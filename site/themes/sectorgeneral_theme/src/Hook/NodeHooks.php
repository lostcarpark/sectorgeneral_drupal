<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_theme\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Variables for book and edition pages (node--novel--full.html.twig and
 * node--edition--full.html.twig).
 */
class NodeHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_preprocess_HOOK() for node templates.
   *
   * Adds book_summary, the facts for the book-header's summary line - "Fix-up
   * novel · Sector General, book 2 · First published 1963 by Ballantine
   * Books · 55,250 words" - and book_credit, who wrote the synopsis.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $variables['view_mode'] !== 'full') {
      return;
    }
    // Book and edition templates attach it with the rest of the book styles.
    if (in_array($node->bundle(), ['magazine_issue', 'anthology'], TRUE)) {
      $variables['#attached']['library'][] = 'sectorgeneral_theme/cover-lightbox';
      return;
    }
    if ($node->bundle() === 'edition') {
      $this->preprocessEdition($variables, $node);
      return;
    }
    if ($node->bundle() !== 'novel') {
      return;
    }
    $cache = CacheableMetadata::createFromRenderArray($variables);
    $summary = [];

    $type = $node->get('field_book_type');
    if ($value = $type->value) {
      $summary[] = $type->getFieldDefinition()->getSetting('allowed_values')[$value] ?? $value;
    }

    if ($series = $node->get('field_series')->entity) {
      $cache->addCacheableDependency($series);
      $number = $node->get('field_series_number')->value;
      $summary[] = [
        '#type' => 'link',
        '#title' => $number ? $this->t('@series, book @n', ['@series' => $series->label(), '@n' => $number]) : $series->label(),
        '#url' => $series->toUrl(),
      ];
    }

    $year = $node->get('field_first_published_year')->value;
    $publisher = $node->get('field_publisher')->entity;
    if ($publisher) {
      $cache->addCacheableDependency($publisher);
    }
    if ($year && $publisher) {
      $summary[] = $this->t('First published @year by @publisher', ['@year' => $year, '@publisher' => $publisher->label()]);
    }
    elseif ($year || $publisher) {
      $summary[] = $this->t('First published @detail', ['@detail' => $year ?: $this->t('by @publisher', ['@publisher' => $publisher->label()])]);
    }

    if ($words = $node->get('field_words')->value) {
      $summary[] = $this->t('@count words', ['@count' => number_format((int) $words)]);
    }

    $variables['book_summary'] = $summary;
    $variables['book_credit'] = $node->get('field_synopsis_credit')->value;
    $cache->applyTo($variables);
  }

  /**
   * Adds edition_summary - "Paperback · Ballantine Books, New York · March
   * 1963" - and edition_relation, which also gives a translation's language:
   * "An edition of Star Surgeon", "A Dutch translation of Star Surgeon", "An
   * edition of Beginning Operations, containing Hospital Station, Star Surgeon
   * and Major Operation", "Published in Out of This World 8".
   */
  protected function preprocessEdition(array &$variables, NodeInterface $edition): void {
    $cache = CacheableMetadata::createFromRenderArray($variables);
    $summary = [];

    $language = $edition->get('field_language')->entity;
    $translation = $language && $language->label() !== 'English';
    if ($language) {
      $cache->addCacheableDependency($language);
    }
    foreach (['field_format', 'field_publisher'] as $field) {
      foreach ($edition->get($field)->referencedEntities() as $term) {
        $cache->addCacheableDependency($term);
        $summary[] = $term->label();
      }
    }
    $date = $edition->get('field_date_display')->value ?: $edition->get('field_year')->value;
    if ($date) {
      $summary[] = $date;
    }

    // What it's an edition of. The first book is the one it belongs to.
    $books = $edition->get('field_novel')->referencedEntities();
    $link = fn(NodeInterface $node) => $node->toLink()->toRenderable();
    $relation = [];
    if ($books) {
      foreach ($books as $book) {
        $cache->addCacheableDependency($book);
      }
      $first = array_shift($books);
      $contained = array_map('intval', array_column($first->get('field_contained_books')->getValue(), 'target_id'));
      $is_part = fn(NodeInterface $b) => in_array((int) $b->id(), $contained, TRUE);
      if ($books && count(array_filter($books, $is_part)) === count($books)) {
        // An omnibus edition: the omnibus, then the books in it.
        $template = $translation ? '{{ article }} {{ language }} translation of {{ first }}, containing {{ rest }}.' : 'An edition of {{ first }}, containing {{ rest }}.';
        $relation[] = $this->sentence($template, ['first' => $link($first), 'rest' => $this->andList(array_map($link, $books))] + $this->languageWords($language));
      }
      else {
        $all = array_map($link, [$first, ...$books]);
        $template = $translation ? '{{ article }} {{ language }} translation of {{ rest }}.' : 'An edition of {{ rest }}.';
        $relation[] = $this->sentence($template, ['rest' => $this->andList($all)] + $this->languageWords($language));
      }
    }
    if ($anthology = $edition->get('field_in_anthology')->entity) {
      $cache->addCacheableDependency($anthology);
      $relation[] = $this->sentence('Published in {{ rest }}.', ['rest' => $link($anthology)]);
    }

    $variables['edition_summary'] = $summary;
    $variables['edition_relation'] = $relation;
    $cache->applyTo($variables);
  }

  /**
   * A sentence with links in it, from a fixed template.
   *
   * Names and links go in as variables, never into the template itself.
   */
  protected function sentence(string $template, array $context): array {
    return ['#type' => 'inline_template', '#template' => $template, '#context' => $context, '#suffix' => ' '];
  }

  /**
   * The language's name, and "A" or "An" to go before it.
   */
  protected function languageWords($language): array {
    $name = (string) $language?->label();
    return ['language' => $name, 'article' => preg_match('/^[AEIOU]/i', $name) ? 'An' : 'A'];
  }

  /**
   * "A, B and C" from rendered items.
   */
  protected function andList(array $items): array {
    $last = array_pop($items);
    if (!$items) {
      return $last;
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{{ items|safe_join(", ") }} {{ and }} {{ last }}',
      '#context' => ['items' => $items, 'and' => $this->t('and'), 'last' => $last],
    ];
  }

}
