<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_theme\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Listing filters with a few choices become rows of buttons.
 *
 * A single-choice drop-down with up to MAX_OPTIONS options becomes radio
 * buttons styled as a button row (css/filter-buttons.css). With JavaScript,
 * choosing one applies the filter straight away and the Apply button is
 * hidden; without, Apply works as usual.
 */
class ExposedFormHooks {

  use StringTranslationTrait;

  protected const MAX_OPTIONS = 8;

  /**
   * Implements hook_form_FORM_ID_alter() for views_exposed_form.
   */
  #[Hook('form_views_exposed_form_alter')]
  public function formViewsExposedFormAlter(array &$form, FormStateInterface $form_state): void {
    $changed = FALSE;
    foreach ($form as $key => &$element) {
      if (!is_array($element) || str_starts_with((string) $key, '#') || ($element['#type'] ?? NULL) !== 'select'
        || !empty($element['#multiple']) || count($element['#options'] ?? []) > self::MAX_OPTIONS) {
        continue;
      }
      if (isset($element['#options']['All'])) {
        $element['#options']['All'] = $this->t('All');
      }
      $element['#type'] = 'radios';
      $element['#attributes']['class'][] = 'filter-buttons';
      // The label becomes the group's legend.
      $element['#title'] = $form['#info']["filter-$key"]['label'] ?? $element['#title'] ?? NULL;
      $changed = TRUE;
    }
    if ($changed) {
      $form['#attributes']['class'][] = 'views-exposed-form--buttons';
      $form['#attached']['library'][] = 'sectorgeneral_theme/filter-buttons';
    }
  }

}
