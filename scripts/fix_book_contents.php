<?php

/**
 * Corrects book contents and duplicated synopsis credits. Safe to re-run.
 *
 * 1. The ISFDB import took a book's contents from the earliest publication
 *    containing it that listed any stories. A novel's own editions list none,
 *    so it got the stories of the first omnibus or collection reprinting it:
 *    Star Surgeon those of Beginning Operations, Star Healer those of Alien
 *    Emergencies, Second Ending those of Monsters and Medics. The import now
 *    reads only a book's own publications (IsfdbTitles); this removes the
 *    stories it added that ISFDB no longer gives the book.
 * 2. An omnibus lists the stories of every book it contains. ISFDB lists a
 *    novel in an omnibus as the novel, not its stories, so Beginning
 *    Operations lacked Star Surgeon's. Missing ones are added, book by book.
 * 3. A synopsis ending with "Synopsis by <credit>" repeats the credit field
 *    shown beneath it; the old-site import now removes it (sg_strip_credit in
 *    scripts/oldsite/import.php), as this does for existing books.
 *
 * Run with: drush php:script scripts/fix_book_contents.php
 */

use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$db = \Drupal::database();
$log = [];
$changed = [];

$ids = fn(NodeInterface $node, string $field) => array_map('intval', array_column($node->get($field)->getValue(), 'target_id'));

// 1. Stories the ISFDB import added that ISFDB no longer gives the book.
$source = \Drupal::service('plugin.manager.migration')->createInstance('isfdb_books')->getSourcePlugin();
foreach ($source->query()->execute() as $values) {
  $row = new Row((array) $values, $source->getIds());
  $source->prepareRow($row);
  $nid = $db->query('SELECT destid1 FROM {migrate_map_isfdb_books} WHERE sourceid1 = :id', [':id' => $row->getSourceProperty('title_id')])->fetchField();
  $book = $nid ? Node::load($nid) : NULL;
  if (!$book) {
    continue;
  }
  // The contents just before the import first filled the book in.
  $fill = $db->query("SELECT MIN(vid) FROM {node_revision} WHERE nid = :nid AND revision_log LIKE 'Filled in from ISFDB%'", [':nid' => $nid])->fetchField();
  $before = $fill ? $db->query('SELECT MAX(vid) FROM {node_revision} WHERE nid = :nid AND vid < :fill', [':nid' => $nid, ':fill' => $fill])->fetchField() : NULL;
  if (!$before) {
    continue;
  }
  $original = $ids($storage->loadRevision($before), 'field_contents');
  // What ISFDB gives the book now, as site stories.
  $from_isfdb = [];
  foreach ($row->getSourceProperty('story_ids') as $title_id) {
    if ($story = $db->query('SELECT destid1 FROM {migrate_map_isfdb_stories} WHERE sourceid1 = :id', [':id' => $title_id])->fetchField()) {
      $from_isfdb[] = (int) $story;
    }
  }
  // An omnibus's stories from the books it contains are its own (step 2).
  $contained = [];
  foreach ($book->get('field_contained_books')->referencedEntities() as $part) {
    array_push($contained, ...$ids($part, 'field_contents'));
  }
  $current = $ids($book, 'field_contents');
  $keep = array_values(array_filter($current, fn($id) => in_array($id, [...$original, ...$from_isfdb, ...$contained], TRUE)));
  if ($keep !== $current) {
    $book->set('field_contents', $keep);
    $changed[$nid] = $book;
    $log[] = sprintf('%s: removed %d stories from other books (%d left)', $book->label(), count($current) - count($keep), count($keep));
  }
}

// 2. Omnibuses: add the stories of the books they contain.
foreach ($storage->loadByProperties(['type' => 'novel', 'field_book_type' => 'omnibus']) as $omnibus) {
  $omnibus = $changed[$omnibus->id()] ?? $omnibus;
  $contents = $ids($omnibus, 'field_contents');
  $wanted = [];
  foreach ($omnibus->get('field_contained_books')->referencedEntities() as $book) {
    $book = $changed[$book->id()] ?? $book;
    array_push($wanted, ...$ids($book, 'field_contents'));
  }
  // Book by book, then any the omnibus lists that none of its books do.
  $all = array_values(array_unique([...$wanted, ...$contents]));
  if (array_diff($all, $contents)) {
    $omnibus->set('field_contents', $all);
    $changed[$omnibus->id()] = $omnibus;
    $log[] = sprintf('%s: added %d stories from the books it contains', $omnibus->label(), count(array_diff($all, $contents)));
  }
}

// 3. "Synopsis by …" at the end of a synopsis that has the credit field.
foreach ($storage->loadByProperties(['type' => ['novel', 'short_story']]) as $node) {
  $node = $changed[$node->id()] ?? $node;
  $credit = trim((string) $node->get('field_synopsis_credit')->value);
  if ($credit === '' || $node->get('body')->isEmpty()) {
    continue;
  }
  $pattern = '#\s*<p>\s*(?:<em>)?\s*Synopsis by\s+' . preg_quote(htmlspecialchars($credit, ENT_QUOTES), '#') . '\.?\s*(?:</em>)?\s*</p>\s*$#i';
  $body = (string) $node->get('body')->value;
  $stripped = preg_replace($pattern, '', $body);
  if ($stripped !== $body) {
    $node->get('body')->value = $stripped;
    $changed[$node->id()] = $node;
    $log[] = "{$node->label()}: removed the repeated synopsis credit from the text";
  }
}

foreach ($changed as $node) {
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Corrected contents and synopsis credit (scripts/fix_book_contents.php).');
  $node->save();
}
echo $log ? implode("\n", $log) . "\n" : "Nothing to correct.\n";
