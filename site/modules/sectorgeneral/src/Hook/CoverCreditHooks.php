<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\sectorgeneral\Plugin\Field\FieldFormatter\PeopleLabelFormatter;

/**
 * Builds the credit line shown beneath cover images.
 *
 * The credit combines facts about the book (cover artist, publisher, year),
 * taken from the edition that uses the cover, with facts about the scan
 * (where it was found and who scanned it), taken from the media item.
 */
class CoverCreditHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['media']['cover_image']['display']['cover_credit'] = [
      'label' => $this->t('Cover credit'),
      'description' => $this->t('Artist, publisher and image source, built from the edition using this cover.'),
      'weight' => 10,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for media entities.
   */
  #[Hook('media_view')]
  public function mediaView(array &$build, MediaInterface $media, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($media->bundle() !== 'cover_image' || !$display->getComponent('cover_credit')) {
      return;
    }

    $cache = CacheableMetadata::createFromObject($media);
    // Any edition may start or stop using this cover.
    $cache->addCacheTags(['node_list:edition']);
    $parts = [];

    $edition = $this->findEdition($media);
    if ($edition) {
      $cache->addCacheableDependency($edition);

      $artists = [];
      foreach ($edition->get('field_cover_artist')->referencedEntities() as $artist) {
        $cache->addCacheableDependency($artist);
        // "Uncredited" and other placeholders aren't people to link to.
        $artists[] = PeopleLabelFormatter::isPlaceholder($artist) ? ['#plain_text' => $artist->label()] : $artist->toLink()->toRenderable();
      }
      if ($artists) {
        $parts[] = [
          '#type' => 'inline_template',
          '#template' => '{{ label }}: {{ artists|safe_join(", ") }}',
          '#context' => [
            'label' => $this->t('Cover art'),
            'artists' => $artists,
          ],
        ];
      }

      $publication = [];
      foreach ($edition->get('field_publisher')->referencedEntities() as $publisher) {
        $cache->addCacheableDependency($publisher);
        $publication[] = $publisher->label();
      }
      $date = $edition->get('field_date_display')->value ?: $edition->get('field_year')->value;
      if ($date) {
        $publication[] = $date;
      }
      if ($publication) {
        $parts[] = ['#plain_text' => implode(', ', $publication)];
      }
    }

    $source = $media->get('field_image_source')->entity;
    if ($source) {
      $cache->addCacheableDependency($source);
      $uri = $media->get('field_source_url')->uri ?: $source->get('field_website')->uri;
      $parts[] = [
        '#type' => 'inline_template',
        '#template' => '{{ label }}: {{ source }}',
        '#context' => [
          'label' => $this->t('Image'),
          'source' => $uri
            ? ['#type' => 'link', '#title' => $source->label(), '#url' => Url::fromUri($uri)]
            : ['#plain_text' => $source->label()],
        ],
      ];
    }

    if ($scanned_by = $media->get('field_scanned_by')->value) {
      $parts[] = ['#plain_text' => $this->t('Scan by @name', ['@name' => $scanned_by])];
    }

    if ($parts) {
      $build['cover_credit'] = [
        '#type' => 'inline_template',
        '#template' => '<p class="cover-credit">{{ parts|safe_join(" · ") }}</p>',
        '#context' => ['parts' => $parts],
      ];
    }
    CacheableMetadata::createFromRenderArray($build)->merge($cache)->applyTo($build);
  }

  /**
   * Finds the earliest edition that uses the given cover.
   */
  protected function findEdition(MediaInterface $media): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'edition')
      ->condition('field_cover', $media->id())
      ->sort('field_year')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

}
