<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * "Sector General · book 2 of 12" with the previous and next books.
 *
 * Worked out from the books' series and numbers, so nothing needs entering.
 * A book in a series without a number (an omnibus, say) just names the
 * series.
 */
class SeriesHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['node']['novel']['display']['series_nav'] = [
      'label' => $this->t('Series navigation'),
      'description' => $this->t('Which book of its series this is, with links to the books before and after it.'),
      'weight' => 15,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for nodes.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'novel' || !$display->getComponent('series_nav')) {
      return;
    }
    $cache = CacheableMetadata::createFromRenderArray($build);
    $cache->addCacheTags(['node_list:novel']);
    $series = $node->get('field_series')->entity;
    if ($series) {
      $cache->addCacheableDependency($series);
      $number = (int) $node->get('field_series_number')->value;
      $books = $this->numberedBooks((int) $series->id());
      $series_link = ['#type' => 'link', '#title' => $series->label(), '#url' => $series->toUrl()];

      $previous = $next = NULL;
      if ($number && isset($books[$number])) {
        $numbers = array_keys($books);
        $position = array_search($number, $numbers, TRUE);
        $previous = $position > 0 ? $books[$numbers[$position - 1]] : NULL;
        $next = $books[$numbers[$position + 1] ?? NULL] ?? NULL;
      }
      $build['series_nav'] = [
        '#type' => 'inline_template',
        '#template' => '<nav class="series-nav" aria-label="{{ label }}">'
          . '{% if previous %}<a class="series-nav__previous" href="{{ previous.url }}" rel="prev"><span class="series-nav__direction">{{ previous_label }}</span> {{ previous.title }}</a>{% endif %}'
          . '<p class="series-nav__position">{{ series }}{% if number %} · {{ position }}{% endif %}</p>'
          . '{% if next %}<a class="series-nav__next" href="{{ next.url }}" rel="next"><span class="series-nav__direction">{{ next_label }}</span> {{ next.title }}</a>{% endif %}'
          . '</nav>',
        '#context' => [
          'label' => $this->t('@series series', ['@series' => $series->label()]),
          'series' => $series_link,
          'number' => $number,
          'position' => $books && $number ? $this->t('book @n of @total', ['@n' => $number, '@total' => max(array_keys($books))]) : $this->t('book @n', ['@n' => $number]),
          'previous' => $previous ? ['url' => $previous->toUrl()->toString(), 'title' => $previous->label()] : NULL,
          'next' => $next ? ['url' => $next->toUrl()->toString(), 'title' => $next->label()] : NULL,
          'previous_label' => $this->t('Previous'),
          'next_label' => $this->t('Next'),
        ],
      ];
    }
    $cache->applyTo($build);
  }

  /**
   * Published books of a series that have a number, keyed by number.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function numberedBooks(int $series): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'novel')
      ->condition('status', 1)
      ->condition('field_series', $series)
      ->exists('field_series_number')
      ->execute();
    $books = [];
    foreach ($storage->loadMultiple($ids) as $book) {
      // The first book with a number keeps it, should two share one.
      $books[(int) $book->get('field_series_number')->value] ??= $book;
    }
    ksort($books);
    return $books;
  }

}
