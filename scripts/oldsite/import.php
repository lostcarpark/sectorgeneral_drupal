<?php

/**
 * Imports the old static site's bibliography into Drupal.
 *
 * Run with:
 *   drush php:script scripts/oldsite/import.php -- <import.json> <old site dir>
 *
 * Re-runnable: created entities are remembered in state
 * (sectorgeneral.oldsite_import), so a second run finds them instead of
 * creating duplicates. Existing values are never overwritten; empty fields
 * are filled.
 */

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\taxonomy\Entity\Term;

[$json_path, $old_root] = array_slice($extra ?? $_SERVER['argv'], -2);
$data = json_decode(file_get_contents($json_path), TRUE);
$old_root = rtrim($old_root, '/');

$etm = \Drupal::entityTypeManager();
$nodes = $etm->getStorage('node');
$state = \Drupal::state();
$map = $state->get('sectorgeneral.oldsite_import', []);
$log = [];
const SG_SOURCE = 'James White Information (original site)';
const SG_LOG = 'Imported from the original James White Information site.';

// ---------------------------------------------------------------------------
// Structure additions needed by the old content.

$storage = FieldStorageConfig::loadByName('node', 'field_book_type');
$allowed = $storage->getSetting('allowed_values');
if (!isset($allowed['chapbook'])) {
  $allowed['chapbook'] = 'Chapbook';
  $storage->setSetting('allowed_values', $allowed)->save();
}
if (!FieldConfig::loadByName('node', 'short_story', 'field_publication_notes')) {
  if (!FieldStorageConfig::loadByName('node', 'field_publication_notes')) {
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_publication_notes', 'type' => 'text_long'])->save();
  }
  FieldConfig::create([
    'entity_type' => 'node',
    'bundle' => 'short_story',
    'field_name' => 'field_publication_notes',
    'label' => 'Publication notes',
    'description' => 'Other appearances: anthologies by other editors, translations, broadcasts.',
  ])->save();
  $repo = \Drupal::service('entity_display.repository');
  $form = $repo->getFormDisplay('node', 'short_story');
  $form->setComponent('field_publication_notes', ['type' => 'text_textarea', 'weight' => 6, 'settings' => ['rows' => 4]]);
  $form->save();
  $view = $repo->getViewDisplay('node', 'short_story');
  $view->setComponent('field_publication_notes', ['type' => 'text_default', 'label' => 'above', 'weight' => 20]);
  $view->save();
}

// ---------------------------------------------------------------------------
// Helpers.

function sg_term(string $vid, ?string $name): ?int {
  if ($name === NULL || $name === '') {
    return NULL;
  }
  static $cache = [];
  if (isset($cache[$vid][$name])) {
    return $cache[$vid][$name];
  }
  $found = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
  $term = $found ? reset($found) : Term::create(['vid' => $vid, 'name' => $name]);
  if ($term->isNew()) {
    $term->save();
  }
  return $cache[$vid][$name] = (int) $term->id();
}

function sg_paragraphs(array $paragraphs): string {
  return implode("\n", array_map(fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES) . '</p>', $paragraphs));
}

/**
 * Removes a closing "Synopsis by <credit>" paragraph from a body.
 *
 * The credit has its own field, shown beneath the synopsis, so in the text
 * as well it would appear twice. Some bodies on the earlier Drupal site end
 * with one.
 */
function sg_strip_credit(Node $node): void {
  $credit = trim((string) $node->get('field_synopsis_credit')->value);
  $body = $node->get('body');
  if ($credit === '' || $body->isEmpty()) {
    return;
  }
  $pattern = '#\s*<p>\s*(?:<em>)?\s*Synopsis by\s+' . preg_quote(htmlspecialchars($credit, ENT_QUOTES), '#') . '\.?\s*(?:</em>)?\s*</p>\s*$#i';
  $value = preg_replace($pattern, '', (string) $body->value);
  if ($value !== $body->value) {
    $body->value = $value;
  }
}

