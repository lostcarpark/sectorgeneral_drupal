<?php

/**
 * Moves anthologies from Book nodes to the Anthology content type, and marks
 * novels built from separately published stories as fix-up novels.
 *
 * Run once, after build_structure.php has created the Anthology type:
 *   drush php:script scripts/oldsite/move_anthologies.php
 */

use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliases = \Drupal::service('path_alias.manager');
$state = \Drupal::state();
$map = $state->get('sectorgeneral.oldsite_import', []);

$series_tid = function (string $name) use ($terms): int {
  $found = $terms->loadByProperties(['vid' => 'series', 'name' => $name]);
  $term = $found ? reset($found) : $terms->create(['vid' => 'series', 'name' => $name]);
  $term->isNew() && $term->save();
  return (int) $term->id();
};

$copy = ['body', 'field_cover', 'field_contents', 'field_editor', 'field_first_published_year', 'field_isfdb_id', 'field_data_sources'];
$anthology_nids = array_filter($map, fn($k) => str_starts_with($k, 'anthology:'), ARRAY_FILTER_USE_KEY);
foreach ($nodes->loadMultiple($anthology_nids) as $old) {
  if ($old->bundle() !== 'novel') {
    continue;
  }
  $new = Node::create([
    'type' => 'anthology',
    'title' => $old->label(),
    'uid' => $old->getOwnerId(),
    'status' => $old->isPublished(),
    'created' => $old->getCreatedTime(),
  ]);
  foreach ($copy as $field) {
    $new->set($field, $old->get($field)->getValue());
  }
  // "New Writings in SF 18" belongs to a numbered series.
  if (preg_match('/^(New Writings in SF) (\d+)$/', $old->label(), $m)) {
    $new->set('field_series', $series_tid($m[1]));
    $new->set('field_series_number', (int) $m[2]);
  }
  $new->setRevisionLogMessage('Moved from Book to Anthology.');
  $new->save();

  $old_alias = $aliases->getAliasByPath('/node/' . $old->id());
  foreach ($map as $key => $nid) {
    if (!str_starts_with($key, 'media:') && (int) $nid === (int) $old->id()) {
      $map[$key] = (int) $new->id();
    }
  }
  $old->delete();
  if ($old_alias !== '/node/' . $old->id()) {
    Redirect::create(['redirect_source' => ltrim($old_alias, '/'), 'redirect_redirect' => 'entity:node/' . $new->id(), 'status_code' => 301, 'language' => 'und'])->save();
  }
  $new_alias = $aliases->getAliasByPath('/node/' . $new->id());
  echo "anthology {$new->id()}: {$new->label()} ($old_alias → $new_alias)\n";
}
$state->set('sectorgeneral.oldsite_import', $map);

// Novels with a contents list were built from separately published stories.
foreach ($nodes->loadByProperties(['type' => 'novel', 'field_book_type' => 'novel']) as $book) {
  if (!$book->get('field_contents')->isEmpty()) {
    $book->set('field_book_type', 'fixup');
    $book->setNewRevision(TRUE);
    $book->setRevisionLogMessage('Marked as a fix-up novel: built from separately published stories.');
    $book->save();
    echo "fix-up novel: {$book->label()} (" . $book->get('field_contents')->count() . " stories)\n";
  }
}
