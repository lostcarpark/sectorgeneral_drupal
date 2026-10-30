<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\sectorgeneral\PublicationDate;

/**
 * Keeps each edition's and magazine issue's sort date in step with its date.
 */
class SortDateHooks {

  /**
   * Implements hook_ENTITY_TYPE_presave() for nodes.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if (!$node->hasField('field_sort_date')) {
      return;
    }
    $exact = $node->hasField('field_publication_date') ? $node->get('field_publication_date')->value : NULL;
    // Undated items sort after everything dated.
    $node->set('field_sort_date', PublicationDate::sortDate(
      $node->get('field_date_display')->value,
      (int) $node->get('field_year')->value ?: NULL,
      $exact,
    ) ?? '9999-12-31');
  }

}
