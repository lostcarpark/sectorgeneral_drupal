<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Loads the cover thumbnail styles used by listings across the site.
 */
class CoverStyleHooks {

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'sectorgeneral/covers';
  }

}
