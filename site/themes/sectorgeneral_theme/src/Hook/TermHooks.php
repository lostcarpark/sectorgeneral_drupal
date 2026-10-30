<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_theme\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\sectorgeneral\LanguageStats;
use Drupal\taxonomy\TermInterface;

/**
 * Variables for language cards (taxonomy-term--language--card.html.twig).
 */
class TermHooks {

  public function __construct(
    protected LanguageStats $languageStats,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for taxonomy terms.
   *
   * Core suggests templates by vocabulary and term, not view mode; adds
   * taxonomy-term--VOCABULARY--VIEW-MODE.html.twig.
   */
  #[Hook('theme_suggestions_taxonomy_term_alter')]
  public function themeSuggestionsTaxonomyTermAlter(array &$suggestions, array $variables): void {
    $term = $variables['elements']['#taxonomy_term'] ?? NULL;
    $view_mode = $variables['elements']['#view_mode'] ?? NULL;
    if ($term instanceof TermInterface && $view_mode && $view_mode !== 'full') {
      $suggestions[] = 'taxonomy_term__' . $term->bundle() . '__' . strtr($view_mode, '.', '_');
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for taxonomy term templates.
   */
  #[Hook('preprocess_taxonomy_term')]
  public function preprocessTaxonomyTerm(array &$variables): void {
    $term = $variables['term'] ?? NULL;
    if (!$term instanceof TermInterface || $term->bundle() !== 'language' || $variables['view_mode'] !== 'card') {
      return;
    }
    $phrases = $this->languageStats->phrases($term);
    $media = $this->entityTypeManager->getViewBuilder('media');
    $variables['language_card'] = [
      'name' => $term->label(),
      'url' => $term->toUrl()->toString(),
      'summary' => array_values(array_intersect_key($phrases, array_flip(['publications', 'years']))),
      'detail' => array_values(array_intersect_key($phrases, array_flip(['editions', 'issues']))),
      'covers' => array_map(fn($cover) => $media->view($cover, 'cover_small'), $this->languageStats->get($term)['covers']),
    ];
    $this->languageStats->cacheability($term)->applyTo($variables);
  }

}
