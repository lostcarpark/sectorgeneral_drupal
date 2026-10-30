<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\sectorgeneral_isfdb\Library\LibrarySourceInterface;
use Drupal\sectorgeneral_isfdb\Library\LibrarySources;

/**
 * National library records: stored raw, reviewed, then applied.
 *
 * fetch() is the only step that contacts a library. It stores each record
 * exactly as the library sent it; a record that has changed since is stored
 * as a new version, and the old one kept. Everything else works from the
 * stored records, so parsing and matching can be changed and re-run without
 * fetching again.
 *
 * review() parses the stored records afresh and works out, for each, what
 * applying it would change. An editor approves or rejects each record, and
 * may point it at another edition, or at a book to add a new edition to;
 * those decisions are kept apart from the records, so re-parsing never loses
 * them. applyApproved() applies only approved records. After a record is
 * applied its approval is cleared, so anything new a later fetch brings is
 * reviewed again.
 */
class LibraryRecords {

  public const RAW = 'sectorgeneral_library_raw';

  public const REVIEW = 'sectorgeneral_library_review';

  /**
   * Statuses, in the order the review lists them.
   */
  public const STATUSES = [
    'pending' => 'To review',
    'approved' => 'Approved, to apply',
    'skipped' => 'Needs a match',
    'rejected' => 'Rejected',
    'done' => 'Up to date',
  ];

  public function __construct(
    protected Connection $database,
    protected LibrarySources $sources,
    protected LibraryImporter $importer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Fetches a library's records and stores them.
   *
   * @return int[]
   *   Counts: new, changed, unchanged, and gone (stored, but no longer
   *   returned; kept).
   */
  public function fetch(LibrarySourceInterface $source): array {
    $raw = $source->fetch();
    $now = $this->time->getRequestTime();
    $current = $this->database->select(self::RAW, 'r')
      ->fields('r', ['id', 'record_id', 'hash'])
      ->condition('source', $source->id())
      ->condition('current', 1)
      ->execute()
      ->fetchAllAssoc('record_id');
    $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0];
    $transaction = $this->database->startTransaction();
    foreach ($raw as $record_id => $data) {
      $record_id = (string) $record_id;
      $hash = sha1($data);
      $row = $current[$record_id] ?? NULL;
      if ($row && $row->hash === $hash) {
        $this->database->update(self::RAW)->fields(['checked' => $now])->condition('id', $row->id)->execute();
        $counts['unchanged']++;
        continue;
      }
      if ($row) {
        $this->database->update(self::RAW)->fields(['current' => 0])->condition('id', $row->id)->execute();
      }
      $this->database->insert(self::RAW)->fields([
        'source' => $source->id(),
        'record_id' => $record_id,
        'data' => $data,
        'hash' => $hash,
        'fetched' => $now,
        'checked' => $now,
        'current' => 1,
      ])->execute();
      $counts[$row ? 'changed' : 'new']++;
    }
    $counts['gone'] = count(array_diff_key($current, $raw));
    return $counts;
  }

