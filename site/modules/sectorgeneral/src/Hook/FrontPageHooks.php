<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Front page sections (the series shelf, section cards and translated
 * covers) and the photo on a basic page.
 */
class FrontPageHooks {

  /**
   * The blocks laid out as front page sections.
   */
  protected const SECTIONS = [
    'sectorgeneral_series_shelf',
    'sectorgeneral_explore',
    'sectorgeneral_around_the_world',
  ];

  /**
   * Implements hook_preprocess_HOOK() for block templates.
   */
  #[Hook('preprocess_block')]
  public function preprocessBlock(array &$variables): void {
    if (in_array($variables['plugin_id'] ?? '', self::SECTIONS, TRUE)) {
      $variables['attributes']['class'][] = 'front-section';
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for node templates.
   *
   * A page's photo is styled with the front page's styles.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if ($node && $node->bundle() === 'page' && $node->hasField('field_photo') && !$node->get('field_photo')->isEmpty()) {
      $variables['#attached']['library'][] = 'sectorgeneral/front';
    }
  }

}
