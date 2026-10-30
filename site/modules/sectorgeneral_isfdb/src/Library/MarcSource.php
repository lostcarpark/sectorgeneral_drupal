<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Editions from a library catalogue in MARC 21, however it is fetched
 * (Z3950Source, SruSource).
 *
 * One class serves every such library; what differs between them is in the
 * settings, one entry per library (see CatalogueSources):
 * - label, licence, language: as credited and as the site's term names it.
 * - language_code: MARC language code of the editions wanted ("ita").
 * - query: the search, run once; its form depends on the protocol.
 * - author: the author as the catalogue heads his records ("White, James").
 * - authority: his authority record ID in the catalogue, in $0 or $7 of 100
 *   or 700 (the name search also finds other James Whites).
 * - unidentified: optional; "1" also accepts records naming exactly the
 *   author, with no authority ID and no dates, as older records do. The
 *   importer still needs a title to match one of the site's books.
 * - record_id: where the record number is: a control field tag ("001") or a
 *   data field tag and subfield ("998a"); record_id_pattern and
 *   record_id_replace optionally rewrite it.
 * - record_url: the record's public address, with {id} for the number.
 * - translator_note: optional regex for the words that introduce a translator
 *   in notes ("Trad. di"), for records whose 700 entries carry no role.
 * - cover_note: optional words crediting a cover illustrator ("copertina di").
 *
 * Several libraries in a union catalogue may catalogue one edition, so
 * records for one edition are merged and each is credited.
 */
abstract class MarcSource implements LibrarySourceInterface {

  /**
   * MARC 700 $4 relator codes and the edition fields they fill.
   */
  protected const ROLES = [
    'trl' => 'field_translator',
    'aui' => 'field_introduction_by',
    'nrt' => 'field_narrator',
  ];