function sg_isbn13(?string $raw): ?string {
  $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $raw));
  // A nine-digit SBN is an ISBN-10 without its leading zero.
  if (strlen($digits) === 9) {
    $digits = '0' . $digits;
  }
  if (strlen($digits) === 13 && ctype_digit($digits)) {
    $sum = 0;
    foreach (str_split($digits) as $i => $c) {
      $sum += ($i % 2 ? 3 : 1) * (int) $c;
    }
    return $sum % 10 === 0 ? $digits : NULL;
  }
  if (strlen($digits) === 10) {
    $sum = 0;
    foreach (str_split($digits) as $i => $c) {
      $sum += (10 - $i) * ($c === 'X' ? 10 : (int) $c);
    }
    if ($sum % 11 !== 0) {
      return NULL;
    }
    $core = '978' . substr($digits, 0, 9);
    $sum = 0;
    foreach (str_split($core) as $i => $c) {
      $sum += ($i % 2 ? 3 : 1) * (int) $c;
    }
    return $core . ((10 - $sum % 10) % 10);
  }
  return NULL;
}

/**
 * Sets a field only when it is empty, so existing data wins.
 */
function sg_fill(FieldableEntityInterface $node, string $field, $value): void {
  if ($value === NULL || $value === '' || $value === []) {
    return;
  }
  if ($node->hasField($field) && $node->get($field)->isEmpty()) {
    $node->set($field, $value);
  }
}

/**
 * Creates (once) a cover image media item from an old-site file.
 */
function sg_media(array $image, string $name, string $old_root, array &$map): int {
  $key = 'media:' . $image['path'];
  if (isset($map[$key]) && Media::load($map[$key])) {
    return $map[$key];
  }
  $fs = \Drupal::service('file_system');
  $dir = 'public://covers/old-site';
  $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $basename = preg_replace('/[^a-z0-9._-]+/', '-', strtolower(basename($image['path'])));
  $uri = $fs->copy($old_root . '/' . $image['path'], "$dir/$basename", \Drupal\Core\File\FileExists::Rename);
  $file = File::create(['uri' => $uri, 'status' => 1, 'uid' => 1]);
  $file->save();
  $media = Media::create([
    'bundle' => 'cover_image',
    'name' => $name,
    'uid' => 1,
    'field_media_image' => ['target_id' => $file->id(), 'alt' => $image['alt'], 'width' => $image['width'], 'height' => $image['height']],
    'field_image_source' => sg_term('image_source', $image['source']),
  ]);
  $media->save();
  $map[$key] = (int) $media->id();
  \Drupal::state()->set('sectorgeneral.oldsite_import', $map);
  return $map[$key];
}

/**
 * Loads a node created by an earlier run, or creates a new one.
 */
function sg_node(string $key, string $bundle, array &$map): NodeInterface {
  if (isset($map[$key]) && ($node = Node::load($map[$key]))) {
    return $node;
  }
  return Node::create(['type' => $bundle, 'uid' => 1, 'status' => 1]);
}

function sg_save(NodeInterface $node, string $key, array &$map): int {
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage(SG_LOG);
  $node->save();
  $map[$key] = (int) $node->id();
  // Saved as we go, so an interrupted run never leads to duplicates.
  \Drupal::state()->set('sectorgeneral.oldsite_import', $map);
  return $map[$key];
}

function sg_redirect(string $source, int $nid): void {
  $storage = \Drupal::entityTypeManager()->getStorage('redirect');
  if (!$storage->loadByProperties(['redirect_source__path' => $source])) {
    Redirect::create(['redirect_source' => $source, 'redirect_redirect' => "entity:node/$nid", 'status_code' => 301, 'language' => 'und'])->save();
  }
}

// ---------------------------------------------------------------------------
// Books.

$book_nid = [];
foreach ($data['books'] as $b) {
  $key = 'book:' . $b['file'];
  $node = $b['existing_nid'] ? Node::load($b['existing_nid']) : sg_node($key, 'novel', $map);
  $is_new = $node->isNew();
  if ($is_new) {
    $node->setTitle($b['title']);
    // Set, not filled: the field's default makes it non-empty on creation.
    $node->set('field_book_type', $b['type']);
    $node->set('body', ['value' => sg_paragraphs($b['synopsis']), 'summary' => $b['summary'], 'format' => 'basic_html']);
  }
  elseif ($node->get('body')->isEmpty()) {
    $node->set('body', ['value' => sg_paragraphs($b['synopsis']), 'summary' => $b['summary'], 'format' => 'basic_html']);
  }
  elseif (!$node->get('body')->summary && $b['summary']) {
    $node->get('body')->summary = $b['summary'];
  }
  sg_fill($node, 'field_book_type', $b['type']);
  sg_fill($node, 'field_first_published_year', $b['year']);
  sg_fill($node, 'field_words', $b['words']);
  sg_fill($node, 'field_synopsis_credit', $b['synopsis_credit']);
  sg_strip_credit($node);
  sg_fill($node, 'field_alternative_titles', $b['alt_titles']);
  sg_fill($node, 'field_editor', array_map(fn($n) => sg_term('people', $n), $b['editors']));
  if ($b['series']) {
    sg_fill($node, 'field_series', sg_term('series', $b['series'][0]));
    sg_fill($node, 'field_series_number', $b['series'][1]);
  }
  sg_fill($node, 'field_data_sources', SG_SOURCE . ($b['synopsis_credit'] ? '. Synopsis by ' . $b['synopsis_credit'] . '.' : '.'));
  $book_nid[$b['file']] = sg_save($node, $key, $map);
  sg_redirect('books/' . $b['file'], $book_nid[$b['file']]);
  $log[] = ($is_new ? 'created' : 'updated') . " book {$b['title']}";
}

