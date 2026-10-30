<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\StringFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An edition's title without its publisher and year.
 *
 * Edition titles read "Vita con gli automi, Mondadori, 1963", which in a
 * table beside Publisher and Date columns repeats them. This shows "Vita con
 * gli automi". The ending is only removed when it matches the edition's own
 * year and publisher (or the publisher's first word, as in "Ballantine" for
 * "Ballantine Books, New York"), so titles with commas of their own are kept.
 */
#[FieldFormatter(
  id: 'sectorgeneral_edition_title',
  label: new TranslatableMarkup('Edition title, without publisher and year'),
  field_types: ['string'],
)]
class EditionTitleFormatter extends StringFormatter {

  protected function viewValue(FieldItemInterface $item) {
    $build = parent::viewValue($item);
    $build['#context']['value'] = static::shortTitle($item);
    return $build;
  }

  /**
   * The title without a trailing ", <publisher>, <year>".
   */
  public static function shortTitle(FieldItemInterface $item): string {
    $title = (string) $item->value;
    $edition = $item->getEntity();
    if (!$edition->hasField('field_publisher')) {
      return $title;
    }
    $year = (string) $edition->get('field_year')->value;
    if ($year !== '' && str_ends_with($title, ", $year")) {
      $title = substr($title, 0, -strlen(", $year"));
    }
    $last = strrpos($title, ', ');
    if ($last === FALSE) {
      return $title;
    }
    $ending = substr($title, $last + 2);
    foreach ($edition->get('field_publisher')->referencedEntities() as $publisher) {
      $name = $publisher->label();
      if ($ending === $name || strtok($ending, ' /') === strtok($name, ' ,/')) {
        return substr($title, 0, $last);
      }
    }
    return (string) $item->value;
  }

}
