<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\sectorgeneral_isfdb\Library\LibrarySourceInterface;

/**
 * Translated editions from national library catalogues.
 *
 * Each book record from a library (see Library\*Source) whose original title
 * matches one of the site's books fills in the matching edition - by ISBN,
 * by an earlier import of the same record, or by the same book, language and
 * year - or becomes a new edition in the library's language. Only empty
 * fields are filled; each edition credits the library record. Articles, and
 * records whose original title matches no book (a story published as a
 * booklet, say), are left for an editor and reported.
 *
 * LibraryRecords decides which records are applied: only those an editor has
 * approved, matched as proposed or as the editor chose.
 */
class LibraryImporter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * State key listing the nodes a source created, keyed by node ID.
   */
  public static function createdKey(LibrarySourceInterface $source): string {
    return "sectorgeneral_isfdb.{$source->id()}_created";
  }

  /**
   * What would happen to a record, without changing anything.
   *
   * @param array $target
   *   An editor's choice, overriding the match: "edition" (a node to fill
   *   in) or "book" (a node to add a new edition to).
   *
   * @return array
   *   - action: "update", "create" or "skip".
   *   - book, edition: the matching nodes, where found.
   *   - reason: why a record is skipped.
   */
  public function plan(array $record, LibrarySourceInterface $source, array $target = []): array {
    if (!empty($target['edition'])) {
      return ['action' => 'update', 'book' => $target['edition']->get('field_novel')->entity, 'edition' => $target['edition']];
    }
    if (!empty($target['book'])) {
      return ['action' => 'create', 'book' => $target['book']];
    }
    if (!in_array($record['kind'], ['book', 'audio'], TRUE)) {
      return ['action' => 'skip', 'reason' => "not a book (\"{$record['kind']}\")"];
    }
    // A library's original title can be misspelt ("All judgement fleed"),
    // so failing that, its translated title is looked for among editions.
    $book = ($record['original'] ? $this->book($record['original']) : NULL) ?? $this->bookByTranslation((string) $record['title'], $source->language());
    $edition = $this->edition($record, $book, $source->language());
    if ($edition) {
      return ['action' => 'update', 'book' => $book, 'edition' => $edition];
    }
    if (!$book) {
      return ['action' => 'skip', 'reason' => $record['original'] ? "original title \"{$record['original']}\" matches no book" : 'no original title'];
    }
    return ['action' => 'create', 'book' => $book];
  }

  /**
   * What applying a record would change: field => the new value as text.
   *
   * Only empty fields are filled (all of them, for a new edition), plus
   * credit lines for records not yet credited.
   *
   * @param \Drupal\node\NodeInterface|null $edition
   *   The edition to fill in, or NULL for a new one.
   */
  public function changes(array $record, LibrarySourceInterface $source, ?NodeInterface $edition): array {
    $changes = [];
    foreach ($this->offers($record, $source) as $field => [$text]) {
      if (!$edition || $edition->get($field)->isEmpty()) {
        $changes[$field] = $text;
      }
    }
    $sources = $edition ? (string) $edition->get('field_data_sources')->value : '';
    $credits = array_filter($this->credits($record, $source), fn($url) => !str_contains($sources, $url), ARRAY_FILTER_USE_KEY);
    if ($credits) {
      $changes['field_data_sources'] = implode("\n", $credits);
    }
    return $changes;
  }

  /**
   * Applies a record: fills in its edition, or creates one.
   *
   * @param array $target
   *   An editor's choice of edition or book; see plan().
   *
   * @return string
   *   "created", "updated", "unchanged" or "skipped".
   */
  public function apply(array $record, LibrarySourceInterface $source, array $target = []): string {
    $plan = $this->plan($record, $source, $target);
    if ($plan['action'] === 'skip') {
      return 'skipped';
    }
    $edition = $plan['edition'] ?? NULL;
    $changes = $this->changes($record, $source, $edition);
    if (!$changes) {
      return 'unchanged';
    }
    $new = !$edition;
    if ($new) {
      $publisher = $record['publisher'] ? $this->term('publisher', $record['publisher']) : NULL;
      $edition = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'edition',
        'title' => implode(', ', array_filter([$record['title'], $publisher?->label(), $record['year']])),
        'uid' => 1,
        'status' => 1,
        'field_novel' => [$plan['book']->id()],
      ]);
    }
    // Terms are only created for the fields actually filled.
    foreach ($this->offers($record, $source) as $field => [, $value]) {
      if (isset($changes[$field])) {
        $edition->set($field, $value instanceof \Closure ? $value() : $value);
      }
    }
    if (isset($changes['field_data_sources'])) {
      $edition->set('field_data_sources', trim($edition->get('field_data_sources')->value . "\n" . $changes['field_data_sources']));
    }
    $edition->setNewRevision(TRUE);
    $edition->setRevisionLogMessage(($new ? 'Created' : 'Filled in') . " from the {$source->label()}: {$record['url']}");
    // A loaded node keeps its last revision's time and author otherwise.
    $edition->setRevisionCreationTime($this->time->getRequestTime());
    $edition->setRevisionUserId((int) $this->currentUser->id());
    $edition->save();
    if ($new) {
      $created = $this->state->get(self::createdKey($source), []);
      $created[(int) $edition->id()] = $record['id'];
      $this->state->set(self::createdKey($source), $created);
    }
    return $new ? 'created' : 'updated';
  }

  /**
   * The values a record offers: field => [text, value], where the value may
   * be a closure (creating terms) worked out only if the field is filled.
   */
  protected function offers(array $record, LibrarySourceInterface $source): array {
    $offers = [
      'field_language' => [$source->language(), fn() => $this->term('language', $source->language())?->id()],
      'field_publisher' => [$record['publisher'], fn() => $this->term('publisher', $record['publisher'])?->id()],
      'field_year' => [$record['year'], $record['year']],
      'field_date_display' => [$record['year'], $record['year']],
      'field_isbn' => [$record['isbn'], $record['isbn']],
      'field_pages' => [$record['pages'], $record['pages']],
      'field_printing' => [$record['printing'], $record['printing']],
    ];
    if ($record['series']) {
      $offers['field_publisher_series'] = [$record['series'], fn() => $this->term('publisher_series', $record['series'])?->id()];
      $offers['field_publisher_series_number'] = [$record['series_number'], $record['series_number']];
    }
    if ($record['kind'] === 'audio') {
      $offers['field_format'] = ['Audio cassette', fn() => $this->term('format', 'Audio cassette')?->id()];
    }
    foreach ($record['people'] as $field => $names) {
      $offers[$field] = [implode('; ', $names), fn() => array_map(fn($name) => $this->term('people', $name)?->id(), $names)];
    }
    return array_filter($offers, fn($offer) => $offer[0] !== NULL && $offer[0] !== '');
  }

  /**
   * Credit lines for every record the values came from, merged duplicates
   * too, keyed by record address.
   */
  protected function credits(array $record, LibrarySourceInterface $source): array {
    $licence = $source->licence() !== '' ? " ({$source->licence()})" : '';
    $credits = [];
    foreach ([$record['url'], ...$record['also'] ?? []] as $url) {
      $credits[$url] = "{$source->label()} record $url$licence.";
    }
    return $credits;
  }

  /**
   * The site's book with this original title, or one of its other titles.
   */
  protected function book(string $original): ?NodeInterface {
    $key = Isfdb::normalizeTitle($original);
    foreach ($this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'novel']) as $book) {
      $titles = [$book->label(), ...array_column($book->get('field_alternative_titles')->getValue(), 'value')];
      foreach ($titles as $title) {
        if (Isfdb::normalizeTitle($title) === $key) {
          return $book;
        }
      }
    }
    return NULL;
  }

  /**
   * The book of an edition in this language with this title, for records
   * that give no original title (the 1991 audiobook of Szpital kosmiczny), or
   * one that matches no book.
   */
  protected function bookByTranslation(string $title, string $language): ?NodeInterface {
    $key = Isfdb::normalizeTitle($title);
    $term = $this->term('language', $language, FALSE);
    if ($key === '' || !$term) {
      return NULL;
    }
    $editions = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'edition', 'field_language' => $term->id()]);
    foreach ($editions as $edition) {
      // Edition titles read "Szpital Kosmiczny, Wydawnictwa Alfa, 1987".
      if (Isfdb::normalizeTitle(explode(', ', $edition->label())[0]) === $key && ($book = $edition->get('field_novel')->entity)) {
        return $book;
      }
    }
    return NULL;
  }

  /**
   * The edition a record describes, if the site has it.
   *
   * One already filled in from this record; else by ISBN, preferring the
   * same year; else the book's edition in this language and year, with the
   * same publisher (or one naming the other) or none, and no ISBN of its own.
   */
  protected function edition(array $record, ?NodeInterface $book, string $language): ?NodeInterface {
    $editions = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'edition']);
    $same_isbn = [];
    foreach ($editions as $edition) {
      foreach ([$record['url'], ...$record['also'] ?? []] as $url) {
        if (str_contains((string) $edition->get('field_data_sources')->value, $url)) {
          return $edition;
        }
      }
      if ($record['isbn'] && Isfdb::isbn13($edition->get('field_isbn')->value) === $record['isbn']) {
        $same_isbn[] = $edition;
      }
    }
    // Later printings keep the ISBN; prefer the one of the same year.
    foreach ($same_isbn as $edition) {
      if ((string) $edition->get('field_year')->value === (string) $record['year']) {
        return $edition;
      }
    }
    if ($same_isbn) {
      return reset($same_isbn);
    }
    if (!$book) {
      return NULL;
    }
    $term = $this->term('language', $language, FALSE);
    foreach ($editions as $edition) {
      if ((int) $edition->get('field_novel')->target_id !== (int) $book->id()
        || (int) $edition->get('field_language')->target_id !== (int) $term?->id()
        || (string) $edition->get('field_year')->value !== (string) $record['year']
        || !$edition->get('field_isbn')->isEmpty()) {
        continue;
      }
      $publisher = $edition->get('field_publisher')->entity;
      if (!$publisher || !$record['publisher'] || $this->samePublisher($publisher->label(), $record['publisher'])) {
        return $edition;
      }
    }
    return NULL;
  }

  /**
   * Whether two publisher names are the same, or one is part of the other
   * ("Pabel", "Erich Pabel Verlag").
   */
  protected function samePublisher(string $a, string $b): bool {
    if (Isfdb::normalizeName($a, 'publisher') === Isfdb::normalizeName($b, 'publisher')) {
      return TRUE;
    }
    $words = fn(string $name) => array_filter(array_map(fn($word) => Isfdb::normalizeName($word, 'publisher'), preg_split('/[\s,\/]+/u', $name)));
    [$a, $b] = [$words($a), $words($b)];
    [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
    return $short && !array_diff($short, $long);
  }

  /**
   * A term by name - matched as the ISFDB import matches them - created if
   * there's none, and recorded as created so the usual tidying applies.
   */
  protected function term(string $vid, string $name, bool $create = TRUE) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $keys = [Isfdb::normalizeTitle($name), Isfdb::normalizeName($name, $vid)];
    foreach ($storage->loadByProperties(['vid' => $vid]) as $term) {
      if (array_intersect($keys, [Isfdb::normalizeTitle($term->label()), Isfdb::normalizeName($term->label(), $vid)])) {
        return $term;
      }
    }
    if (!$create) {
      return NULL;
    }
    $term = $storage->create(['vid' => $vid, 'name' => $name]);
    $term->save();
    $created = $this->state->get('sectorgeneral_isfdb.created_terms', []);
    $created[$vid][(int) $term->id()] = $name;
    $this->state->set('sectorgeneral_isfdb.created_terms', $created);
    return $term;
  }

}
