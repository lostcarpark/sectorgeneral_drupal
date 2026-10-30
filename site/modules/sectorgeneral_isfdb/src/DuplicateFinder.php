<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\node\Entity\Node;

/**
 * Finds imported items that may duplicate existing ones, with a confidence
 * level and the reasons, for the duplicates page and Drush commands.
 */
class DuplicateFinder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MigrationPluginManagerInterface $migrationManager,
    protected StateInterface $state,
    protected Merger $merger,
  ) {}

  /**
   * Pairs of existing and imported items that may be the same thing.
   *
   * @return array[]
   *   Each with kind (nodes|terms), section, keep, dup, labels, level
   *   (high|medium|low|printing) and reasons.
   */
  public function find(): array {
    $created = $this->createdNodeIds();
    $storage = $this->entityTypeManager->getStorage('node');
    $year = fn(Node $n) => (int) ($n->hasField('field_year') ? $n->get('field_year')->value : 0) ?: (int) ($n->hasField('field_first_published_year') ? $n->get('field_first_published_year')->value : 0);
    $ids = fn(Node $n, string $field) => array_map('intval', array_column($n->get($field)->getValue(), 'target_id'));
    $label = fn($e, $extra = '') => sprintf('"%s"%s', $e->label(), $extra);
    $title_score = function (string $a, string $b): float {
      similar_text(Isfdb::normalizeTitle($a), Isfdb::normalizeTitle($b), $percent);
      return $percent;
    };

    $pairs = [];
    foreach ($storage->loadMultiple($created) as $new) {
      $bundle = $new->bundle();
      $candidates = [];
      if (in_array($bundle, ['anthology', 'magazine_issue'], TRUE) && ($stories = $ids($new, 'field_contents'))) {
        $candidates = $storage->loadByProperties(['type' => $bundle, 'field_contents' => $stories]);
      }
      if ($bundle === 'magazine_issue' && ($month = Isfdb::month((string) $new->get('field_date_display')->value))) {
        // The same magazine dated the same month: the same issue, however its
        // contents were recorded.
        $candidates += array_filter($storage->loadByProperties(['type' => 'magazine_issue', 'field_magazine' => $new->get('field_magazine')->target_id]),
          fn(Node $i) => $year($i) === $year($new) && Isfdb::month((string) $i->get('field_date_display')->value) === $month
            && (string) $i->get('field_regional_edition')->value === (string) $new->get('field_regional_edition')->value);
      }
      elseif ($bundle === 'edition' && ($books = $ids($new, 'field_novel'))) {
        $language = $new->get('field_language')->target_id;
        $candidates = array_filter($storage->loadByProperties(['type' => 'edition', 'field_novel' => $books]), fn(Node $e) => $e->get('field_language')->target_id == $language);
      }
      elseif (in_array($bundle, ['short_story', 'novel'], TRUE)) {
        $candidates = array_filter($storage->loadByProperties(['type' => $bundle]), fn(Node $n) => $title_score($new->label(), $n->label()) >= 85);
      }
      foreach ($candidates as $old) {
        if (in_array((int) $old->id(), $created, TRUE)) {
          continue;
        }
        $gap = $year($old) && $year($new) ? abs($year($old) - $year($new)) : NULL;
        if ($gap !== NULL && $gap > 1) {
          continue;
        }
        $reasons = [];
        $level = NULL;
        if ($bundle === 'edition') {
          $pa = $old->get('field_publisher')->target_id;
          $pb = $new->get('field_publisher')->target_id;
          $ia = Isfdb::isbn13($old->get('field_isbn')->value);
          $ib = Isfdb::isbn13($new->get('field_isbn')->value);
          if ($ia && $ia === $ib) {
            $level = 'printing';
            $reasons[] = 'same ISBN';
          }
          elseif ($pa && $pb && $pa != $pb) {
            // Different publishers: different editions, not duplicates.
            continue;
          }
          else {
            $reasons[] = $pa && $pb ? 'same publisher' : 'publisher missing on one';
            $reasons[] = $gap === 0 ? 'same year' : 'adjacent year';
            $reasons[] = $ia && $ib ? 'different ISBNs' : 'ISBN missing on one';
            $level = $pa && $pb && $gap === 0 ? 'high' : ($pa && $pb ? 'medium' : 'low');
          }
        }
        elseif ($bundle === 'magazine_issue') {
          $same_magazine = $old->get('field_magazine')->target_id == $new->get('field_magazine')->target_id;
          $same_month = $gap === 0 && ($m = Isfdb::month((string) $old->get('field_date_display')->value)) && $m === Isfdb::month((string) $new->get('field_date_display')->value);
          $same_issue = ($old->get('field_issue_number')->value !== NULL && $old->get('field_issue_number')->value === $new->get('field_issue_number')->value) || ($same_magazine && $same_month);
          $reasons[] = array_intersect($ids($old, 'field_contents'), $ids($new, 'field_contents')) ? 'same story' : 'contents recorded differently';
          $reasons[] = $same_magazine ? 'same magazine' : 'different magazine (perhaps another country\'s edition)';
          $reasons[] = $same_month ? 'same month' : ($same_issue ? 'same issue number' : ($gap === 0 ? 'same year' : 'adjacent year'));
          $level = $same_magazine && $same_issue ? 'high' : ($same_magazine ? 'medium' : 'low');
        }
        else {
          $score = $title_score($old->label(), $new->label());
          $reasons[] = $bundle === 'anthology' ? 'same story' : 'similar title';
          $reasons[] = sprintf('titles %d%% alike', $score);
          $reasons[] = $gap === 0 ? 'same year' : ($gap === NULL ? 'year unknown' : 'adjacent year');
          $level = $score >= 80 && $gap === 0 ? 'high' : ($score >= 60 || $gap === 0 ? 'medium' : 'low');
        }
        $pairs[] = [
          'kind' => 'nodes', 'section' => $bundle, 'keep' => (int) $old->id(), 'dup' => (int) $new->id(),
          'keep_label' => $label($old, $year($old) ? ' (' . $year($old) . ')' : ''),
          'dup_label' => $label($new, $year($new) ? ' (' . $year($new) . ')' : ''),
          'level' => $level, 'reasons' => $reasons,
        ];
      }
    }

    // Terms the import created that look like existing ones.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($this->state->get('sectorgeneral_isfdb.created_terms', []) as $vid => $new_terms) {
      $existing = array_filter($terms->loadByProperties(['vid' => $vid]), fn($t) => !isset($new_terms[$t->id()]));
      foreach ($new_terms as $tid => $name) {
        if (!$terms->load($tid)) {
          continue;
        }
        $a = Isfdb::normalizeName($name, $vid);
        foreach ($existing as $old) {
          $b = Isfdb::normalizeName($old->label(), $vid);
          similar_text($a, $b, $percent);
          $contains = min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a));
          if ($a !== $b && $percent < 88 && !$contains) {
            continue;
          }
          $reasons = [$a === $b ? 'same name once reduced' : sprintf('names %d%% alike', $percent)];
          $reasons[] = sprintf('%d and %d uses', $this->merger->countReferences('taxonomy_term', (int) $old->id()), $this->merger->countReferences('taxonomy_term', (int) $tid));
          // Similar names can be different people, so people are never high.
          $level = $a === $b && $vid !== 'people' ? 'high' : 'medium';
          $pairs[] = [
            'kind' => 'terms', 'section' => $vid, 'keep' => (int) $old->id(), 'dup' => (int) $tid,
            'keep_label' => $label($old), 'dup_label' => sprintf('"%s"', $name), 'level' => $level, 'reasons' => $reasons,
          ];
        }
      }
    }

    // An item paired more than once is ambiguous: never merge it unchecked.
    foreach (['keep', 'dup'] as $side) {
      $seen = array_count_values(array_map(fn($p) => $p['kind'] . $p[$side], $pairs));
      foreach ($pairs as &$p) {
        if ($seen[$p['kind'] . $p[$side]] > 1 && $p['level'] === 'high') {
          $p['level'] = 'medium';
          $p['reasons'][] = 'more than one candidate';
        }
      }
      unset($p);
    }
    return $pairs;
  }

  /**
   * IDs of nodes the ISFDB import created (rather than filled in).
   *
   * @return int[]
   */
  public function createdNodeIds(): array {
    $ids = [];
    foreach ($this->migrationManager->createInstancesByTag('isfdb') as $migration) {
      $map = $migration->getIdMap();
      if ($map instanceof Sql && $map->getDatabase()->schema()->tableExists($map->mapTableName())) {
        $ids = array_merge($ids, $map->getDatabase()->select($map->mapTableName(), 'm')->fields('m', ['destid1'])
          ->condition('rollback_action', MigrateIdMapInterface::ROLLBACK_DELETE)->isNotNull('destid1')->execute()->fetchCol());
      }
    }
    return array_map('intval', $ids);
  }

}
