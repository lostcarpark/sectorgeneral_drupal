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
 * "English edition 2 of 11 of Star Surgeon" with the editions either side.
 *
 * An edition's book is its first "edition of" (Beginning Operations, for an
 * omnibus edition that also contains Star Surgeon). Its neighbours are that
 * book's editions in the same language, in the date order of the book page.
 */
class EditionNavHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['node']['edition']['display']['edition_nav'] = [
      'label' => $this->t('Edition navigation'),
      'description' => $this->t("Which of its book's editions in this language this is, with links to the ones before and after it."),
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
    if ($node->bundle() !== 'edition' || !$display->getComponent('edition_nav')) {
      return;
    }
    $cache = CacheableMetadata::createFromRenderArray($build);
    $cache->addCacheTags(['node_list:edition']);
    $book = $node->get('field_novel')->entity;
    $language = $node->get('field_language')->entity;
    if ($book && $language) {
      $cache->addCacheableDependency($book)->addCacheableDependency($language);
      $editions = $this->editions((int) $book->id(), (int) $language->id());
      $ids = array_map(fn($e) => (int) $e->id(), $editions);
      $position = array_search((int) $node->id(), $ids, TRUE);
      if ($position !== FALSE) {
        $neighbour = fn(?NodeInterface $e) => $e ? ['url' => $e->toUrl()->toString(), 'title' => $this->shortLabel($e, $book)] : NULL;
        $build['edition_nav'] = [
          '#type' => 'inline_template',
          '#template' => '<nav class="series-nav edition-nav" aria-label="{{ label }}">'
            . '{% if previous %}<a class="series-nav__previous" href="{{ previous.url }}" rel="prev"><span class="series-nav__direction">{{ previous_label }}</span> {{ previous.title }}</a>{% endif %}'
            . '<p class="series-nav__position">{{ position }} {{ book }}</p>'
            . '{% if next %}<a class="series-nav__next" href="{{ next.url }}" rel="next"><span class="series-nav__direction">{{ next_label }}</span> {{ next.title }}</a>{% endif %}'
            . '</nav>',
          '#context' => [
            'label' => $this->t('Editions of @book', ['@book' => $book->label()]),
            'position' => count($editions) > 1
              ? $this->t('@language edition @n of @total of', ['@language' => $language->label(), '@n' => $position + 1, '@total' => count($editions)])
              : $this->t('The only @language edition of', ['@language' => $language->label()]),
            'book' => ['#type' => 'link', '#title' => $book->label(), '#url' => $book->toUrl()],
            'previous' => $neighbour($editions[$position - 1] ?? NULL),
            'next' => $neighbour($editions[$position + 1] ?? NULL),
            'previous_label' => $this->t('Previous'),
            'next_label' => $this->t('Next'),
          ],
        ];
      }
    }
    $cache->applyTo($build);
  }

  /**
   * Published editions whose book is $book, in $language, in date order.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function editions(int $book, int $language): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'edition')
      ->condition('status', 1)
      // The book it belongs to, not an omnibus it is also part of.
      ->condition('field_novel.0.target_id', $book)
      ->condition('field_language', $language)
      // As the book page's tables: by date, then year, then title.
      ->sort('field_sort_date')
      ->sort('field_year')
      ->sort('title')
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * "Corgi, 1967" from "Star Surgeon, Corgi, 1967": the book is understood.
   */
  protected function shortLabel(NodeInterface $edition, NodeInterface $book): string {
    $prefix = $book->label() . ', ';
    $label = $edition->label();
    return str_starts_with($label, $prefix) ? substr($label, strlen($prefix)) : $label;
  }

}
