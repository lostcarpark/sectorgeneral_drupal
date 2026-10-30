<?php

/**
 * Moves existing node data into the new structure. Safe to re-run.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

$nodes = \Drupal::entityTypeManager()->getStorage('node');
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

// Covers: editions first so a book whose cover duplicates an edition's
// reuses that edition's media item (and so gets its credit).
$by_hash = [];
$ids = array_merge(
  $nodes->getQuery()->accessCheck(FALSE)->condition('type', 'edition')->sort('nid')->execute(),
  $nodes->getQuery()->accessCheck(FALSE)->condition('type', 'novel')->sort('nid')->execute(),
);
foreach ($nodes->loadMultiple($ids) as $node) {
  if (!$node->hasField('field_cover_image') || $node->get('field_cover_image')->isEmpty() || !$node->get('field_cover')->isEmpty()) {
    continue;
  }
  $item = $node->get('field_cover_image')->first();
  $file = $item->entity;
  $hash = md5_file($file->getFileUri());
  if (!isset($by_hash[$hash])) {
    $media = Media::create([
      'bundle' => 'cover_image',
      'name' => $node->label() . ' cover',
      'uid' => $node->getOwnerId(),
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $item->alt ?: $node->label() . ' cover',
        'width' => $item->width,
        'height' => $item->height,
      ],
    ]);
    $media->save();
    $by_hash[$hash] = $media->id();
    echo "media {$media->id()} from file {$file->id()} for node {$node->id()}\n";
  }
  else {
    echo "node {$node->id()} reuses media {$by_hash[$hash]}\n";
  }
  $node->set('field_cover', $by_hash[$hash]);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Moved cover image into the media library.');
  $node->save();
}

// Edition dates: split into a sortable year and a human-readable date.
foreach ($nodes->loadByProperties(['type' => 'edition']) as $node) {
  if (!$node->get('field_year')->isEmpty() || $node->get('field_publication_date')->isEmpty()) {
    continue;
  }
  $date = $node->get('field_publication_date')->date;
  [$y, $m, $d] = array_map('intval', explode('-', $date->format('Y-m-d')));
  $display = match (TRUE) {
    $m === 1 && $d === 1 => (string) $y,
    $d === 1 => $date->format('F Y'),
    default => $date->format('j F Y'),
  };
  // Only keep the exact date where a real day was recorded.
  if ($d === 1) {
    $node->set('field_publication_date', NULL);
  }
  $node->set('field_year', $y);
  $node->set('field_date_display', $display);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Split publication date into year and date as shown.');
  $node->save();
  echo "edition {$node->id()}: $y / $display\n";
}

// Typos in existing terms and titles.
foreach (['Del Ray' => 'Del Rey', 'Portugese' => 'Portuguese'] as $old => $new) {
  foreach ($terms->loadByProperties(['name' => $old]) as $term) {
    $term->setName($new)->save();
    echo "term renamed: $old -> $new\n";
  }
}
foreach ($nodes->loadMultiple() as $node) {
  if (str_contains($node->label(), 'Del Ray')) {
    $node->setTitle(str_replace('Del Ray', 'Del Rey', $node->label()));
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Corrected publisher name: Del Rey.');
    $node->save();
    echo "title fixed: {$node->label()}\n";
  }
}

// Books: type, series and first-publication year.
$series = current($terms->loadByProperties(['vid' => 'series', 'name' => 'Sector General']));
$books = [
  'Hospital Station' => [1, 1962],
  'Star Surgeon' => [2, 1963],
  'Ambulance Ship' => [4, 1979],
];
foreach ($nodes->loadByProperties(['type' => 'novel']) as $node) {
  $changed = FALSE;
  if ($node->get('field_book_type')->isEmpty()) {
    $node->set('field_book_type', 'novel');
    $changed = TRUE;
  }
  if (isset($books[$node->label()]) && $node->get('field_series')->isEmpty()) {
    [$number, $year] = $books[$node->label()];
    $node->set('field_series', $series->id());
    $node->set('field_series_number', $number);
    $node->set('field_first_published_year', $year);
    $changed = TRUE;
  }
  if ($changed) {
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Added book type and series.');
    $node->save();
    echo "book {$node->id()} {$node->label()} updated\n";
  }
}

// Retire the old image fields once every cover has a media item.
$missing = 0;
foreach ($nodes->loadMultiple() as $node) {
  if ($node->hasField('field_cover_image') && !$node->get('field_cover_image')->isEmpty() && $node->get('field_cover')->isEmpty()) {
    $missing++;
  }
}
if ($missing) {
  echo "NOT removing field_cover_image: $missing nodes still lack a media cover\n";
}
else {
  foreach (['novel', 'edition'] as $bundle) {
    if ($field = FieldConfig::loadByName('node', $bundle, 'field_cover_image')) {
      $field->delete();
      echo "removed field_cover_image from $bundle\n";
    }
  }
}
