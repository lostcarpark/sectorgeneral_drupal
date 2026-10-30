<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sectorgeneral_isfdb\CoverFetcher;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Polish editions from the Polish National Library (Biblioteka Narodowa).
 *
 * Its catalogue is published at data.bn.org.pl under CC0, with no robots.txt
 * restrictions on the API. (The catalogue's search pages, katalogi.bn.org.pl,
 * ask bots to stay away; this doesn't use them.) Records come as JSON with
 * MARC 21 inside.
 */
class BibliotekaNarodowaSource implements LibrarySourceInterface {

  /**
   * MARC 700 $e roles and the edition fields they fill.
   */
  protected const ROLES = [
    'tłumaczenie' => 'field_translator',
    'wykonanie' => 'field_narrator',
    'przedm' => 'field_introduction_by',
    'przedmowa' => 'field_introduction_by',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected CoverFetcher $covers,
  ) {}

  public function id(): string {
    return 'bn';
  }

  public function label(): string {
    return 'Biblioteka Narodowa';
  }

  public function licence(): string {
    return 'CC0';
  }

  public function language(): string {
    return 'Polish';
  }

  public function fetch(): array {
    $settings = $this->configFactory->get('sectorgeneral_isfdb.settings');
    $url = rtrim($settings->get('bn_base'), '/') . '/api/institutions/bibs.json?' . http_build_query(['author' => $settings->get('bn_author'), 'limit' => 100]);
    $raw = [];
    for ($page = 0; $url && $page < 20; $page++) {
      $data = json_decode($this->covers->download($url, (float) $settings->get('bn_delay')), TRUE) ?: [];
      foreach ($data['bibs'] ?? [] as $record) {
        $raw[(string) $record['id']] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      $url = $data['nextPage'] ?? NULL;
    }
    return $raw;
  }

  public function parse(array $raw): array {
    $author = $this->configFactory->get('sectorgeneral_isfdb.settings')->get('bn_author');
    $records = [];
    foreach ($raw as $json) {
      $record = json_decode($json, TRUE) ?: [];
      // The author search also finds other James Whites.
      if (str_contains((string) ($record['author'] ?? ''), $author) && empty($record['deleted'])) {
        $records[] = $this->parseRecord($record);
      }
    }
    return $records;
  }

  protected function parseRecord(array $record): array {
    $marc = $this->marc($record);
    $first = fn(string $tag, string $code) => $marc[$tag][0][$code] ?? NULL;
    $clean = fn(?string $text) => $text === NULL ? NULL : trim(preg_replace('/\s+/', ' ', str_replace('"', '', $text)), " \t/:;,.=");
    $people = [];
    foreach ($marc['700'] ?? [] as $field) {
      $field_name = self::ROLES[mb_strtolower(trim($field['e'] ?? '', ' .'))] ?? NULL;
      if ($field_name && !empty($field['a'])) {
        $people[$field_name][] = self::firstLast($field['a']);
      }
    }
    $series = $clean($first('490', 'a'));
    preg_match('/\d+/', (string) $first('300', 'a'), $pages);
    $kind = match ($record['kind'] ?? '') {
      'książka' => 'book',
      'audiobook' => 'audio',
      default => (string) ($record['kind'] ?? ''),
    };
    return [
      'id' => (int) $record['id'],
      'url' => rtrim($this->configFactory->get('sectorgeneral_isfdb.settings')->get('bn_base'), '/') . '/api/institutions/bibs.json?id=' . $record['id'],
      'kind' => $kind,
      'title' => $clean($first('245', 'a')),
      'original' => $clean($first('246', 'a')),
      'publisher' => $clean($first('264', 'b') ?? $first('260', 'b')),
      'year' => preg_match('/\d{4}/', (string) ($record['publicationYear'] ?? ''), $y) ? $y[0] : NULL,
      'isbn' => Isfdb::isbn13($first('020', 'a')),
      // An audiobook's extent is a count of cassettes, not pages.
      'pages' => $kind === 'book' && $pages ? (int) $pages[0] : NULL,
      'printing' => $clean($first('250', 'a')),
      // "Szpital Kosmiczny / James White" → "Szpital Kosmiczny".
      'series' => $series ? trim(explode(' / ', $series)[0]) : NULL,
      'series_number' => $clean($first('490', 'v')),
      'people' => $people,
    ];
  }

  /**
   * "Kot, Radosław" → "Radosław Kot".
   */
  public static function firstLast(string $name): string {
    $parts = array_map('trim', explode(',', trim($name, ' ,.'), 2));
    return count($parts) === 2 && $parts[1] !== '' ? "$parts[1] $parts[0]" : $parts[0];
  }

  /**
   * MARC fields by tag: lists of subfield-code => value arrays (the first
   * value of each code).
   */
  protected function marc(array $record): array {
    $fields = [];
    foreach ($record['marc']['fields'] ?? [] as $field) {
      foreach ($field as $tag => $value) {
        if (is_array($value)) {
          $subfields = [];
          foreach ($value['subfields'] ?? [] as $subfield) {
            foreach ($subfield as $code => $text) {
              $subfields[$code] ??= $text;
            }
          }
          $fields[$tag][] = $subfields;
        }
      }
    }
    return $fields;
  }

}
