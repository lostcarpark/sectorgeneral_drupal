<?php

/**
 * Sets each imported book's type from the import data.
 *
 * The first import left every book as "Novel": the field's default value is
 * applied on creation, so the importer's fill-if-empty never set it.
 *
 * Run with:
 *   drush php:script scripts/oldsite/fix_book_types.php -- <import.json>
 */

use Drupal\node\Entity\Node;

[$json_path] = array_slice($extra ?? $_SERVER['argv'], -1);
$data = json_decode(file_get_contents($json_path), TRUE);
$map = \Drupal::state()->get('sectorgeneral.oldsite_import', []);

$types = [];
foreach ($data['books'] as $b) {
  $types[$b['existing_nid'] ?: ($map['book:' . $b['file']] ?? NULL)] = $b['type'];
}
foreach ($data['anthologies'] as $a) {
  $types[$map['anthology:' . $a['title']] ?? NULL] = 'anthology';
}
unset($types['']);

foreach ($types as $nid => $type) {
  $node = Node::load($nid);
  if ($node && $node->bundle() === 'novel' && $node->get('field_book_type')->value !== $type) {
    $node->set('field_book_type', $type);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage("Book type set to $type from the old-site import data.");
    $node->save();
    echo "{$node->label()}: $type\n";
  }
}
