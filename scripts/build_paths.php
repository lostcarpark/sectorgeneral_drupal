<?php

/**
 * URL patterns, alias regeneration and redirects from the old static site.
 * Safe to re-run.
 *
 * Run with: drush php:script scripts/build_paths.php
 */

use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\pathauto\PathautoState;
use Drupal\redirect\Entity\Redirect;

$patterns = [
  // Nested paths give a useful breadcrumb: Home › Books › Hospital Station.
  'book' => ['Books', 'node', 'novel', 'books/[node:title]'],
  'edition' => ['Editions', 'node', 'edition', 'books/[node:field_novel:0:entity:title]/[node:title]'],
  'short_story' => ['Short stories', 'node', 'short_story', 'short-stories/[node:title]'],
  'magazine_issue' => ['Magazine issues', 'node', 'magazine_issue', 'magazines/[node:field_magazine:entity:name]/[node:title]'],
  'anthology' => ['Anthologies', 'node', 'anthology', 'anthologies/[node:title]'],
  'people' => ['People', 'taxonomy_term', 'people', 'people/[term:name]'],
  'series' => ['Series', 'taxonomy_term', 'series', 'series/[term:name]'],
  'magazine' => ['Magazines', 'taxonomy_term', 'magazine', 'magazines/[term:name]'],
  'publisher' => ['Publishers', 'taxonomy_term', 'publisher', 'publishers/[term:name]'],
  'publisher_series' => ['Publisher series', 'taxonomy_term', 'publisher_series', 'publisher-series/[term:name]'],
  'language' => ['Languages', 'taxonomy_term', 'language', 'languages/[term:name]'],
  'format' => ['Formats', 'taxonomy_term', 'format', 'formats/[term:name]'],
];
$weight = 0;
foreach ($patterns as $id => [$label, $entity_type, $bundle, $pattern]) {
  if ($existing = PathautoPattern::load($id)) {
    $existing->delete();
  }
  $context = $entity_type === 'node' ? 'node' : 'taxonomy_term';
  $p = PathautoPattern::create([
    'id' => $id,
    'label' => $label,
    'type' => $entity_type === 'node' ? 'canonical_entities:node' : 'canonical_entities:taxonomy_term',
    'pattern' => $pattern,
    'weight' => $weight++,
  ]);
  $p->addSelectionCondition([
    'id' => 'entity_bundle:' . $entity_type,
    'bundles' => [$bundle => $bundle],
    'negate' => FALSE,
    'context_mapping' => [$context => $context],
  ]);
  $p->save();
}
echo "patterns saved\n";

// Hand-made aliases from the earlier site are treated as manual by pathauto;
// switch every entity to automatic so the patterns apply. The redirect module
// turns each replaced alias into a 301.
$etm = \Drupal::entityTypeManager();
foreach (['node', 'taxonomy_term'] as $entity_type) {
  foreach ($etm->getStorage($entity_type)->loadMultiple() as $entity) {
    if (!$entity->hasField('path')) {
      continue;
    }
    $before = $entity->get('path')->alias;
    $entity->get('path')->pathauto = PathautoState::CREATE;
    \Drupal::service('pathauto.generator')->updateEntityAlias($entity, 'update');
    $after = \Drupal::service('path_alias.manager')->getAliasByPath('/' . $entity->toUrl()->getInternalPath());
    if ($before !== $after) {
      echo "$entity_type {$entity->id()}: " . ($before ?: '(none)') . " -> $after\n";
    }
  }
}

// Old site URLs that already have a home in Drupal. The content import adds
// the rest as each book and story is created.
$old = [
  'index.html' => 'internal:/',
  'books.html' => 'internal:/books',
  'shortstories.html' => 'internal:/short-stories',
  'books/hospitalstation.html' => 'entity:node/1',
  'books/starsurgeon.html' => 'entity:node/5',
  'books/ambulanceship.html' => 'entity:node/15',
];
$redirects = $etm->getStorage('redirect');
foreach ($old as $source => $target) {
  if (!$redirects->loadByProperties(['redirect_source__path' => $source])) {
    Redirect::create(['redirect_source' => $source, 'redirect_redirect' => $target, 'status_code' => 301, 'language' => 'und'])->save();
    echo "redirect /$source -> $target\n";
  }
}
