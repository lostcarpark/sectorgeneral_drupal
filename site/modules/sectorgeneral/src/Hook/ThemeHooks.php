<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Templates the site module provides.
 */
class ThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      // Translations grouped by language: language names as headings.
      'views_view_table__book_editions__translations' => [
        'base hook' => 'views_view_table',
        'template' => 'views-view-table--book-editions--translations',
      ],
    ];
  }

}