  public function __construct(
    protected string $id,
    protected array $settings,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public function id(): string {
    return $this->id;
  }

  public function label(): string {
    return $this->settings['label'];
  }

  public function licence(): string {
    return (string) ($this->settings['licence'] ?? '');
  }

  public function language(): string {
    return $this->settings['language'];
  }

  public function parse(array $raw): array {
    $marcs = array_filter(array_map(fn($xml) => $this->marc($xml), $raw));
    // A volume of a multi-part work (DNB's "Roman des Zyklus Orbit-Hospital")
    // may name its author only in the record for the whole work, which it
    // links to in 773 $w.
    $works = [];
    foreach ($marcs as $marc) {
      if ($this->isSet($marc) && $this->isAuthors($marc)) {
        $works[$marc['001'] ?? ''] = TRUE;
      }
    }
    $records = [];
    foreach ($marcs as $marc) {
      if (!$this->isSet($marc) && ($this->isAuthors($marc) || array_intersect_key($works, array_flip($this->partOf($marc)))) && $this->isInLanguage($marc)) {
        $records[] = $this->parseRecord($marc);
      }
    }
    return $this->merge($records);
  }

  /**
   * Whether the record describes a multi-part work as a whole, not an
   * edition (leader position 19 "a").
   */
  protected function isSet(array $marc): bool {
    return ($marc['leader'][19] ?? '') === 'a';
  }

  /**
   * The record numbers of the multi-part works the record is a volume of,
   * from 773 $w without its "(DE-101)" prefix.
   */
  protected function partOf(array $marc): array {
    $ids = [];
    foreach ($marc['773'] ?? [] as $field) {
      foreach ($field['w'] ?? [] as $w) {
        $ids[] = preg_replace('/^\(.*?\)/', '', $w);
      }
    }
    return $ids;
  }

  /**
   * Whether the record names the author's authority record as author.
   */
  protected function isAuthors(array $marc): bool {
    foreach ([...$marc['100'] ?? [], ...$marc['700'] ?? []] as $field) {
      $ids = [...$field['0'] ?? [], ...$field['7'] ?? []];
      if (in_array($this->settings['authority'], $ids, TRUE)) {
        return TRUE;
      }
      if (!empty($this->settings['unidentified']) && !$ids && empty($field['d']) && trim($field['a'][0] ?? '', ' ,.') === $this->settings['author']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function isInLanguage(array $marc): bool {
    $languages = $marc['041'][0]['a'] ?? [substr($marc['008'] ?? '', 35, 3)];
    return in_array($this->settings['language_code'], $languages, TRUE);
  }

  protected function isAuthorsName(string $name): bool {
    return str_starts_with($name, $this->settings['author']);
  }

  protected function parseRecord(array $marc): array {
    $first = fn(string $tag, string $code) => $marc[$tag][0][$code][0] ?? NULL;
    // DNB wraps leading articles in non-sorting marks: "\x98Das\x9c Erwachen".
    $clean = fn(?string $text) => $text === NULL ? NULL : (trim(preg_replace('/\s+/', ' ', str_replace(['"', '\\', '!', '[', ']', "\u{98}", "\u{9C}"], '', $text)), " \t/:;,.=-") ?: NULL);
    // The uniform title of the author's own work; a record can also carry
    // others' ("The Mysterious Planet", serialised in the same Galassia),
    // which SBN marks with their author in $9. DNB gives it as a name-title
    // entry instead ("White, James $t Lifeboat"). An omnibus of several of
    // his books gives none, so the book is found by its translated title.
    $originals = [];
    foreach ($marc['240'] ?? [] as $field) {
      if (!isset($field['9']) || $this->isAuthorsName($field['9'][0])) {
        $originals[] = $clean($field['a'][0] ?? NULL);
      }
    }
    if (!$originals) {
      foreach ($marc['700'] ?? [] as $field) {
        if (isset($field['t']) && $this->isAuthorsName($field['a'][0] ?? '')) {
          $originals[] = $clean($field['t'][0]);
        }
      }
    }
    $originals = array_unique($originals);
    $original = count($originals) === 1 ? reset($originals) : NULL;
    $id = $this->recordId($marc);
    $notes = implode(' ', [$first('245', 'c') ?? '', ...array_map(fn($field) => $field['a'][0] ?? '', $marc['500'] ?? [])]);
    $leader = $marc['leader'] ?? '';
    $kind = match (TRUE) {
      ($leader[7] ?? '') === 'a' => 'article',
      ($leader[6] ?? '') === 'i' => 'audio',
      str_starts_with($marc['007'] ?? '', 'cr') || preg_match('/\bOnline\b/i', (string) $first('300', 'a')) => 'online resource',
      in_array($leader[6] ?? '', ['a', 't'], TRUE) && ($leader[7] ?? '') === 'm' => 'book',
      default => 'record type ' . substr($leader, 6, 2),
    };
    // "VI, 288 p.", "187 s.", "160 S.", "64 stran".
    preg_match('/(\d+)\s*(?:p|s|str|stran|pages|pagine)\b/iu', (string) $first('300', 'a'), $pages);
    $series = $marc['830'][0] ?? $marc['490'][0] ?? [];
    $title = $clean($first('245', 'a'));
    $series_name = $clean($series['a'][0] ?? NULL);
    $series_number = $series['v'][0] ?? NULL;
    // A numbered issue of a series, its title the part's: "Terra. Sonderband
    // $n Nr. 7. $p Die Ausserirdischen / James White".
    if ($part = $first('245', 'p')) {
      $series_name ??= $title;
      $series_number ??= $first('245', 'n');
      $title = $clean(preg_replace('# / .*$#', '', $part));
    }
    // "Bd. Nr. 3842 : Science-fiction" → "3842".
    $series_number = $clean($series_number === NULL ? NULL : preg_replace(['/^(?:Bd\.|Band|Nr\.|No\.|n\.)\s*(?:Nr\.\s*)?/iu', '/\s:.*$/u'], '', $series_number));
    return [
      'id' => $id,
      'url' => str_replace('{id}', rawurlencode($id), $this->settings['record_url']),
      'kind' => $kind,
      'title' => $title,
      'original' => $original,
      'publisher' => $this->publisher($clean($first('264', 'b') ?? $first('260', 'b'))),
      'year' => preg_match('/\d{4}/', (string) ($first('264', 'c') ?? $first('260', 'c')), $y) ? $y[0] : NULL,
      'isbn' => Isfdb::isbn13($first('020', 'a')),
      'pages' => $kind === 'book' && $pages ? (int) $pages[1] : NULL,
      // Edition statements end in abbreviations: "Dt. Erstausg.", "2. Aufl.".
      'printing' => ($printing = $clean($first('250', 'a'))) && str_ends_with(rtrim((string) $first('250', 'a'), ' /:;,=-]'), '.') ? "$printing." : $printing,
      'series' => $series_name,
      'series_number' => $series_number,
      'people' => $this->people($marc, $notes),
    ];
  }

  /**
   * The record number, from record_id and its optional rewrite:
   * "IT\ICCU\UBO\1388878" → "UBO1388878" for SBN.
   */
  protected function recordId(array $marc): string {
    $tag = substr($this->settings['record_id'], 0, 3);
    $code = substr($this->settings['record_id'], 3);
    $id = $code === '' ? (string) ($marc[$tag] ?? '') : (string) ($marc[$tag][0][$code][0] ?? '');
    if (!empty($this->settings['record_id_pattern'])) {
      $id = preg_replace($this->settings['record_id_pattern'], $this->settings['record_id_replace'] ?? '$0', $id);
    }
    return $id;
  }

  /**
   * Translators, introducers and narrators, by relator code; or, in older
   * records without codes, translators named in the notes.
   *
   * A magazine issue (Galassia) also holds others' work, translated by
   * others: there, only translators the title statement names are his.
   */
  protected function people(array $marc, string $notes): array {
    $statement = (string) ($marc['245'][0]['c'][0] ?? '');
    $mixed = FALSE;
    foreach ($marc['700'] ?? [] as $field) {
      if (($field['4'][0] ?? NULL) === 'aut' && !$this->isAuthorsName($field['a'][0] ?? '')) {
        $mixed = TRUE;
      }
    }
    if ($mixed) {
      $notes = $statement;
    }
    $people = [];
    $uncoded = [];
    foreach ($marc['700'] ?? [] as $field) {
      if ($this->isAuthorsName($field['a'][0] ?? '')) {
        continue;
      }
      $name = self::firstLast($field['a'][0] ?? '');
      if ($name === '') {
        continue;
      }
      $role = $field['4'][0] ?? NULL;
      $surname = trim(explode(',', $field['a'][0])[0]);
      // DNB's older records call a translator only a contributor (ctb).
      if ($role === NULL || $role === 'ctb') {
        $uncoded[] = $name;
      }
      elseif ($role === 'trl' && $mixed && !preg_match('/\b' . preg_quote($surname, '/') . '\b/u', $statement)) {
        continue;
      }
      elseif (isset(self::ROLES[$role])) {
        $people[self::ROLES[$role]][] = $name;
      }
    }
    // "Copertina di Erberto Tealdi": an illustrator credited for the cover.
    if (!empty($this->settings['cover_note'])) {
      foreach ($marc['700'] ?? [] as $field) {
        $name = self::firstLast($field['a'][0] ?? '');
        $surname = trim(explode(',', $field['a'][0] ?? '')[0]);
        if (($field['4'][0] ?? NULL) === 'ill' && $surname !== '' && preg_match('/' . preg_quote($this->settings['cover_note'], '/') . ' [^.;]*\b' . preg_quote($surname, '/') . '\b/iu', $notes)) {
          $people['field_cover_artist'][] = $name;
        }
      }
    }
    if (empty($people['field_translator']) && !empty($this->settings['translator_note'])) {
      // "Trad. di Gianluigi Zuddas", "Trad. L. Pollini", "přeložil Ivo Lanský",
      // "Aus d. Engl. übertr. von S. M. Roediger".
      $name = '((?:\p{Lu}\.\s*)*\p{Lu}[\p{L}\'’-]+(?:\s+(?:d[aei]l?l?[ao]?\s+)?\p{Lu}[\p{L}\'’-]+)*)';
      if (preg_match("/(?:{$this->settings['translator_note']})\\s+$name/u", $notes, $match)) {
        $translator = trim($match[1]);
        // Prefer the fuller form of the name from an uncoded 700 entry.
        $surname = preg_replace('/^.*\s/u', '', $translator);
        foreach ($uncoded as $candidate) {
          if (str_ends_with($candidate, " $surname")) {
            $translator = $candidate;
          }
        }
        $people['field_translator'][] = $translator;
      }
    }
    return $people;
  }

  /**
   * "Palazzoli, G." → "G. Palazzoli", keeping an initial's full stop.
   */
  public static function firstLast(string $name): string {
    $name = trim($name, ' ,');
    if (!preg_match('/\b\p{Lu}\.$/u', $name)) {
      $name = rtrim($name, '.');
    }
    $parts = array_map('trim', explode(',', $name, 2));
    return count($parts) === 2 && $parts[1] !== '' ? "$parts[1] $parts[0]" : $parts[0];
  }

  /**
   * The publisher as the site names it, through the publisher aliases, and
   * without a founder's initials ("V. Bompiani").
   */
  protected function publisher(?string $name): ?string {
    if ($name === NULL) {
      return NULL;
    }
    $name = preg_replace('/^(?:\p{Lu}\.\s*)+(?=\p{Lu}\p{Ll})/u', '', $name);
    $key = Isfdb::normalizeName($name, 'publisher');
    foreach ($this->configFactory->get('sectorgeneral_isfdb.settings')->get('publisher_aliases') ?? [] as $alias => $target) {
      if (Isfdb::normalizeName((string) $alias, 'publisher') === $key) {
        return $target;
      }
    }
    return $name;
  }

  /**
   * Merges records of one edition: same original (or, without one, the same
   * title), year, publisher and ISBN (or no ISBN, where the edition has only
   * one). The fullest record leads;
   * the others fill its gaps and are listed under "also" for credit.
   */
  protected function merge(array $records): array {
    $groups = [];
    foreach ($records as $record) {
      $key = implode('|', [Isfdb::normalizeTitle((string) ($record['original'] ?? $record['title'])), $record['year'], Isfdb::normalizeName((string) $record['publisher'], 'publisher')]);
      $groups[$key][$record['isbn'] ?? ''][] = $record;
    }
    $merged = [];
    foreach ($groups as $by_isbn) {
      if (isset($by_isbn['']) && count($by_isbn) === 2) {
        $isbn = array_key_last(array_filter($by_isbn, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY));
        $by_isbn[$isbn] = [...$by_isbn[$isbn], ...$by_isbn['']];
        unset($by_isbn['']);
      }
      foreach ($by_isbn as $set) {
        usort($set, fn($a, $b) => $this->fullness($b) <=> $this->fullness($a));
        $lead = array_shift($set);
        $lead['also'] = [];
        foreach ($set as $other) {
          foreach ($other as $field => $value) {
            if ($field === 'people') {
              $lead['people'] += $value;
            }
            elseif (!in_array($field, ['id', 'url', 'also', 'also_ids'], TRUE) && ($lead[$field] ?? NULL) === NULL) {
              $lead[$field] = $value;
            }
          }
          $lead['also'][] = $other['url'];
          $lead['also_ids'][] = $other['id'];
        }
        $merged[] = $lead;
      }
    }
    return $merged;
  }

  protected function fullness(array $record): int {
    return count(array_filter($record, fn($value) => $value !== NULL && $value !== [])) + count($record['people']);
  }

  /**
   * MARCXML as tag => list of fields (subfield code => values); control
   * fields and the leader as plain strings.
   */
  protected function marc(string $xml): array {
    // DNB sends accents as combining marks: "Schlu\u{308}ck".
    $record = @simplexml_load_string(\Normalizer::normalize($xml) ?: $xml);
    if (!$record) {
      return [];
    }
    $fields = ['leader' => (string) $record->leader];
    foreach ($record->controlfield as $field) {
      $fields[(string) $field['tag']] = (string) $field;
    }
    foreach ($record->datafield as $field) {
      $subfields = [];
      foreach ($field->subfield as $subfield) {
        $subfields[(string) $subfield['code']][] = trim((string) $subfield);
      }
      $fields[(string) $field['tag']][] = $subfields;
    }
    return $fields;
  }

}
