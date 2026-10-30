<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sectorgeneral\LanguageStats;
use Drupal\taxonomy\TermInterface;

/**
 * "45 publications · 1963–2021 · 25 editions of 10 books · …" on a
 * language's page.
 */
class LanguageHooks {

  use StringTranslationTrait;

  public function __construct(
    protected LanguageStats $languageStats,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['taxonomy_term']['language']['display']['language_summary'] = [
      'label' => $this->t('Publication summary'),
      'description' => $this->t('How many editions and magazine issues there are in this language, and when.'),
      'weight' => -10,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for taxonomy terms.
   */
  #[Hook('taxonomy_term_view')]
  public function taxonomyTermView(array &$build, TermInterface $term, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($term->bundle() !== 'language' || !$display->getComponent('language_summary')) {
      return;
    }
    $build['language_summary'] = [
      '#theme' => 'item_list',
      '#items' => array_values($this->languageStats->phrases($term)),
      '#attributes' => ['class' => ['summary-line']],
    ];
    $this->languageStats->cacheability($term)->applyTo($build['language_summary']);
  }

}
