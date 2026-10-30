<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\node\NodeInterface;
use Drupal\sectorgeneral_isfdb\Isfdb;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds the existing node an ISFDB record describes, or NULL to create one.
 *
 * Every strategy first tries field_isfdb_id. Then:
 * - title: same normalised title or alternative title, in "bundle".
 * - edition: same ISBN; else an edition of the same book(s) and language
 *   with the same year (and no ISBN), or the same publisher and the same or
 *   an adjacent year.
 * - anthology: same title; else one containing the same story, published
 *   within a year (preferring the same year, then a clearly closer title).
 * - issue: a magazine issue of the same year containing the same story, or
 *   of the same magazine dated the same month and year.
 * A node already claimed by another record in this migration is never
 * matched twice, and an ambiguous match is left alone rather than guessed.
 *
 * Configuration: strategy, bundle, and the names of the row values to use
 * (title, alt_titles, isbn, year, language, publisher, books, stories).
 * Names starting with @ are destination values set earlier in the process.
 *
 * @code
 * nid:
 *   plugin: isfdb_match
 *   source: pub_id
 *   strategy: edition
 *   bundle: edition
 *   isbn: isbn
 *   books: '@_books'
 * @endcode
 */
#[MigrateProcess('isfdb_match')]
class IsfdbMatch extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MigrationInterface $migration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static($configuration, $plugin_id, $plugin_definition, $migration);
  }

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $bundle = $this->configuration['bundle'];
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    $by_id = $storage->loadByProperties(['type' => $bundle, 'field_isfdb_id' => (string) $value]);
    if ($match = $this->single($by_id, $row)) {
      return (int) $match->id();
    }
    $match = match ($this->configuration['strategy']) {
      'title' => $this->byTitle($bundle, $row),
      'edition' => $this->edition($row),
      'issue' => $this->issue($row),
      'anthology' => $this->anthology($row),
    };
    return $match ? (int) $match->id() : NULL;
  }

  protected function byTitle(string $bundle, Row $row): ?NodeInterface {
    $wanted = array_map([Isfdb::class, 'normalizeTitle'], array_merge([(string) $this->get($row, 'title')], (array) $this->get($row, 'alt_titles')));
    $found = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => $bundle]) as $node) {
      $titles = [$node->label()];
      if ($node->hasField('field_alternative_titles')) {
        $titles = array_merge($titles, array_column($node->get('field_alternative_titles')->getValue(), 'value'));
      }
      if (array_intersect($wanted, array_map([Isfdb::class, 'normalizeTitle'], $titles))) {
        $found[] = $node;
      }
    }
    return $this->single($found, $row);
  }

  protected function edition(Row $row): ?NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $isbn = $this->get($row, 'isbn');
    if ($isbn && ($match = $this->single($storage->loadByProperties(['type' => 'edition', 'field_isbn' => $isbn]), $row))) {
      return $match;
    }
    $books = array_filter((array) $this->get($row, 'books'));
    $year = (int) $this->get($row, 'year');
    if (!$books || !$year) {
      return NULL;
    }
    $language = $this->get($row, 'language');
    $publisher = (int) $this->get($row, 'publisher');
    // A magazine-serial entry summarises magazine issues; it is never the
    // same thing as a book publication.
    $editions = array_filter($storage->loadByProperties(['type' => 'edition', 'field_novel' => $books]), fn(NodeInterface $e) =>
      (!$language || $e->get('field_language')->entity?->label() === $language)
      && $e->get('field_format')->entity?->label() !== 'Magazine serial');
    $same_publisher = fn(NodeInterface $e) => $publisher && (int) $e->get('field_publisher')->target_id === $publisher;
    $year_of = fn(NodeInterface $e) => (int) $e->get('field_year')->value;

    // 1. Same year, and no ISBN of its own (one with an ISBN would have
    //    matched above); narrowed by publisher if that leaves several.
    $same = array_filter($editions, fn($e) => $year_of($e) === $year && !Isfdb::isbn13($e->get('field_isbn')->value));
    if (count($this->unclaimed($same, $row)) > 1) {
      $same = array_filter($same, $same_publisher);
    }
    // 2. Same publisher and year, even though the ISBNs differ: old records
    //    often have a mistyped ISBN. The difference is logged as a conflict.
    // 3. Same publisher, a year either side: dates recorded differently.
    foreach ([
      $same,
      array_filter($editions, fn($e) => $same_publisher($e) && $year_of($e) === $year),
      array_filter($editions, fn($e) => $same_publisher($e) && $year_of($e) && abs($year_of($e) - $year) <= 1),
    ] as $candidates) {
      if ($match = $this->single($candidates, $row)) {
        return $match;
      }
    }
    return NULL;
  }

  /**
   * An anthology with the same title, or containing one of the same stories
   * and published within a year: the same book under a variant title.
   */
  protected function anthology(Row $row): ?NodeInterface {
    if ($match = $this->byTitle('anthology', $row)) {
      return $match;
    }
    $stories = array_filter((array) $this->get($row, 'stories'));
    $year = (int) $this->get($row, 'year');
    if (!$stories) {
      return NULL;
    }
    $year_of = fn(NodeInterface $a) => (int) $a->get('field_first_published_year')->value;
    $anthologies = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'anthology', 'field_contents' => $stories]);
    $near = $this->unclaimed(array_filter($anthologies, fn($a) => !$year || !$year_of($a) || abs($year_of($a) - $year) <= 1), $row);
    // Several candidates (a translation of the same book, say): prefer the
    // same year, then the clearly closest title.
    if (count($near) > 1 && $year) {
      $near = array_filter($near, fn($a) => $year_of($a) === $year) ?: $near;
    }
    if (count($near) > 1) {
      $title = Isfdb::normalizeTitle((string) $this->get($row, 'title'));
      $scores = [];
      foreach ($near as $key => $a) {
        similar_text($title, Isfdb::normalizeTitle($a->label()), $scores[$key]);
      }
      arsort($scores);
      [$best, $next] = array_pad(array_values($scores), 2, 0);
      $near = $best >= 50 && $best - $next >= 20 ? [$near[array_key_first($scores)]] : [];
    }
    return $this->single($near, $row);
  }

  protected function issue(Row $row): ?NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $year = (int) $this->get($row, 'year');
    $stories = array_filter((array) $this->get($row, 'stories'));
    if ($stories && ($match = $this->single(array_filter($storage->loadByProperties(['type' => 'magazine_issue', 'field_contents' => $stories]),
      fn(NodeInterface $i) => (int) $i->get('field_year')->value === $year), $row))) {
      return $match;
    }
    // The same magazine, dated the same month and year: the same issue, even
    // if its contents were recorded differently (a story here, the book it
    // grew into there).
    $magazine = (int) $this->get($row, 'magazine');
    $month = Isfdb::month((string) $this->get($row, 'date'));
    if (!$magazine || !$year || !$month) {
      return NULL;
    }
    // A British reprint edition is not the same issue as the American one.
    $edition = (string) $this->get($row, 'edition');
    return $this->single(array_filter($storage->loadByProperties(['type' => 'magazine_issue', 'field_magazine' => $magazine]),
      fn(NodeInterface $i) => (int) $i->get('field_year')->value === $year && Isfdb::month((string) $i->get('field_date_display')->value) === $month
        && (string) $i->get('field_regional_edition')->value === $edition), $row);
  }

  /**
   * The one candidate left after removing nodes claimed by other records.
   */
  protected function single(array $nodes, Row $row): ?NodeInterface {
    $nodes = $this->unclaimed($nodes, $row);
    return count($nodes) === 1 ? reset($nodes) : NULL;
  }

  protected function unclaimed(array $nodes, Row $row): array {
    $source = $row->getSourceIdValues();
    return array_filter($nodes, function (NodeInterface $node) use ($source) {
      $claimed = $this->migration->getIdMap()->lookupSourceId(['nid' => $node->id()]);
      return !$claimed || array_values($claimed) == array_values($source);
    });
  }

  protected function get(Row $row, string $key) {
    $name = $this->configuration[$key] ?? NULL;
    if ($name === NULL) {
      return NULL;
    }
    return str_starts_with($name, '@') ? $row->getDestinationProperty(substr($name, 1)) : $row->getSourceProperty($name);
  }

}
