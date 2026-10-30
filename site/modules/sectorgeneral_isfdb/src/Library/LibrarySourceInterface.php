<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

/**
 * A national library catalogue that LibraryImporter can read editions from.
 *
 * A source fetches the library's records for James White exactly as the
 * library gives them, which LibraryRecords stores, and maps stored records
 * to a common shape; matching them to the site's books and editions, and
 * saving, is LibraryImporter's. Parsing never fetches, so it can be changed
 * and re-run on stored records.
 */
interface LibrarySourceInterface {

  /**
   * Short machine name, as given to drush sg-isfdb:library ("bn", "dnb").
   */
  public function id(): string;

  /**
   * The library's name, as credited: "Biblioteka Narodowa".
   */
  public function label(): string;

  /**
   * The licence of its data, as credited: "CC0"; empty if none is stated.
   */
  public function licence(): string;

  /**
   * The language of the editions it describes, as the site's term names it.
   */
  public function language(): string;

  /**
   * Fetches the library's records for the author, unparsed.
   *
   * @return string[]
   *   Each record as the library sent it (MARC XML, JSON), keyed by the
   *   library's record number. May include others' records that the search
   *   also found; parse() leaves those out.
   */
  public function fetch(): array;

  /**
   * The author's records, from raw records as fetch() returned them.
   *
   * @param string[] $raw
   *   Raw records keyed by record number.
   *
   * @return array[]
   *   Records with these keys (NULL or empty where unknown):
   *   - id: the library's record number.
   *   - url: a link to the record, for the credit.
   *   - kind: "book", "audio", or what else it is, in the library's words.
   *   - title, original: the title, and the original (English) title.
   *   - publisher, year, isbn, pages, printing (edition statement),
   *     series, series_number.
   *   - people: edition field name => list of names ("First Last").
   *   - also: optional links to further records of the same edition merged
   *     into this one (a union catalogue's duplicates), credited too; and
   *     also_ids, their record numbers.
   */
  public function parse(array $raw): array;

}