// Anthologies by other editors are books too.
foreach ($data['anthologies'] as $a) {
  $key = 'anthology:' . $a['title'];
  $node = sg_node($key, 'anthology', $map);
  $node->setTitle($a['title']);
  sg_fill($node, 'field_first_published_year', $a['year']);
  sg_fill($node, 'field_editor', array_map(fn($n) => sg_term('people', $n), $a['editors']));
  sg_fill($node, 'field_publisher', sg_term('publisher', $a['publisher'] ?? NULL));
  if (preg_match('/^(New Writings in SF) (\d+)$/', $a['title'], $m)) {
    sg_fill($node, 'field_series', sg_term('series', $m[1]));
    sg_fill($node, 'field_series_number', (int) $m[2]);
  }
  if (!empty($a['image'])) {
    sg_fill($node, 'field_cover', sg_media($a['image'], "{$a['title']} cover", $old_root, $map));
  }
  sg_fill($node, 'field_data_sources', SG_SOURCE . '.');
  $book_nid['anthology:' . $a['title']] = sg_save($node, $key, $map);
}

// ---------------------------------------------------------------------------
// Editions.

/**
 * Finds an existing edition of the book that this old-site line describes.
 */
function sg_match_edition(array $ed, int $book, array $existing): ?NodeInterface {
  $same = fn($a, $b) => $a !== NULL && $a === $b;
  $isbn = $ed['isbn'];
  $lang = $ed['language'] ? sg_term('language', $ed['language']) : NULL;
  $pub = $ed['publisher'] ? sg_term('publisher', $ed['publisher']) : NULL;
  $info = [];
  foreach ($existing as $node) {
    $info[] = [
      'node' => $node,
      'isbn' => sg_isbn13($node->get('field_isbn')->value),
      'year' => (int) $node->get('field_year')->value ?: NULL,
      'lang' => (int) $node->get('field_language')->target_id ?: NULL,
      'pub' => (int) $node->get('field_publisher')->target_id ?: NULL,
    ];
  }
  if ($isbn) {
    foreach ($info as $i) {
      if ($same($i['isbn'], $isbn)) {
        return $i['node'];
      }
    }
  }
  if ($ed['year']) {
    // An existing edition with no year can still match on publisher.
    $candidates = array_filter($info, fn($i) => ($i['year'] === $ed['year'] || ($i['year'] === NULL && $i['pub'] === $pub)) && $i['lang'] === $lang);
    if (count($candidates) > 1) {
      $candidates = array_filter($candidates, fn($i) => $i['pub'] === $pub);
    }
    if (count($candidates) === 1) {
      return reset($candidates)['node'];
    }
  }
  else {
    $candidates = array_filter($info, fn($i) => $i['pub'] === $pub && $i['lang'] === $lang);
    if (count($candidates) === 1) {
      return reset($candidates)['node'];
    }
  }
  return NULL;
}

