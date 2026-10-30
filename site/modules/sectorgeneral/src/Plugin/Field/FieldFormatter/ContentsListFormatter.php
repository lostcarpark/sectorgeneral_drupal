<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A book's contents as a numbered list: "Resident Physician (1961)".
 *
 * Each item links to the story or book, followed by the year it was first
 * published, where known.
 */
#[FieldFormatter(
  id: 'sectorgeneral_contents_list',
  label: new TranslatableMarkup('Contents list, numbered, with years'),
  field_types: ['entity_reference'],
)]
class ContentsListFormatter extends EntityReferenceFormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $list = [];
    $cache = new CacheableMetadata();
    foreach ($this->getEntitiesToView($items, $langcode) as $entity) {
      $cache->addCacheableDependency($entity);
      $year = $entity->hasField('field_first_published_year') ? $entity->get('field_first_published_year')->value : NULL;
      $list[] = [
        '#type' => 'inline_template',
        '#template' => '{{ link }}{% if year %} <span class="contents-list__year">({{ year }})</span>{% endif %}',
        '#context' => [
          'link' => $entity->toLink()->toRenderable(),
          'year' => $year,
        ],
      ];
    }
    if (!$list) {
      return [];
    }
    $element = [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#items' => $list,
      '#attributes' => ['class' => ['contents-list']],
    ];
    $cache->applyTo($element);
    return [$element];
  }

}
