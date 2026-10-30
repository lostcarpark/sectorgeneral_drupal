<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Person pages list work by role (the person_credits view), so the generic
 * "content tagged with this term" list from core would only repeat it.
 */
class PersonPageHooks {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_views_query_alter().
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, QueryPluginBase $query): void {
    if ($view->id() !== 'taxonomy_term' || !$query instanceof Sql || empty($view->args[0])) {
      return;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($view->args[0]);
    // People and languages have their own lists of work (person_credits and
    // language_publications), so the generic list would only repeat them.
    if ($term && in_array($term->bundle(), ['people', 'language'], TRUE)) {
      $query->addWhereExpression(0, '1 = 0');
    }
  }

}