$edition_nid = [];
$matched = [];
foreach ($data['editions'] as $i => $ed) {
  $book = $book_nid[$ed['book']];
  $key = 'edition:' . $ed['book'] . ':' . md5($ed['raw']);
  $node = NULL;
  if (!isset($map[$key]) && $ed['book'] !== 'beginningoperations.html') {
    $existing = $nodes->loadByProperties(['type' => 'edition', 'field_novel' => $book]);
    // Only node keys: the map also holds media IDs, which can collide.
    $imported = array_values(array_filter($map, fn($k) => !str_starts_with($k, 'media:'), ARRAY_FILTER_USE_KEY));
    $existing = array_filter($existing, fn($n) => !in_array((int) $n->id(), $imported, TRUE) && !in_array((int) $n->id(), $matched, TRUE));
    $node = sg_match_edition($ed, $book, $existing);
    if ($node) {
      $matched[] = (int) $node->id();
      $log[] = "matched existing edition {$node->id()} \"{$node->label()}\" to \"{$ed['raw']}\"";
    }
  }
  // Beginning Operations was entered as an edition of Hospital Station; it
  // is an omnibus, so its edition moves to the new book.
  if ($ed['book'] === 'beginningoperations.html' && !isset($map[$key])) {
    $node = Node::load(14);
    if ($node && $node->bundle() === 'edition') {
      $node->set('field_novel', [$book]);
      $matched[] = 14;
      $log[] = 'moved edition 14 (Beginning Operations) from Hospital Station to the new omnibus book';
    }
  }
  $node ??= sg_node($key, 'edition', $map);
  $is_new = $node->isNew();
  if ($is_new) {
    $node->setTitle($ed['label']);
    $node->set('field_novel', array_map(fn($f) => $book_nid[$f], $ed['books']));
  }
  sg_fill($node, 'field_language', sg_term('language', $ed['language']));
  sg_fill($node, 'field_publisher', sg_term('publisher', $ed['publisher']));
  sg_fill($node, 'field_publisher_series', sg_term('publisher_series', $ed['publisher_series']));
  sg_fill($node, 'field_format', sg_term('format', $ed['format']));
  sg_fill($node, 'field_isbn', $ed['isbn']);
  sg_fill($node, 'field_publisher_reference', $ed['catalogue']);
  sg_fill($node, 'field_price', $ed['price']);
  sg_fill($node, 'field_printing', $ed['printing']);
  sg_fill($node, 'field_year', $ed['year']);
  sg_fill($node, 'field_date_display', $ed['date_display']);
  sg_fill($node, 'field_pages', $ed['pages']);
  $source = SG_SOURCE . ': “' . $ed['raw'] . '”';
  $sources = (string) $node->get('field_data_sources')->value;
  if (!str_contains($sources, $ed['raw'])) {
    $node->set('field_data_sources', trim($sources . "\n" . $source));
  }
  if (!empty($ed['image'])) {
    $current = $node->get('field_cover')->entity;
    $width = $current ? (int) $current->get('field_media_image')->width : 0;
    if (!$current) {
      $node->set('field_cover', sg_media($ed['image'], $node->label() . ' cover', $old_root, $map));
    }
    elseif ($width < $ed['image']['width']) {
      // A larger scan of the same cover: swap the file, keep the media item.
      $bigger = Media::load(sg_media($ed['image'], $node->label() . ' cover', $old_root, $map));
      $current->set('field_media_image', $bigger->get('field_media_image')->getValue());
      sg_fill($current, 'field_image_source', sg_term('image_source', SG_SOURCE));
      $current->setNewRevision(TRUE);
      $current->save();
      $bigger->delete();
      unset($map['media:' . $ed['image']['path']]);
      $log[] = "replaced the cover of \"{$node->label()}\" with a larger scan ({$ed['image']['width']}px, was {$width}px)";
    }
  }
  $edition_nid[$i] = sg_save($node, $key, $map);
}

// Tidy ISBNs on editions entered before the import: 13 digits, no hyphens.
foreach ($nodes->loadByProperties(['type' => 'edition']) as $node) {
  $raw = $node->get('field_isbn')->value;
  if ($raw && ($isbn = sg_isbn13($raw)) && $isbn !== $raw) {
    $node->set('field_isbn', $isbn);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Standardised ISBN to 13 digits.');
    $node->save();
    $log[] = "standardised ISBN on edition {$node->id()}: $raw → $isbn";
  }
}

