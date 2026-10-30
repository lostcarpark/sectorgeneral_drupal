<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Fills in editions from Open Library, looked up by ISBN.
 *
 * One request per edition fetches Open Library's edition record (record ID,
 * page count, OCLC number, cover ID); the cover image itself is fetched only
 * when the edition has no cover or a smaller one, through CoverFetcher, so
 * the same de-duplication, credit and undo apply. Open Library's catalogue
 * data is CC0; requests are spaced by openlibrary_delay seconds.
 */
class OpenLibrary {

  /**
   * ISBNs Open Library doesn't have, so they aren't asked for again.
   */
  protected const MISSING = 'sectorgeneral_isfdb.openlibrary_missing';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected CoverFetcher $covers,
  ) {}

  /**
   * Editions with a valid ISBN that haven't been looked up yet.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function candidates(bool $recheck = FALSE, int $limit = 0): array {
    $missing = $recheck ? [] : $this->state->get(self::MISSING, []);
    $found = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'edition']) as $edition) {
      $isbn = Isfdb::isbn13($edition->get('field_isbn')->value);
      if (!$isbn || isset($missing[$isbn]) || (!$recheck && !$edition->get('field_openlibrary_id')->isEmpty())) {
        continue;
      }
      $found[] = $edition;
      if ($limit && count($found) >= $limit) {
        break;
      }
    }
    return $found;
  }

  /**
   * Looks up one edition and fills in what Open Library knows.
   *
   * @return string[]
   *   What happened: "not found", "details", and the cover result
   *   (added, reused, replaced, kept, current) if it has a cover.
   */
  public function process(NodeInterface $edition): array {
    $settings = $this->configFactory->get('sectorgeneral_isfdb.settings');
    $delay = (float) $settings->get('openlibrary_delay');
    $isbn = Isfdb::isbn13($edition->get('field_isbn')->value);
    try {
      $record = json_decode($this->covers->download(rtrim($settings->get('openlibrary_base'), '/') . "/isbn/$isbn.json", $delay), TRUE);
    }
    catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() !== 404) {
        throw $e;
      }
      $missing = $this->state->get(self::MISSING, []);
      $missing[$isbn] = time();
      $this->state->set(self::MISSING, $missing);
      return ['not found'];
    }
    if (!is_array($record) || !preg_match('#/books/(OL\d+M)$#', $record['key'] ?? '', $m)) {
      return ['not found'];
    }
    $olid = $m[1];
    $page = "https://openlibrary.org/books/$olid";
    $results = [];

    $changed = FALSE;
    $fill = function (string $field, $value) use ($edition, &$changed) {
      if ($value !== NULL && $value !== '' && $edition->get($field)->isEmpty()) {
        $edition->set($field, $value);
        $changed = TRUE;
      }
    };
    $fill('field_openlibrary_id', $olid);
    $fill('field_pages', isset($record['number_of_pages']) ? (int) $record['number_of_pages'] : NULL);
    $fill('field_oclc', $record['oclc_numbers'][0] ?? NULL);
    $attribution = "Open Library record $page (CC0).";
    $sources = (string) $edition->get('field_data_sources')->value;
    if (!str_contains($sources, $page)) {
      $edition->set('field_data_sources', trim("$sources\n$attribution"));
      $changed = TRUE;
    }
    if ($changed) {
      $edition->setNewRevision(TRUE);
      $edition->setRevisionLogMessage("Filled in from Open Library: $page");
      $edition->save();
      $results[] = 'details';
    }

    $cover_ids = array_values(array_filter($record['covers'] ?? [], fn($id) => (int) $id > 0));
    if ($cover_ids) {
      // default=false: a missing cover is a 404, not a blank placeholder.
      $url = rtrim($settings->get('openlibrary_covers_base'), '/') . "/b/id/{$cover_ids[0]}-L.jpg?default=false";
      $results[] = $this->covers->process($edition, $url, 'Open Library', $page, $delay);
    }
    return $results;
  }

}
