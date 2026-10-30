<?php

/**
 * Field Hospital was only ever serialised (New Worlds 114-116, 1962) before
 * becoming part of Star Surgeon, so it's a story, not a book. The ISFDB
 * import made it a book (ISFDB files it as a novel) alongside the story the
 * old site already had. Merge the book into the story.
 *
 * The import now treats such novels as stories (book_publication in
 * isfdb_books.yml and isfdb_stories.yml), so it won't come back.
 *
 * Run with: drush php:script scripts/fix_field_hospital.php
 */

use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;

const STORY = 264;
const BOOK = 626;
const ISFDB_TITLE = 541865;

$story = Node::load(STORY);
$book = Node::load(BOOK);
$log = [];
if ($story?->bundle() !== 'short_story' || ($book && ($book->bundle() !== 'novel' || $story->label() !== $book->label()))) {
  throw new RuntimeException('Unexpected nodes; check the IDs.');
}
if ($book) {
  // "Field Hospital (Part 1 of 3)" and so on are the serial's part titles,
  // which the New Worlds issues already record, not other titles of the story.
  $book->set('field_alternative_titles', []);
  $log = \Drupal::service(\Drupal\sectorgeneral_isfdb\Merger::class)->mergeNodes($story, $book, TRUE);
}

// The merge pointed the books migration's map entry at the story; it belongs
// to the stories migration now.
$migrations = \Drupal::service('plugin.manager.migration');
$books_map = $migrations->createInstance('isfdb_books')->getIdMap();
$books_map->delete(['title_id' => ISFDB_TITLE]);
$stories = $migrations->createInstance('isfdb_stories');
$stories->getIdMap()->saveIdMapping(
  new Row(['title_id' => ISFDB_TITLE], $stories->getSourcePlugin()->getIds()),
  ['nid' => STORY],
  MigrateIdMapInterface::STATUS_IMPORTED,
  MigrateIdMapInterface::ROLLBACK_PRESERVE,
);
$log[] = 'moved the ISFDB title ' . ISFDB_TITLE . ' mapping to isfdb_stories';

echo implode("\n", $log), "\n";