// Old "buy this book" links go on the edition with that ASIN/ISBN.
foreach ($data['books'] as $b) {
  foreach ($b['buy'] as $link) {
    if (preg_match('#/ASIN/([0-9X]{10})/#', $link['url'], $m) && ($isbn = sg_isbn13($m[1]))) {
      foreach ($nodes->loadByProperties(['type' => 'edition', 'field_isbn' => $isbn]) as $node) {
        $urls = array_column($node->get('field_buy_links')->getValue(), 'uri');
        if (!in_array($link['url'], $urls)) {
          $node->get('field_buy_links')->appendItem(['uri' => $link['url'], 'title' => $link['title']]);
          $node->save();
        }
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Stories, magazine issues, and contents lists.

$story_nid = [];
foreach ($data['stories'] as $s) {
  $key = 'story:' . $s['key'];
  $node = sg_node($key, 'short_story', $map);
  if ($node->isNew()) {
    $node->setTitle($s['title']);
  }
  if ($node->get('body')->isEmpty() && $s['synopsis']) {
    $node->set('body', ['value' => sg_paragraphs($s['synopsis']), 'format' => 'basic_html']);
  }
  sg_fill($node, 'field_opening_excerpt', $s['opening'] ? ['value' => sg_paragraphs([$s['opening']]), 'format' => 'basic_html'] : NULL);
  sg_fill($node, 'field_publication_notes', $s['publication_notes'] ? ['value' => sg_paragraphs(explode("\n", $s['publication_notes'])), 'format' => 'basic_html'] : NULL);
  sg_fill($node, 'field_words', $s['words']);
  sg_fill($node, 'field_first_published_year', $s['year']);
  sg_fill($node, 'field_synopsis_credit', $s['synopsis_credit']);
  sg_fill($node, 'field_alternative_titles', $s['alt_titles']);
  sg_fill($node, 'field_series', $s['series'] ? sg_term('series', $s['series']) : NULL);
  sg_fill($node, 'field_data_sources', SG_SOURCE . ($s['synopsis_credit'] ? '. Synopsis by ' . $s['synopsis_credit'] . '.' : '.'));
  $story_nid[$s['key']] = sg_save($node, $key, $map);
  if ($s['file']) {
    sg_redirect('shortstories/' . $s['file'], $story_nid[$s['key']]);
  }
}

foreach ($data['issues'] as $issue) {
  $key = 'issue:' . $issue['key'];
  $node = sg_node($key, 'magazine_issue', $map);
  if ($node->isNew()) {
    $node->setTitle($issue['title']);
  }
  sg_fill($node, 'field_magazine', sg_term('magazine', $issue['magazine']));
  sg_fill($node, 'field_issue_number', $issue['issue']);
  sg_fill($node, 'field_year', $issue['year']);
  sg_fill($node, 'field_date_display', $issue['date']);
  sg_fill($node, 'field_contents', array_map(fn($k) => $story_nid[$k], $issue['contents']));
  if (!empty($issue['image'])) {
    sg_fill($node, 'field_cover', sg_media($issue['image'], "{$issue['title']} cover", $old_root, $map));
  }
  sg_save($node, $key, $map);
}

foreach ($data['books'] as $b) {
  $node = Node::load($book_nid[$b['file']]);
  sg_fill($node, 'field_contents', array_map(fn($k) => $story_nid[$k], $b['contents']));
  sg_fill($node, 'field_contained_books', array_map(fn($f) => $book_nid[$f], $b['contains']));
  // The book's own cover: its earliest English edition that has one.
  if ($node->get('field_cover')->isEmpty()) {
    $editions = $nodes->loadByProperties(['type' => 'edition', 'field_novel' => $node->id()]);
    $english = sg_term('language', 'English');
    $editions = array_filter($editions, fn($e) => !$e->get('field_cover')->isEmpty() && (int) $e->get('field_language')->target_id === $english);
    usort($editions, fn($x, $y) => ((int) $x->get('field_year')->value ?: 9999) <=> ((int) $y->get('field_year')->value ?: 9999));
    if ($editions) {
      $node->set('field_cover', reset($editions)->get('field_cover')->target_id);
    }
  }
  $node->save();
}
foreach ($data['anthologies'] as $a) {
  $node = Node::load($book_nid['anthology:' . $a['title']]);
  sg_fill($node, 'field_contents', array_map(fn($k) => $story_nid[$k], $a['contents']));
  $node->save();
}

// Covers the old site shows but which match no edition or issue.
foreach ($data['media'] as $image) {
  sg_media($image, basename($image['path'], '.jpg') . ' (old site, unmatched)', $old_root, $map);
}

$state->set('sectorgeneral.oldsite_import', $map);
foreach ($log as $line) {
  echo $line . "\n";
}
echo sprintf("Done: %d books, %d anthologies, %d editions, %d stories, %d issues, %d media.\n",
  count($data['books']), count($data['anthologies']), count($edition_nid), count($story_nid), count($data['issues']),
  count(array_filter(array_keys($map), fn($k) => str_starts_with($k, 'media:'))));
