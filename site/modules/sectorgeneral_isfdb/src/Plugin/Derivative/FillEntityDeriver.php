<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;

/**
 * Provides sg_fill:node and sg_fill:taxonomy_term, like core's entity:TYPE.
 *
 * Core destinations take the entity type from the derivative part of the
 * plugin ID, so the fill destination follows the same pattern.
 */
class FillEntityDeriver extends DeriverBase {

  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach (['node', 'taxonomy_term'] as $entity_type) {
      $this->derivatives[$entity_type] = $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
