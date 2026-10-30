<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Trims filters on listings to the options the listing's content uses.
 *
 * The series vocabulary is shared by books, stories and anthologies, so the
 * books page's Series filter would otherwise offer anthology series such as
 * New Writings in SF, which match no books. Likewise book types no book has.
 */
class ExposedFilterHooks {

  /**
   * Filters to trim: view ID => [identifier => [node type, field, column]].
   */
  protected const FILTERS = [
    'books' => [
      'type' => ['novel', 'field_book_type', 'value'],
      'series' => ['novel', 'field_series', 'target_id'],
    ],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for views_exposed_form.
   */
  #[Hook('form_views_exposed_form_alter')]
  public function formViewsExposedFormAlter(array &$form, FormStateInterface $form_state): void {
    $view = $form_state->get('view');
    foreach (self::FILTERS[$view?->id()] ?? [] as $identifier => [$bundle, $field, $column]) {
      if (empty($form[$identifier]['#options'])) {
        continue;
      }
      $used = $this->usedValues($bundle, $field, $column);
      // Keep "- Any -" and the values some published item of this type has.
      $form[$identifier]['#options'] = array_filter(
        $form[$identifier]['#options'],
        fn($key) => $key === 'All' || isset($used[$key]),
        ARRAY_FILTER_USE_KEY,
      );
      // Rebuild when content of this type changes.
      $form['#cache']['tags'][] = "node_list:$bundle";
    }
  }

  /**
   * Values of a field column across published nodes of a type.
   *
   * @return array<int|string, true>
   */
  protected function usedValues(string $bundle, string $field, string $column): array {
    $rows = $this->entityTypeManager->getStorage('node')->getAggregateQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->exists($field)
      ->groupBy("$field.$column")
      ->execute();
    // One value per row; its key depends on the field type, so don't rely on it.
    return array_fill_keys(array_map(fn(array $row) => array_values($row)[0], $rows), TRUE);
  }

}
