<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\taxonomy\TermInterface;

/**
 * People as links, except placeholders such as "Uncredited", shown as text.
 *
 * A People term with "Not a person" ticked records that a work's editor or
 * artist is uncredited; it has no page worth linking to.
 */
#[FieldFormatter(
  id: 'sectorgeneral_people_label',
  label: new TranslatableMarkup('People, with placeholders unlinked'),
  field_types: ['entity_reference'],
)]
class PeopleLabelFormatter extends EntityReferenceLabelFormatter {

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      if (isset($elements[$delta]) && static::isPlaceholder($entity)) {
        $cache = $elements[$delta]['#cache'] ?? [];
        $elements[$delta] = ['#plain_text' => $entity->label(), '#cache' => $cache];
      }
    }
    return $elements;
  }

  /**
   * Whether a term stands for "no named person", like "Uncredited".
   */
  public static function isPlaceholder($entity): bool {
    return $entity instanceof TermInterface
      && $entity->hasField('field_not_a_person')
      && (bool) $entity->get('field_not_a_person')->value;
  }

}
