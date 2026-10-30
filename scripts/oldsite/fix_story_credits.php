<?php

/**
 * One-off corrections after the first import:
 * - story credits that swallowed the "First Publication" text, and the
 *   publication notes that lost it;
 * - "New Writings in SF (1973)" is volume 22, and volume 16 is from 1970.
 *
 * Run with:
 *   drush php:script scripts/oldsite/fix_story_credits.php -- <import.json>
 */

use Drupal\node\Entity\Node;

[$json_path] = array_slice($extra ?? $_SERVER['argv'], -1);
$data = json_decode(file_get_contents($json_path), TRUE);
$state = \Drupal::state();
$map = $state->get('sectorgeneral.oldsite_import', []);
$save = function (Node $node, string $message) {
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage($message);
  $node->save();
};

foreach ($data['stories'] as $s) {
  $node = Node::load($map['story:' . $s['key']] ?? 0);
  if (!$node || !str_contains((string) $node->get('field_synopsis_credit')->value, 'Publication')) {
    continue;
  }
  $node->set('field_synopsis_credit', $s['synopsis_credit']);
  $node->set('field_data_sources', 'James White Information (original site). Synopsis by ' . $s['synopsis_credit'] . '.');
  if ($s['publication_notes']) {
    $paragraphs = array_map(fn($p) => '<p>' . htmlspecialchars($p, ENT_QUOTES) . '</p>', explode("\n", $s['publication_notes']));
    $node->set('field_publication_notes', ['value' => implode("\n", $paragraphs), 'format' => 'basic_html']);
  }
  $save($node, 'Separated the synopsis credit from the publication details.');
  echo "story: {$node->label()}\n";
}

if (isset($map['anthology:New Writings in SF (1973)'])) {
  $node = Node::load($map['anthology:New Writings in SF (1973)']);
  $node->setTitle('New Writings in SF 22');
  $save($node, 'Volume 22, per the old site\'s "Spacebird" page.');
  $map['anthology:New Writings in SF 22'] = (int) $node->id();
  unset($map['anthology:New Writings in SF (1973)']);
  $state->set('sectorgeneral.oldsite_import', $map);
  echo "anthology renamed: New Writings in SF 22\n";
}
if ($node = Node::load($map['anthology:New Writings in SF 16'] ?? 0)) {
  if ((int) $node->get('field_first_published_year')->value === 1969) {
    $node->set('field_first_published_year', 1970);
    $save($node, 'Dobson, January 1970, per the old site\'s "Meatball" page.');
    echo "anthology year: New Writings in SF 16 → 1970\n";
  }
}
