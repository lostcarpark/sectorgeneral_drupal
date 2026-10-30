<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sectorgeneral\Plugin\Field\FieldFormatter\PeopleLabelFormatter;
use Drupal\taxonomy\TermInterface;

/**
 * Figures for James White's publications in one language.
 *
 * Used for the summary line on a language's page and its card on the
 * Translations index.
 */
class LanguageStats {

  use StringTranslationTrait;

  /**
   * Figures already worked out, by term ID.
   */
  protected array $stats = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The figures for a language.
   *
   * @return array
   *   - editions, issues: how many published editions and magazine issues.
   *   - books: how many different books the editions are of.
   *   - translators: how many named translators (not "Uncredited").
   *   - first, last: the years of the earliest and latest.
   *   - covers: up to three cover media entities, earliest first.
   */
  public function get(TermInterface $language): array {
    return $this->stats[$language->id()] ??= $this->work($language);
  }

  /**
   * Cache metadata for anything showing the figures.
   */
  public function cacheability(TermInterface $language): CacheableMetadata {
    return CacheableMetadata::createFromObject($language)->addCacheTags(['node_list:edition', 'node_list:magazine_issue']);
  }

  /**
   * The figures as short phrases, for a summary line.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   "45 publications", "1963–2021", "25 editions of 10 books",
   *   "20 magazine issues", "8 translators" - those that apply.
   */
  public function phrases(TermInterface $language): array {
    $s = $this->get($language);
    $phrases = ['publications' => $this->formatPlural($s['editions'] + $s['issues'], '1 publication', '@count publications')];
    if ($s['first']) {
      $phrases['years'] = $s['first'] === $s['last'] ? (string) $s['first'] : $s['first'] . '–' . $s['last'];
    }
    if ($s['editions']) {
      $phrases['editions'] = $this->t('@editions of @books', [
        '@editions' => $this->formatPlural($s['editions'], '1 edition', '@count editions'),
        '@books' => $this->formatPlural($s['books'], '1 book', '@count books'),
      ]);
    }
    if ($s['issues']) {
      $phrases['issues'] = $this->formatPlural($s['issues'], '1 magazine issue', '@count magazine issues');
    }
    if ($s['translators']) {
      $phrases['translators'] = $this->formatPlural($s['translators'], '1 named translator', '@count named translators');
    }
    return $phrases;
  }

  protected function work(TermInterface $language): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', ['edition', 'magazine_issue'], 'IN')
      ->condition('status', 1)
      ->condition('field_language', $language->id())
      ->sort('field_sort_date')
      ->execute();
    $stats = ['editions' => 0, 'issues' => 0, 'books' => 0, 'translators' => 0, 'first' => NULL, 'last' => NULL, 'covers' => []];
    $books = $translators = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $node->bundle() === 'edition' ? $stats['editions']++ : $stats['issues']++;
      if ($year = (int) $node->get('field_year')->value) {
        $stats['first'] = min($stats['first'] ?? $year, $year);
        $stats['last'] = max($stats['last'] ?? $year, $year);
      }
      if ($node->hasField('field_novel')) {
        foreach ($node->get('field_novel')->getValue() as $item) {
          $books[$item['target_id']] = TRUE;
        }
      }
      if ($node->hasField('field_translator')) {
        foreach ($node->get('field_translator')->referencedEntities() as $person) {
          if (!PeopleLabelFormatter::isPlaceholder($person)) {
            $translators[$person->id()] = TRUE;
          }
        }
      }
      if (count($stats['covers']) < 3 && ($cover = $node->get('field_cover')->entity)) {
        $stats['covers'][] = $cover;
      }
    }
    $stats['books'] = count($books);
    $stats['translators'] = count($translators);
    return $stats;
  }

}
