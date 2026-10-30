<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\sectorgeneral_isfdb\DuplicateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists likely duplicates from the ISFDB import, by confidence.
 */
class DuplicatesController extends ControllerBase {

  protected const LEVELS = [
    'high' => ['High confidence', 'Same publisher and year, same magazine issue, or a closely matching title in the same year, with no other candidate. Safe to merge; or merge them all with drush sg-isfdb:merge-confident.'],
    'medium' => ['Medium confidence', 'Probably the same: check each before merging.'],
    'low' => ['Low confidence', 'Probably different, for example the British and American editions of a magazine issue. Merge only if you know they are the same.'],
    'printing' => ['Printings sharing an ISBN', 'ISFDB lists each printing separately, so these are kept as separate editions. Merge only if one is a mistake.'],
  ];

  public function __construct(protected DuplicateFinder $finder) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get(DuplicateFinder::class));
  }

  public function list(): array {
    $pairs = $this->finder->find();
    $build = [
      'intro' => ['#markup' => '<p>' . $this->t('Each row pairs existing content (kept) with an item the ISFDB import created. Merging keeps the first item and its values, fills its gaps from the second, moves references and the address to it, and deletes the second. The list is worked out afresh each time, so merged pairs disappear.') . '</p>'],
    ];
    foreach (self::LEVELS as $level => [$title, $help]) {
      $rows = [];
      foreach (array_filter($pairs, fn($p) => $p['level'] === $level) as $p) {
        $entity_type = $p['kind'] === 'nodes' ? 'node' : 'taxonomy_term';
        $rows[] = [
          $p['section'],
          Link::fromTextAndUrl($p['keep_label'], Url::fromRoute("entity.$entity_type.canonical", [$entity_type => $p['keep']])),
          Link::fromTextAndUrl($p['dup_label'], Url::fromRoute("entity.$entity_type.canonical", [$entity_type => $p['dup']])),
          implode(', ', $p['reasons']),
          ['data' => ['#markup' => sprintf('<code>drush sg-isfdb:merge-%s %d %d</code>', $p['kind'], $p['keep'], $p['dup'])]],
          Link::fromTextAndUrl($this->t('Merge'), Url::fromRoute('sectorgeneral_isfdb.merge', ['kind' => $p['kind'], 'keep' => $p['keep'], 'duplicate' => $p['dup']], ['query' => ['destination' => '/admin/content/isfdb-duplicates']])),
        ];
      }
      $build[$level] = [
        '#type' => 'details',
        '#title' => $this->t('@title (@count)', ['@title' => $title, '@count' => count($rows)]),
        '#open' => $level === 'medium',
        'help' => ['#markup' => '<p>' . $help . '</p>'],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Type'), $this->t('Keep'), $this->t('Merge into it'), $this->t('Why'), $this->t('Drush'), ''],
          '#rows' => $rows,
          '#empty' => $this->t('None.'),
        ],
      ];
    }
    // Merges change the list; never serve it from cache.
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