  /**
   * A library's stored records, current versions: record number => data.
   */
  public function raw(LibrarySourceInterface $source): array {
    return $this->database->select(self::RAW, 'r')
      ->fields('r', ['record_id', 'data'])
      ->condition('source', $source->id())
      ->condition('current', 1)
      ->orderBy('record_id')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * When a library's records were last fetched, or NULL if never.
   */
  public function lastFetched(LibrarySourceInterface $source): ?int {
    $time = $this->database->select(self::RAW, 'r')->condition('source', $source->id());
    $time->addExpression('MAX(checked)');
    return ((int) $time->execute()->fetchField()) ?: NULL;
  }

  /**
   * Every stored record, parsed, with what applying it would do.
   *
   * @param string|null $source_id
   *   One library only.
   *
   * @return array[]
   *   Keyed by row key ("sbn:3f2a…"), each with: key, source, record,
   *   plan (see LibraryImporter::plan()), changes (field => new value as
   *   text), decision (the review row, or NULL) and status (a STATUSES key).
   */
  public function review(?string $source_id = NULL): array {
    $decisions = [];
    $query = $this->database->select(self::REVIEW, 'v')->fields('v');
    if ($source_id) {
      $query->condition('source', $source_id);
    }
    foreach ($query->execute() as $decision) {
      $decisions["$decision->source $decision->url"] = $decision;
    }
    $rows = [];
    foreach ($this->sources->all() as $id => $source) {
      if ($source_id && $id !== $source_id) {
        continue;
      }
      $raw = $this->raw($source);
      foreach ($raw ? $source->parse($raw) : [] as $record) {
        // Merged duplicates can change which record leads; a decision made
        // under any of them stands.
        $decision = NULL;
        foreach ([$record['url'], ...$record['also'] ?? []] as $url) {
          if (isset($decisions["$id $url"])) {
            $decision = $decisions["$id $url"];
            break;
          }
        }
        $plan = $this->importer->plan($record, $source, $this->target($decision));
        $changes = $plan['action'] === 'skip' ? [] : $this->importer->changes($record, $source, $plan['edition'] ?? NULL);
        $status = match (TRUE) {
          $decision?->decision === 'rejected' => 'rejected',
          $plan['action'] === 'skip' => 'skipped',
          !$changes => 'done',
          $decision?->decision === 'approved' => 'approved',
          default => 'pending',
        };
        $key = $id . ':' . substr(sha1($record['url']), 0, 12);
        $rows[$key] = [
          'key' => $key,
          'source' => $source,
          'record' => $record,
          'plan' => $plan,
          'changes' => $changes,
          'decision' => $decision,
          'status' => $status,
        ];
      }
    }
    return $rows;
  }

  /**
   * One review row by its key, or NULL.
   */
  public function row(string $key): ?array {
    return $this->review(explode(':', $key)[0])[$key] ?? NULL;
  }

  /**
   * Records an editor's decision on a row.
   *
   * @param string|null $decision
   *   "approved", "rejected", or NULL to leave it for review.
   * @param int|null $edition
   *   An edition to fill in instead of the match.
   * @param int|null $book
   *   A book to add a new edition to instead.
   */
  public function decide(array $row, ?string $decision, ?int $edition = NULL, ?int $book = NULL): void {
    $this->database->merge(self::REVIEW)
      ->keys([
        'source' => $row['source']->id(),
        // A decision already made under a merged duplicate stays there.
        'url' => $row['decision']->url ?? $row['record']['url'],
      ])
      ->fields([
        'decision' => $decision,
        'target_edition' => $edition,
        'target_book' => $book,
        'uid' => (int) $this->currentUser->id(),
        'changed' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Sets a row's decision, keeping any edition or book chosen for it.
   */
  public function setDecision(array $row, ?string $decision): void {
    $edition = $row['decision']->target_edition ?? NULL;
    $book = $row['decision']->target_book ?? NULL;
    $this->decide($row, $decision, $edition === NULL ? NULL : (int) $edition, $book === NULL ? NULL : (int) $book);
  }

  /**
   * Applies every approved record.
   *
   * @return string[]
   *   Results by row key: "created", "updated", "unchanged" or "skipped".
   */
  public function applyApproved(?string $source_id = NULL): array {
    $results = [];
    foreach ($this->review($source_id) as $key => $row) {
      if ($row['status'] === 'approved') {
        $results[$key] = $this->apply($row);
      }
    }
    return $results;
  }

  /**
   * Applies one approved row.
   *
   * @return string
   *   "created", "updated", "unchanged" or "skipped".
   */
  public function apply(array $row): string {
    $result = $this->importer->apply($row['record'], $row['source'], $this->target($row['decision']));
    // Approval covers what was reviewed; anything newer is reviewed again.
    // A new edition is found by its credit from now on, so the book it was
    // added to no longer needs choosing.
    $this->database->update(self::REVIEW)
      ->fields(['decision' => NULL, 'target_book' => NULL, 'applied' => $this->time->getRequestTime()])
      ->condition('source', $row['decision']->source)
      ->condition('url', $row['decision']->url)
      ->execute();
    return $result;
  }

  /**
   * Changes keyed by the edition fields' labels.
   */
  public function labelled(array $changes): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'edition');
    $labelled = [];
    foreach ($changes as $field => $text) {
      $labelled[(string) ($definitions[$field]?->getLabel() ?? $field)] = $text;
    }
    return $labelled;
  }

  /**
   * The editor's choice of edition or book, for LibraryImporter::plan().
   */
  protected function target(?object $decision): array {
    $storage = $this->entityTypeManager->getStorage('node');
    if (!empty($decision->target_edition) && ($edition = $storage->load($decision->target_edition))) {
      return ['edition' => $edition];
    }
    if (!empty($decision->target_book) && ($book = $storage->load($decision->target_book))) {
      return ['book' => $book];
    }
    return [];
  }

}
