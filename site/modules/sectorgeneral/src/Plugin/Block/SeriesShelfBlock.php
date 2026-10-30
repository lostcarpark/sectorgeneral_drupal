<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Sector General books in series order, as a shelf of book cards.
 */
#[Block(
  id: 'sectorgeneral_series_shelf',
  admin_label: new TranslatableMarkup('Sector General shelf'),
)]
class SeriesShelfBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The series shown.
   */
  protected const SERIES = 'Sector General';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  public function build() {
    $cache = (new CacheableMetadata())->addCacheTags(['node_list:novel']);
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => 'series', 'name' => self::SERIES]);
    $series = reset($terms);
    if (!$series) {
      return [];
    }
    $cache->addCacheableDependency($series);
    $storage = $this->entityTypeManager->getStorage('node');
    // The numbered novels; the later collections and omnibuses aren't numbered.
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'novel')
      ->condition('status', 1)
      ->condition('field_series', $series->id())
      ->exists('field_series_number')
      ->sort('field_series_number')
      ->execute();
    $media_view = $this->entityTypeManager->getViewBuilder('media');
    $items = [];
    foreach ($storage->loadMultiple($ids) as $book) {
      $cache->addCacheableDependency($book);
      $cover = $book->get('field_cover')->entity;
      $items[] = [
        '#type' => 'component',
        '#component' => 'sectorgeneral_theme:book-card',
        '#props' => [
          'title' => $book->label(),
          'url' => $book->toUrl()->toString(),
          'year' => (string) $book->get('field_first_published_year')->value,
          'series' => $this->t('Book @n', ['@n' => $book->get('field_series_number')->value]),
        ],
        '#slots' => ['cover' => $cover ? $media_view->view($cover, 'cover_card') : []],
      ];
    }
    $build = [
      '#type' => 'inline_template',
      '#template' => '<p class="front-section__intro">{{ intro }} <a href="{{ url }}">{{ link }}</a></p><ol class="front-shelf">{% for item in items %}<li>{{ item }}</li>{% endfor %}</ol>',
      '#context' => [
        'intro' => $this->t('Twelve novels, from @first to @last, set in a vast hospital in space.', [
          '@first' => $items ? $storage->load(reset($ids))->get('field_first_published_year')->value : '',
          '@last' => $items ? $storage->load(end($ids))->get('field_first_published_year')->value : '',
        ]),
        'url' => $series->toUrl()->toString(),
        'link' => $this->t('The whole series'),
        'items' => $items,
      ],
      '#attached' => ['library' => ['sectorgeneral/front']],
    ];
    $cache->applyTo($build);
    return $build;
  }

}
