<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;

/**
 * Builds the credit line shown beneath photos.
 *
 * Every photo is credited, even where no licence asks for it: who took it
 * (or that nobody knows), who holds the copyright, where the site found it,
 * and on what basis the site uses it.
 */
class PhotoCreditHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['media']['photo']['display']['photo_credit'] = [
      'label' => $this->t('Photo credit'),
      'description' => $this->t('Photographer, copyright holder, source and basis for use.'),
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
    if ($media->bundle() !== 'photo' || !$display->getComponent('photo_credit')) {
      return;
    }
    $cache = CacheableMetadata::createFromObject($media);
    $parts = [];

    $photographer = $media->get('field_photographer')->value;
    $parts[] = ['#plain_text' => $photographer ? $this->t('Photo: @name', ['@name' => $photographer]) : $this->t('Photographer unknown')];

    if ($holder = $media->get('field_copyright_holder')->value) {
      $parts[] = ['#plain_text' => '© ' . $holder];
    }

    $source = $media->get('field_image_source')->entity;
    if ($source) {
      $cache->addCacheableDependency($source);
      $uri = $media->get('field_source_url')->uri ?: $source->get('field_website')->uri;
      $parts[] = [
        '#type' => 'inline_template',
        '#template' => '{{ label }} {{ source }}',
        '#context' => [
          'label' => $this->t('Via'),
          'source' => $uri
            ? ['#type' => 'link', '#title' => $source->label(), '#url' => Url::fromUri($uri)]
            : ['#plain_text' => $source->label()],
        ],
      ];
    }

    if ($note = $media->get('field_credit_note')->value) {
      $parts[] = ['#plain_text' => $note];
    }

    $licence = $media->get('field_licence')->first();
    $parts[] = match ($media->get('field_rights_basis')->value) {
      'licence' => $licence && $licence->uri
        ? ['#type' => 'link', '#title' => $licence->title ?: $this->t('Licence'), '#url' => Url::fromUri($licence->uri)]
        : ['#plain_text' => $licence->title ?? $this->t('Licensed')],
      'permission' => ['#plain_text' => $this->t('Used with permission')],
      'public_domain' => ['#plain_text' => $this->t('Public domain')],
      'fair_dealing' => ['#plain_text' => $this->t('Used for reference without permission; the copyright holder may ask for it to be removed')],
      default => [],
    };

    $build['photo_credit'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="photo-credit">{{ parts|safe_join(" · ") }}</p>',
      '#context' => ['parts' => array_filter($parts)],
    ];
    CacheableMetadata::createFromRenderArray($build)->merge($cache)->applyTo($build);
  }

}
