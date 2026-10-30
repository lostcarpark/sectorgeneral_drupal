<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One cover from each language he has been translated into, each linking to
 * that language's page.
 *
 * Each language shows the cover of its earliest edition that has one, so the
 * row is the same for everyone (pages are cached for anonymous visitors).
 */
#[Block(
  id: 'sectorgeneral_around_the_world',
  admin_label: new TranslatableMarkup('Around the world (translated covers)'),
)]
class AroundTheWorldBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  public function build() {
    $cache = (new CacheableMetadata())->addCacheTags(['node_list:edition', 'taxonomy_term_list:language']);
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'edition')
      ->condition('status', 1)
      ->exists('field_cover')
      ->condition('field_language.entity.name', 'English', '<>')
      ->sort('field_year')
      ->execute();
    $media_view = $this->entityTypeManager->getViewBuilder('media');
    $tiles = [];
    foreach ($storage->loadMultiple($ids) as $edition) {
      $language = $edition->get('field_language')->entity;
      $cover = $edition->get('field_cover')->entity;
      if (!$language || !$cover || isset($tiles[$language->label()]) || !$cover->access('view')) {
        continue;
      }
      $cache->addCacheableDependency($edition)->addCacheableDependency($language)->addCacheableDependency($cover);
      $tiles[$language->label()] = [
        'language' => $language->label(),
        'url' => $language->toUrl()->toString(),
        'title' => $edition->label(),
        'cover' => $media_view->view($cover, 'cover_card'),
      ];
    }
    ksort($tiles);
    $build = [
      '#type' => 'inline_template',
      '#template' => '<p class="front-section__intro">{{ intro }} <a href="{{ url }}">{{ link }}</a></p><ul class="front-world">{% for tile in tiles %}<li class="front-world__tile"><div class="front-world__cover">{{ tile.cover }}</div><a class="front-world__link" href="{{ tile.url }}" title="{{ tile.title }}">{{ tile.language }}</a></li>{% endfor %}</ul>',
      '#context' => [
        'intro' => $this->t('The earliest cover we have in each language he was translated into.'),
        'url' => Url::fromUserInput('/translations')->toString(),
        'link' => $this->t('All translations'),
        'tiles' => array_values($tiles),
      ],
      '#attached' => ['library' => ['sectorgeneral/front']],
    ];
    $cache->applyTo($build);
    return $build;
  }

}
