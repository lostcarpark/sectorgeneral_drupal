<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\sectorgeneral_isfdb\CoverFetcher;
use Drupal\sectorgeneral_isfdb\DuplicateFinder;
use Drupal\sectorgeneral_isfdb\Isfdb;
use Drupal\sectorgeneral_isfdb\Library\LibrarySources;
use Drupal\sectorgeneral_isfdb\LibraryRecords;
use Drupal\sectorgeneral_isfdb\Merger;
use Drupal\sectorgeneral_isfdb\OpenLibrary;
use Drupal\taxonomy\Entity\Term;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Filesystem\Path;

/**
 * Helpers for running the ISFDB import.
 */
final class IsfdbCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Tables and columns the import reads.
   */
  public const REQUIRED = [
    'authors' => ['author_id', 'author_canonical'],
    'titles' => ['title_id', 'title_title', 'title_copyright', 'title_ttype', 'title_parent', 'title_language', 'series_id', 'title_seriesnum', 'note_id'],
    'canonical_author' => ['ca_id', 'title_id', 'author_id', 'ca_status'],
    'pubs' => ['pub_id', 'pub_title', 'pub_year', 'pub_ptype', 'pub_ctype', 'pub_isbn', 'pub_price', 'pub_pages', 'pub_catalog', 'pub_series_id', 'pub_series_num', 'publisher_id', 'pub_frontimage', 'note_id'],
    'pub_content' => ['pubc_id', 'pub_id', 'title_id', 'pubc_page'],
    'publishers' => ['publisher_id', 'publisher_name'],
    'pub_series' => ['pub_series_id', 'pub_series_name'],
    'series' => ['series_id', 'series_title'],
    'languages' => ['lang_id', 'lang_name'],
    'notes' => ['note_id', 'note_note'],
    'identifiers' => ['identifier_type_id', 'identifier_value', 'pub_id'],
    'identifier_types' => ['identifier_type_id', 'identifier_type_name'],
  ];

  public function __construct(
    protected MigrationPluginManagerInterface $migrationManager,
    protected Merger $merger,
    protected DuplicateFinder $duplicateFinder,
    protected CoverFetcher $coverFetcher,
    protected OpenLibrary $openLibrary,
    protected LibrarySources $librarySources,
    protected LibraryRecords $libraryRecords,
  ) {
    parent::__construct();
  }

  /**
   * Checks the ISFDB database copy has the tables and columns the import uses.
   */
  #[CLI\Command(name: 'sg-isfdb:check')]
  #[CLI\Usage(name: 'drush sg-isfdb:check', description: 'Check the "isfdb" database connection.')]
  public function check(): int {
    try {
      $db = Database::getConnection('default', 'isfdb');
    }
    catch (\Throwable $e) {
      $this->logger()->error('No "isfdb" database connection. See the module README: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }
    $missing = [];
    foreach (self::REQUIRED as $table => $columns) {
      // Braces let Drupal apply any table prefix; check the table exists first.
      if (!$db->schema()->tableExists($table)) {
        $missing[] = "table $table";
        continue;
      }
      $have = $this->columns($db, $table);
      foreach (array_diff($columns, $have) as $column) {
        $missing[] = "$table.$column";
      }
    }
    if ($missing) {
      $this->logger()->error('Missing from the ISFDB copy: ' . implode(', ', $missing));
      return self::EXIT_FAILURE;
    }
    $author = (int) \Drupal::config('sectorgeneral_isfdb.settings')->get('author_id');
    $this->logger()->success('The ISFDB copy has every table and column the import uses.');
    if ($author <= 0) {
      $this->logger()->warning('Author ID not set yet: drush sg-isfdb:find-author "James White"');
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Column names of a table, honouring the connection's table prefix.
   */
  private function columns($db, string $table): array {
    return $db->query('SHOW COLUMNS FROM {' . $table . '}')->fetchCol();
  }

  /**
   * Lists ISFDB authors matching a name, to choose the author ID.
   */
  #[CLI\Command(name: 'sg-isfdb:find-author')]
  #[CLI\Argument(name: 'name', description: 'Author name, e.g. "James White".')]
  #[CLI\Usage(name: 'drush sg-isfdb:find-author "James White"', description: 'Then: drush cset sectorgeneral_isfdb.settings author_id <ID>')]
  public function findAuthor(string $name): void {
    $db = Database::getConnection('default', 'isfdb');
    $rows = $db->query(
      'SELECT a.author_id, a.author_canonical, a.author_birthdate, COUNT(ca.title_id) AS titles
       FROM {authors} a LEFT JOIN {canonical_author} ca ON ca.author_id = a.author_id
       WHERE a.author_canonical LIKE :name GROUP BY a.author_id ORDER BY titles DESC',
      [':name' => '%' . $db->escapeLike($name) . '%'],
    )->fetchAll();
    foreach ($rows as $row) {
      $this->output()->writeln(sprintf('%8d  %-40s born %-10s  %d titles', $row->author_id, html_entity_decode($row->author_canonical), $row->author_birthdate, $row->titles));
    }
  }

  /**
   * Writes a Markdown report of what the import did and what to review.
   */
  #[CLI\Command(name: 'sg-isfdb:report')]
  #[CLI\Option(name: 'file', description: 'Where to write the report.')]
  #[CLI\Usage(name: 'drush sg-isfdb:report --file=../scripts/isfdb-report.md', description: 'Write the report.')]
  public function report(array $options = ['file' => 'isfdb-report.md']): void {
    $lines = ['# ISFDB import report', '', 'Bibliographic data from the ISFDB (https://www.isfdb.org), licensed CC BY 4.0.', ''];
    $migrations = $this->migrationManager->createInstancesByTag('isfdb');
    $conflicts = [];
    $skipped = [];
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '| Migration | Created | Filled in existing | Skipped or failed |';
    $lines[] = '|---|---|---|---|';
    foreach ($migrations as $migration) {
      $map = $migration->getIdMap();
      $created = $updated = $failed = 0;
      if ($map instanceof Sql && $map->getDatabase()->schema()->tableExists($map->mapTableName())) {
        $rows = $map->getDatabase()->select($map->mapTableName(), 'm')->fields('m', ['source_row_status', 'rollback_action'])->execute();
        foreach ($rows as $row) {
          if ((int) $row->source_row_status !== MigrateIdMapInterface::STATUS_IMPORTED) {
            $failed++;
          }
          elseif ((int) $row->rollback_action === MigrateIdMapInterface::ROLLBACK_PRESERVE) {
            $updated++;
          }
          else {
            $created++;
          }
        }
      }
      $lines[] = "| {$migration->label()} | $created | $updated | $failed |";
      foreach ($map->getMessages() as $message) {
        if (str_starts_with($message->message, 'Conflict:')) {
          $conflicts[] = substr($message->message, 10);
        }
        else {
          $skipped[] = "{$migration->label()}: {$message->message}";
        }
      }
    }
    $lines = array_merge($lines, ['', '## Conflicts to review (' . count($conflicts) . ')', '',
      'Drupal kept its own value in each case. Change it on the site if ISFDB is right.', '']);
    $lines = array_merge($lines, array_map(fn($c) => "- $c", $conflicts));
    $created = \Drupal::state()->get('sectorgeneral_isfdb.created_terms', []);
    $lines = array_merge($lines, ['', '## New terms', '', 'Names that matched no existing term. If one is a duplicate under another spelling, add an alias in sectorgeneral_isfdb.settings and merge the terms.', '']);
    foreach ($created as $vid => $terms) {
      $lines[] = "- **$vid**: " . implode('; ', $terms);
    }
    if ($skipped) {
      $lines = array_merge($lines, ['', '## Other messages', ''], array_map(fn($s) => "- $s", $skipped));
    }
    // Drush runs from the Drupal root; resolve the path from where the
    // command was typed instead.
    $file = Path::makeAbsolute($options['file'], $this->getConfig()->cwd());
    if (file_put_contents($file, implode("\n", $lines) . "\n") === FALSE) {
      throw new \RuntimeException("Could not write the report to $file.");
    }
    $this->logger()->success(sprintf('Report written to %s: %d conflicts.', $file, count($conflicts)));
  }

  /**
   * Deletes terms the import created that nothing uses any more.
   *
   * Run after rolling back (drush mr --tag=isfdb): rollback removes imported
   * content but not the publishers, people and so on created along the way.
   */
  #[CLI\Command(name: 'sg-isfdb:cleanup-terms')]
  #[CLI\Usage(name: 'drush mr --tag=isfdb && drush sg-isfdb:cleanup-terms', description: 'Roll back, then remove the unused terms.')]
  public function cleanupTerms(): void {
    $deleted = $this->merger->deleteUnusedCreatedTerms();
    $this->logger()->success(sprintf('Deleted %d unused terms created by the import.', count($deleted)));
  }

  /**
   * Lists likely duplicates left by the import, by confidence.
   *
   * High-confidence pairs can be merged together with sg-isfdb:merge-confident;
   * medium and low ones need checking first. Editions sharing an ISBN are
   * listed separately as printings, and editions from different publishers
   * are not listed: they are different editions.
   */
  #[CLI\Command(name: 'sg-isfdb:duplicates')]
  #[CLI\Option(name: 'file', description: 'Also write the list to this Markdown file.')]
  #[CLI\Usage(name: 'drush sg-isfdb:duplicates --file=scripts/isfdb-duplicates.md', description: 'List likely duplicates.')]
  public function duplicates(array $options = ['file' => NULL]): void {
    $pairs = $this->duplicateFinder->find();
    $sections = [
      'high' => ['High confidence', 'Merge these together with `drush sg-isfdb:merge-confident` (try `--dry-run` first), or one by one.'],
      'medium' => ['Medium confidence', 'Probably the same, but check each before merging.'],
      'low' => ['Low confidence', 'Probably different; merge only if you know they are the same.'],
      'printing' => ['Printings sharing an ISBN', 'ISFDB lists each printing separately; these are kept as separate editions. Merge only if one is a mistake.'],
    ];
    $lines = ['# Likely duplicates after the ISFDB import', '',
      'Each pair is existing content (kept) and imported content (merged into it). A merge keeps the first item\'s values.', ''];
    foreach ($sections as $level => [$heading, $help]) {
      $in = array_filter($pairs, fn($p) => $p['level'] === $level);
      $lines[] = sprintf('## %s (%d)', $heading, count($in));
      $lines[] = '';
      $lines[] = $help;
      $lines[] = '';
      usort($in, fn($a, $b) => [$a['section'], $a['keep']] <=> [$b['section'], $b['keep']]);
      foreach ($in as $p) {
        $lines[] = sprintf('- %s: keep %d %s ← merge %d %s. *%s.* `drush sg-isfdb:merge-%s %d %d`',
          $p['section'], $p['keep'], $p['keep_label'], $p['dup'], $p['dup_label'], implode(', ', $p['reasons']), $p['kind'], $p['keep'], $p['dup']);
      }
      $lines[] = '';
    }
    $this->output()->writeln($lines);
    if ($options['file']) {
      $file = Path::makeAbsolute($options['file'], $this->getConfig()->cwd());
      if (file_put_contents($file, implode("\n", $lines) . "\n") === FALSE) {
        throw new \RuntimeException("Could not write $file.");
      }
    }
    $counts = array_count_values(array_column($pairs, 'level'));
    $this->logger()->success(sprintf('%d high, %d medium, %d low confidence; %d printings sharing an ISBN.',
      $counts['high'] ?? 0, $counts['medium'] ?? 0, $counts['low'] ?? 0, $counts['printing'] ?? 0));
  }

  /**
   * Merges every high-confidence duplicate pair.
   */
  #[CLI\Command(name: 'sg-isfdb:merge-confident')]
  #[CLI\Option(name: 'dry-run', description: 'List the pairs without merging.')]
  #[CLI\Usage(name: 'drush sg-isfdb:merge-confident --dry-run', description: 'See what would be merged.')]
  public function mergeConfident(array $options = ['dry-run' => FALSE]): void {
    $pairs = array_values(array_filter($this->duplicateFinder->find(), fn($p) => $p['level'] === 'high'));
    foreach ($pairs as $p) {
      $this->output()->writeln(sprintf('%s: keep %d %s ← merge %d %s', $p['section'], $p['keep'], $p['keep_label'], $p['dup'], $p['dup_label']));
    }
    if (!$pairs || $options['dry-run'] || !$this->io()->confirm(sprintf('Merge these %d pairs?', count($pairs)))) {
      $this->logger()->notice(sprintf('%d high-confidence pairs; nothing merged.', count($pairs)));
      return;
    }
    $done = 0;
    foreach ($pairs as $p) {
      $load = $p['kind'] === 'nodes' ? [Node::class, 'load'] : [Term::class, 'load'];
      $keep = $load($p['keep']);
      $dup = $load($p['dup']);
      // An earlier merge in this run may already have removed one of them.
      if (!$keep || !$dup) {
        continue;
      }
      $p['kind'] === 'nodes' ? $this->merger->mergeNodes($keep, $dup) : $this->merger->mergeTerms($keep, $dup);
      $done++;
    }
    $this->logger()->success("Merged $done pairs.");
  }

  /**
   * Merges an imported node into existing content, keeping the existing one.
   */
  #[CLI\Command(name: 'sg-isfdb:merge-nodes')]
  #[CLI\Argument(name: 'keep', description: 'Node ID to keep.')]
  #[CLI\Argument(name: 'duplicate', description: 'Node ID to merge into it and delete.')]
  #[CLI\Option(name: 'any-type', description: 'Allow a node of another type, e.g. a book that should be a story.')]
  #[CLI\Usage(name: 'drush sg-isfdb:merge-nodes 264 626 --any-type', description: 'Merge book 626 into story 264.')]
  public function mergeNodes(int $keep, int $duplicate, array $options = ['any-type' => FALSE]): void {
    $a = Node::load($keep);
    $b = Node::load($duplicate);
    if (!$a || !$b || $keep === $duplicate) {
      throw new \InvalidArgumentException('Give two different, existing node IDs.');
    }
    if (!$this->io()->confirm("Merge {$b->bundle()} {$duplicate} \"{$b->label()}\" into {$keep} \"{$a->label()}\" and delete {$duplicate}?")) {
      return;
    }
    $this->output()->writeln($this->merger->mergeNodes($a, $b, (bool) $options['any-type']));
    $this->logger()->success("Merged into node $keep.");
  }

  /**
   * Merges a duplicate term into another, keeping the first one.
   */
  #[CLI\Command(name: 'sg-isfdb:merge-terms')]
  #[CLI\Argument(name: 'keep', description: 'Term ID to keep.')]
  #[CLI\Argument(name: 'duplicate', description: 'Term ID to merge into it and delete.')]
  public function mergeTerms(int $keep, int $duplicate): void {
    $a = Term::load($keep);
    $b = Term::load($duplicate);
    if (!$a || !$b || $keep === $duplicate) {
      throw new \InvalidArgumentException('Give two different, existing term IDs.');
    }
    if (!$this->io()->confirm("Merge {$b->bundle()} term {$duplicate} \"{$b->label()}\" into {$keep} \"{$a->label()}\" and delete {$duplicate}?")) {
      return;
    }
    $this->output()->writeln($this->merger->mergeTerms($a, $b));
    $this->logger()->success("Merged into term $keep.");
  }

  /**
   * Adds cover images from ISFDB, or replaces covers with larger ones.
   *
   * Reads image addresses from the local ISFDB copy and downloads only the
   * image files. An image already in the media library, by address or by
   * fingerprint, is reused rather than added again.
   */
  #[CLI\Command(name: 'sg-isfdb:covers')]
  #[CLI\Option(name: 'type', description: 'Content types to check, comma separated.')]
  #[CLI\Option(name: 'limit', description: 'Check at most this many items (0 for all).')]
  #[CLI\Option(name: 'dry-run', description: 'Count what would be checked, without downloading.')]
  #[CLI\Usage(name: 'drush sg-isfdb:covers --limit=10', description: 'Try it on ten items first.')]
  public function covers(array $options = ['type' => 'edition,magazine_issue,anthology', 'limit' => 0, 'dry-run' => FALSE]): void {
    $fingerprinted = $this->coverFetcher->fingerprintLibrary();
    if ($fingerprinted) {
      $this->logger()->notice("Fingerprinted $fingerprinted existing cover images.");
    }
    $candidates = $this->coverFetcher->candidates(array_map('trim', explode(',', $options['type'])), (int) $options['limit']);
    $without = count(array_filter($candidates, fn($c) => $c['node']->get('field_cover')->isEmpty()));
    $this->logger()->notice(sprintf('%d items have an ISFDB image: %d without a cover, %d to compare with their current cover.',
      count($candidates), $without, count($candidates) - $without));
    if ($options['dry-run']) {
      return;
    }
    $results = [];
    $failures = [];
    $this->io()->progressStart(count($candidates));
    foreach ($candidates as $c) {
      try {
        $result = $this->coverFetcher->process($c['node'], $c['url'], 'ISFDB', Isfdb::url('publication', $c['pub_id']));
      }
      catch (\Throwable $e) {
        $result = 'failed';
        $failures[] = sprintf('node/%d: %s', $c['node']->id(), $e->getMessage());
      }
      $results[$result] = ($results[$result] ?? 0) + 1;
      $this->io()->progressAdvance();
    }
    $this->io()->progressFinish();
    $books = $this->coverFetcher->fillBookCovers();
    foreach ($failures as $failure) {
      $this->logger()->warning($failure);
    }
    $this->logger()->success(sprintf('Added %d new images, reused %d already in the library, replaced %d smaller covers; kept %d existing covers that were as large or larger, %d already had this image; %d failed. %d books took their earliest edition\'s cover.',
      $results['added'] ?? 0, $results['reused'] ?? 0, $results['replaced'] ?? 0, $results['kept'] ?? 0, $results['current'] ?? 0, $results['failed'] ?? 0, $books));
  }

  /**
   * Undoes sg-isfdb:covers: restores previous covers, removes added images.
   */
  #[CLI\Command(name: 'sg-isfdb:covers-undo')]
  public function coversUndo(): void {
    if (!$this->io()->confirm('Restore every cover sg-isfdb:covers changed, and delete the images it added?')) {
      return;
    }
    [$restored, $deleted] = $this->coverFetcher->undo();
    $this->logger()->success("Restored $restored covers and deleted $deleted images.");
  }

  /**
   * Switches covers to larger copies of the same picture.
   *
   * Compares the covers within each book's family (the book and its
   * editions) and, where one is a larger copy of another, uses the larger one.
   * Undo with sg-isfdb:covers-undo.
   */
  #[CLI\Command(name: 'sg-isfdb:upgrade-covers')]
  #[CLI\Option(name: 'threshold', description: 'How alike the pattern of light and dark must be, from 0 to 1.')]
  #[CLI\Option(name: 'edge-threshold', description: 'How alike the edges must be, from 0 to 1. Fading barely affects edges, so this tells a faded copy from a different cover.')]
  #[CLI\Option(name: 'dry-run', description: 'List the changes without making them.')]
  #[CLI\Usage(name: 'drush sg-isfdb:upgrade-covers --dry-run', description: 'See what would change.')]
  public function upgradeCovers(array $options = ['threshold' => 0.75, 'edge-threshold' => 0.7, 'dry-run' => FALSE]): void {
    $changes = $this->coverFetcher->upgradeSimilar((float) $options['threshold'], (bool) $options['dry-run'], (float) $options['edge-threshold']);
    $size = fn($m) => $m->get('field_media_image')->width . 'x' . $m->get('field_media_image')->height;
    foreach ($changes as $c) {
      $this->output()->writeln(sprintf('node %d "%s": media %d (%s) → media %d (%s); brightness %.2f, edges %.2f',
        $c['node']->id(), $c['node']->label(), $c['from']->id(), $size($c['from']), $c['to']->id(), $size($c['to']), $c['similarity']['brightness'], $c['similarity']['edges']));
    }
    $this->logger()->success(sprintf($options['dry-run'] ? '%d covers would be switched to a larger copy.' : 'Switched %d covers to a larger copy.', count($changes)));
  }

  /**
   * Fetches national library records into the record store.
   *
   * The only library command that contacts a library. Sources: "bn", the
   * Polish National Library (data.bn.org.pl, CC0), and the Z39.50 catalogues
   * in the z3950 settings: "sbn", Italy's OPAC SBN; "nkp", the Czech
   * National Library (CC0). Requests are spaced as each library's settings
   * say. Each record is stored as the library sent it; a changed record is
   * stored as a new version. Nothing on the site changes: review the records
   * at /admin/content/library-records or with sg-isfdb:library.
   */
  #[CLI\Command(name: 'sg-isfdb:library-fetch')]
  #[CLI\Argument(name: 'source', description: 'Which library: bn (Poland), sbn (Italy), nkp (Czech Republic), or another z3950 settings entry. All if omitted.')]
  #[CLI\Usage(name: 'drush sg-isfdb:library-fetch nkp', description: 'Fetch the Czech National Library\'s records.')]
  public function libraryFetch(string $source = ''): void {
    foreach ($source ? [$this->librarySources->get($source)] : $this->librarySources->all() as $library) {
      $counts = $this->libraryRecords->fetch($library);
      $this->logger()->success(sprintf('%s: %d new, %d changed, %d unchanged; %d stored records no longer found (kept).', $library->label(), $counts['new'], $counts['changed'], $counts['unchanged'], $counts['gone']));
    }
  }

  /**
   * Lists stored national library records and what applying each would do.
   *
   * Works from the record store only. A book record whose original title
   * matches one of the site's books fills in its edition - matched by ISBN,
   * by an earlier run, or by book, language and year - or becomes a new
   * edition. Only empty fields are filled; each edition credits its library
   * record. With --apply, applies the records an editor has approved (see
   * sg-isfdb:library-decide or /admin/content/library-records).
   */
  #[CLI\Command(name: 'sg-isfdb:library')]
  #[CLI\Argument(name: 'source', description: 'Which library: bn, sbn, nkp, ... All if omitted.')]
  #[CLI\Option(name: 'apply', description: 'Apply the approved records.')]
  #[CLI\Option(name: 'status', description: 'List only records with this status: pending, approved, skipped, rejected, done.')]
  #[CLI\Usage(name: 'drush sg-isfdb:library sbn', description: 'See what OPAC SBN has that the site lacks.')]
  #[CLI\Usage(name: 'drush sg-isfdb:library --apply', description: 'Apply every approved record.')]
  public function library(string $source = '', array $options = ['apply' => FALSE, 'status' => '']): void {
    if ($source) {
      $this->librarySources->get($source);
    }
    if ($options['apply']) {
      $results = $this->libraryRecords->applyApproved($source ?: NULL);
      $counts = array_count_values($results);
      ksort($counts);
      $this->logger()->success($results ? 'Applied: ' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)) . '.' : 'No approved records to apply.');
    }
    $rows = [];
    $counts = [];
    foreach ($this->libraryRecords->review($source ?: NULL) as $key => $row) {
      $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
      if ($options['status'] && $row['status'] !== $options['status']) {
        continue;
      }
      $plan = $row['plan'];
      $rows[] = [
        $key,
        $row['record']['year'],
        $row['record']['title'],
        ($plan['book'] ?? NULL)?->label() ?? '',
        LibraryRecords::STATUSES[$row['status']],
        match (TRUE) {
          isset($plan['edition']) => 'node/' . $plan['edition']->id(),
          $plan['action'] === 'create' => 'new edition',
          default => $plan['reason'] ?? '',
        },
        implode(', ', array_keys($this->libraryRecords->labelled($row['changes']))),
      ];
    }
    usort($rows, fn($a, $b) => [explode(':', $a[0])[0], $a[1], $a[2]] <=> [explode(':', $b[0])[0], $b[1], $b[2]]);
    $this->io()->table(['Key', 'Year', 'Title', 'Book', 'Status', 'Edition / note', 'Would fill'], $rows);
    $this->logger()->success(implode(', ', array_map(fn($status) => sprintf('%d %s', $counts[$status], strtolower(LibraryRecords::STATUSES[$status])), array_keys(array_intersect_key(LibraryRecords::STATUSES, $counts)))) . '.');
  }

  /**
   * Approves or rejects stored national library records, by key.
   *
   * Keys are as sg-isfdb:library lists them ("sbn:3f2a…"); "pending" puts a
   * record back for review. --edition or --book point a record at another
   * edition to fill in, or a book to add a new edition to.
   */
  #[CLI\Command(name: 'sg-isfdb:library-decide')]
  #[CLI\Argument(name: 'decision', description: 'approve, reject or pending.')]
  #[CLI\Argument(name: 'keys', description: 'Record keys, or "all-pending" for every record awaiting review.')]
  #[CLI\Option(name: 'edition', description: 'Node ID of an edition to fill in instead of the match.')]
  #[CLI\Option(name: 'book', description: 'Node ID of a book to add a new edition to instead.')]
  #[CLI\Usage(name: 'drush sg-isfdb:library-decide approve nkp:1a2b3c4d5e6f', description: 'Approve one record.')]
  public function libraryDecide(string $decision, array $keys, array $options = ['edition' => NULL, 'book' => NULL]): void {
    $value = ['approve' => 'approved', 'reject' => 'rejected', 'pending' => NULL];
    if (!array_key_exists($decision, $value)) {
      throw new \InvalidArgumentException('Decide "approve", "reject" or "pending".');
    }
    $review = $this->libraryRecords->review();
    if ($keys === ['all-pending']) {
      $keys = array_keys(array_filter($review, fn($row) => $row['status'] === 'pending'));
    }
    foreach ($keys as $key) {
      $row = $review[$key] ?? throw new \InvalidArgumentException("No record \"$key\"; see drush sg-isfdb:library.");
      if ($options['edition'] || $options['book']) {
        $this->libraryRecords->decide($row, $value[$decision], $options['edition'] ? (int) $options['edition'] : NULL, $options['book'] ? (int) $options['book'] : NULL);
      }
      else {
        $this->libraryRecords->setDecision($row, $value[$decision]);
      }
      $this->logger()->success(sprintf('%s: %s (%s).', $key, $decision, $row['record']['title']));
    }
  }

  /**
   * Fills in editions from Open Library by ISBN: record ID, pages, OCLC
   * number, and the cover if the edition has none or a smaller one.
   *
   * Requests are spaced by openlibrary_delay seconds (default 10, as Open
   * Library's robots.txt asks). Cover changes are undone with
   * sg-isfdb:covers-undo; filled-in details through each edition's revisions.
   */
  #[CLI\Command(name: 'sg-isfdb:openlibrary')]
  #[CLI\Option(name: 'limit', description: 'Look up at most this many editions (0 for all).')]
  #[CLI\Option(name: 'recheck', description: 'Also look up editions checked before.')]
  #[CLI\Option(name: 'dry-run', description: 'Count the editions to look up, without contacting Open Library.')]
  #[CLI\Usage(name: 'drush sg-isfdb:openlibrary --limit=5', description: 'Try five editions first.')]
  public function openLibrary(array $options = ['limit' => 0, 'recheck' => FALSE, 'dry-run' => FALSE]): void {
    $this->coverFetcher->fingerprintLibrary();
    $editions = $this->openLibrary->candidates((bool) $options['recheck'], (int) $options['limit']);
    $delay = (int) \Drupal::config('sectorgeneral_isfdb.settings')->get('openlibrary_delay');
    $this->logger()->notice(sprintf('%d editions with an ISBN to look up (about %d minutes at %d seconds between requests).',
      count($editions), ceil(count($editions) * 1.5 * $delay / 60), $delay));
    if ($options['dry-run'] || !$editions) {
      return;
    }
    $results = [];
    $failures = [];
    $this->io()->progressStart(count($editions));
    foreach ($editions as $edition) {
      try {
        $outcome = $this->openLibrary->process($edition);
      }
      catch (\Throwable $e) {
        $outcome = ['failed'];
        $failures[] = sprintf('node/%d: %s', $edition->id(), $e->getMessage());
      }
      foreach ($outcome as $result) {
        $results[$result] = ($results[$result] ?? 0) + 1;
      }
      $this->io()->progressAdvance();
    }
    $this->io()->progressFinish();
    $books = $this->coverFetcher->fillBookCovers();
    foreach ($failures as $failure) {
      $this->logger()->warning($failure);
    }
    $this->logger()->success(sprintf('Found %d of %d editions; filled in details on %d. Covers: %d added, %d reused from the library, %d replaced smaller ones, %d kept larger existing ones, %d already had it. %d failed. %d books took their earliest edition\'s cover.',
      count($editions) - ($results['not found'] ?? 0) - ($results['failed'] ?? 0), count($editions), $results['details'] ?? 0,
      $results['added'] ?? 0, $results['reused'] ?? 0, $results['replaced'] ?? 0, $results['kept'] ?? 0, $results['current'] ?? 0,
      $results['failed'] ?? 0, $books));
  }

  /**
   * Unpublishes single "Magazine serial" editions now listed issue by issue.
   *
   * A serial entry is retired when issues of the same magazine (by reduced
   * name) containing the same book, or a story of the same title, exist
   * within two years of it. Entries with no such issues stay published. An
   * entry the ISFDB import wrongly filled in from a book publication is first
   * reverted to its earlier revision and that publication released, so the
   * next import gives it its own edition.
   */
  #[CLI\Command(name: 'sg-isfdb:retire-serial-editions')]
  #[CLI\Option(name: 'dry-run', description: 'List what would be retired, without changing anything.')]
  public function retireSerialEditions(array $options = ['dry-run' => FALSE]): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $format = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'format', 'name' => 'Magazine serial']);
    if (!$format) {
      return;
    }
    $same_magazine = function (string $a, string $b): bool {
      $a = Isfdb::normalizeName($a, 'magazine');
      $b = Isfdb::normalizeName($b, 'magazine');
      return $a === $b || (min(strlen($a), strlen($b)) >= 2 && (str_contains($a, $b) || str_contains($b, $a)));
    };
    $retired = 0;
    foreach ($storage->loadByProperties(['type' => 'edition', 'field_format' => array_key_first($format), 'status' => 1]) as $edition) {
      $works = array_map(fn($b) => (int) $b->id(), $edition->get('field_novel')->referencedEntities());
      $title = preg_replace('/,\s.*$/', '', $edition->label());
      foreach ($storage->loadByProperties(['type' => 'short_story', 'title' => $title]) as $story) {
        $works[] = (int) $story->id();
      }
      $magazine = (string) $edition->get('field_publisher')->entity?->label();
      $year = (int) $edition->get('field_year')->value;
      $issues = array_filter($works ? $storage->loadByProperties(['type' => 'magazine_issue', 'field_contents' => $works]) : [],
        fn($i) => $same_magazine((string) $i->get('field_magazine')->entity?->label(), $magazine) && abs((int) $i->get('field_year')->value - $year) <= 2);
      if (!$issues) {
        $this->output()->writeln(sprintf('keep    %d "%s": no issues listed for it yet', $edition->id(), $edition->label()));
        continue;
      }
      $isfdb = $edition->get('field_isfdb_id')->value;
      $this->output()->writeln(sprintf('retire  %d "%s": %d issues listed%s', $edition->id(), $edition->label(), count($issues),
        $isfdb ? ", and undo its mistaken match to ISFDB publication $isfdb" : ''));
      if ($options['dry-run']) {
        continue;
      }
      if ($isfdb) {
        $edition = $this->revertIsfdbFill($edition);
        $map = $this->migrationManager->createInstance('isfdb_editions')->getIdMap();
        $map->delete(['pub_id' => $isfdb]);
      }
      $edition->setUnpublished();
      $edition->setNewRevision(TRUE);
      $edition->setRevisionLogMessage('Retired: this serialisation is now listed issue by issue.');
      $edition->save();
      $retired++;
    }
    $this->logger()->success($options['dry-run'] ? 'Dry run: nothing changed.' : "Unpublished $retired serial entries.");
  }

  /**
   * Restores a node to its revision from before the ISFDB import filled it.
   */
  private function revertIsfdbFill(Node $node): Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $previous = NULL;
    foreach ($storage->revisionIds($node) as $vid) {
      $revision = $storage->loadRevision($vid);
      if (str_starts_with((string) $revision->getRevisionLogMessage(), 'Filled in from ISFDB')) {
        break;
      }
      $previous = $revision;
    }
    if (!$previous) {
      return $node;
    }
    $previous->setNewRevision(TRUE);
    $previous->isDefaultRevision(TRUE);
    $previous->setRevisionLogMessage('Reverted a mistaken ISFDB match (a book publication matched to this magazine serial).');
    $previous->save();
    return $storage->load($node->id());
  }

  /**
   * Repairs translator names that swallowed the rest of an ISFDB note.
   *
   * Re-reads each translator's name with the current rules, e.g. "Bert
   * Rozeboom Cover artist is not credited" becomes "Bert Rozeboom", and moves
   * the editions to the corrected names. Names from notes pairing several
   * translators with several titles are removed instead, for the next import
   * (drush mim --tag=isfdb --update) to fill in by matching his title. If the extra text said the cover is
   * uncredited and the edition has no cover artist, records "Uncredited".
   * Bad terms nothing uses afterwards are deleted.
   */
  #[CLI\Command(name: 'sg-isfdb:fix-translators')]
  #[CLI\Option(name: 'dry-run', description: 'Count what would change, without changing anything.')]
  public function fixTranslators(array $options = ['dry-run' => FALSE]): void {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $find = function (string $name) use ($terms): int {
      $found = $terms->loadByProperties(['vid' => 'people', 'name' => $name]);
      $term = $found ? reset($found) : $terms->create(['vid' => 'people', 'name' => $name]);
      $term->isNew() && $term->save();
      return (int) $term->id();
    };
    $uncredited = $terms->loadByProperties(['vid' => 'people', 'name' => 'Uncredited']);
    $bad = $editions = $covers = $deleted = 0;
    $tids = \Drupal::database()->query('SELECT DISTINCT field_translator_target_id FROM {node__field_translator}')->fetchCol();
    foreach ($terms->loadMultiple($tids) as $term) {
      $names = Isfdb::names($term->label());
      if ($names === [$term->label()]) {
        continue;
      }
      $bad++;
      // A name taken from a note pairing several translators with several
      // titles ("Mario Galli as "…" Mission to Universe was translated by…")
      // can't be repaired from the name alone: remove it, and let the next
      // import fill in the right translator by matching his title.
      if (preg_match('/["“«]|\s+as\s+\p{Lu}|\btranslated\b/u', $term->label())) {
        $names = [];
      }
      $says_uncredited = Isfdb::coverUncredited($term->label());
      foreach ($nodes->loadByProperties(['type' => 'edition', 'field_translator' => $term->id()]) as $edition) {
        $editions++;
        $needs_cover = $says_uncredited && $uncredited && $edition->get('field_cover_artist')->isEmpty();
        $covers += $needs_cover ? 1 : 0;
        if ($options['dry-run']) {
          continue;
        }
        $ids = array_map('intval', array_column($edition->get('field_translator')->getValue(), 'target_id'));
        $replacement = $names ? array_map($find, $names) : [];
        $new = [];
        foreach ($ids as $id) {
          array_push($new, ...($id === (int) $term->id() ? $replacement : [$id]));
        }
        $edition->set('field_translator', array_values(array_unique($new)));
        if ($needs_cover) {
          $edition->set('field_cover_artist', array_key_first($uncredited));
        }
        $edition->setNewRevision(TRUE);
        $edition->setRevisionLogMessage('Translator name repaired: it had swallowed the rest of an ISFDB note.');
        $edition->save();
      }
      if (!$options['dry-run'] && !$this->merger->countReferences('taxonomy_term', (int) $term->id())) {
        $term->delete();
        $deleted++;
      }
    }
    $this->logger()->success(sprintf('%s %d translator names on %d editions; %d editions %s "Uncredited" as cover artist; %d bad terms deleted.',
      $options['dry-run'] ? 'Would repair' : 'Repaired', $bad, $editions, $covers, $options['dry-run'] ? 'would get' : 'got', $deleted));
  }

  /**
   * Gives editions back their own covers after an earlier upgrade-covers run.
   *
   * Before upgrade-covers learned to keep an edition's own cover, it could
   * replace a cover fetched for that publication (from ISFDB or Open Library)
   * with a larger picture of a different edition. This puts the edition's
   * own cover back, unless the cover has been changed again since.
   */
  #[CLI\Command(name: 'sg-isfdb:restore-edition-covers')]
  #[CLI\Option(name: 'dry-run', description: 'List what would be restored, without changing anything.')]
  public function restoreEditionCovers(array $options = ['dry-run' => FALSE]): void {
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $media = \Drupal::entityTypeManager()->getStorage('media');
    $restored = 0;
    foreach ($nodes->loadByProperties(['type' => 'edition']) as $edition) {
      $ids = $nodes->revisionIds($edition);
      foreach (array_reverse($ids, TRUE) as $index => $vid) {
        $revision = $nodes->loadRevision($vid);
        if (!str_starts_with((string) $revision->getRevisionLogMessage(), 'Cover switched to a larger copy') || !isset($ids[$index - 1])) {
          continue;
        }
        $before = $nodes->loadRevision($ids[$index - 1])->get('field_cover')->target_id;
        $own = $before ? $media->load($before) : NULL;
        $source = (string) $own?->get('field_image_source')->entity?->label();
        if ($own && $source !== '' && $source !== 'James White Information (original site)'
          && (int) $edition->get('field_cover')->target_id === (int) $revision->get('field_cover')->target_id) {
          $this->output()->writeln(sprintf('edition %d "%s": back to its own %s cover', $edition->id(), $edition->label(), $source));
          if (!$options['dry-run']) {
            $edition->set('field_cover', $own->id());
            $edition->setNewRevision(TRUE);
            $edition->setRevisionLogMessage('Own cover restored: a larger picture of a different edition had replaced it.');
            $edition->save();
          }
          $restored++;
        }
        break;
      }
    }
    $this->logger()->success(sprintf($options['dry-run'] ? '%d editions would get their own cover back.' : 'Restored the own cover of %d editions.', $restored));
  }

}
