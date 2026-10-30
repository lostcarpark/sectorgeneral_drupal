<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sectorgeneral_isfdb\CoverFetcher;

/**
 * Editions from a library catalogue's SRU interface, in MARC 21 XML.
 *
 * Settings, under sru, as MarcSource describes, plus:
 * - url: the SRU base address ("https://services.dnb.de/sru/dnb").
 * - query: the CQL search.
 * - record_schema: the MARC 21 XML schema's name there ("MARC21-xml").
 * - delay: seconds between requests.
 * - parts_query: optional CQL search for the volumes of a multi-part work,
 *   with {id} for its record number ("partof={id}"). The volumes are fetched
 *   for each of the author's multi-part works the search finds, as they may
 *   not name him themselves.
 *
 * fetch() pages through the results, saying who is asking; an HTTP error or
 * an SRU diagnostic stops it.
 */
class SruSource extends MarcSource {

  protected const MARC_NS = 'http://www.loc.gov/MARC21/slim';

  /**
   * Records fetched per request.
   */
  protected const PAGE = 100;

  public function __construct(
    string $id,
    array $settings,
    ConfigFactoryInterface $configFactory,
    protected CoverFetcher $covers,
  ) {
    parent::__construct($id, $settings, $configFactory);
  }

  public function fetch(): array {
    $raw = $this->search($this->settings['query']);
    if (!empty($this->settings['parts_query'])) {
      foreach ($raw as $id => $xml) {
        $marc = $this->marc($xml);
        if ($this->isSet($marc) && $this->isAuthors($marc)) {
          $raw += $this->search(str_replace('{id}', (string) $id, $this->settings['parts_query']));
        }
      }
    }
    return $raw;
  }

  /**
   * Every record a CQL search finds, by record number.
   */
  protected function search(string $query): array {
    $raw = [];
    $start = 1;
    do {
      $url = $this->settings['url'] . '?' . http_build_query([
        'version' => '1.1',
        'operation' => 'searchRetrieve',
        'query' => $query,
        'recordSchema' => $this->settings['record_schema'],
        'startRecord' => $start,
        'maximumRecords' => self::PAGE,
      ]);
      $page = new \DOMDocument();
      if (!@$page->loadXML($this->covers->download($url, (float) ($this->settings['delay'] ?? 3)))) {
        throw new \RuntimeException("{$this->label()} sent a response that is not XML.");
      }
      $diagnostic = $page->getElementsByTagNameNS('*', 'diagnostic')->item(0);
      if ($diagnostic) {
        throw new \RuntimeException("{$this->label()}: " . trim(preg_replace('/\s+/', ' ', $diagnostic->textContent)));
      }
      $total = (int) $page->getElementsByTagNameNS('*', 'numberOfRecords')->item(0)?->textContent;
      $records = $page->getElementsByTagNameNS(self::MARC_NS, 'record');
      foreach ($records as $record) {
        $xml = $page->saveXML($record);
        $marc = $this->marc($xml);
        $raw[$marc ? $this->recordId($marc) : sha1($xml)] = $xml;
      }
      $start += self::PAGE;
    } while ($records->length && $start <= min($total, 1000));
    return $raw;
  }

}
