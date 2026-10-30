<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Publications of the author's books: one row per edition.
 *
 * Source properties added: title, label, work_ids, language, translator,
 * publisher, publisher_series, publisher_series_number, catalogue, isbn,
 * price, pages, format, year, date_display, cover_artists, interior_artists,
 * introductions, oclc, openlibrary, note, image_url, isfdb_url, attribution, and
 * anthology_title_id when ISFDB files the publication as an anthology.
 */
#[MigrateSource('isfdb_publications')]
class IsfdbPublications extends IsfdbSourceBase {

  public function query() {
    $query = $this->select('pubs', 'p');
    $query->innerJoin('pub_content', 'pc', 'pc.pub_id = p.pub_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = pc.title_id');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->leftJoin('publishers', 'pu', 'pu.publisher_id = p.publisher_id');
    $query->leftJoin('pub_series', 'ps', 'ps.pub_series_id = p.pub_series_id');
    $query->fields('p', ['pub_id', 'pub_title', 'pub_ptype', 'pub_ctype', 'pub_isbn', 'pub_price', 'pub_pages', 'pub_catalog', 'pub_series_num', 'pub_frontimage', 'note_id'])
      ->fields('pu', ['publisher_name'])
      ->fields('ps', ['pub_series_name'])
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      ->condition('t.title_ttype', Isfdb::BOOK_TYPES, 'IN')
      // Includes anthologies reprinting a whole book of his.
      ->condition('p.pub_ctype', array_merge(Isfdb::BOOK_TYPES, ['ANTHOLOGY']), 'IN')
      ->distinct()
      ->orderBy('p.pub_id');
    // Dates are read as text: ISFDB dates such as 1977-03-00 are zeroed by
    // MySQL's strict mode when a query needs a temporary table (DISTINCT).
    $query->addExpression('CAST(p.pub_year AS CHAR)', 'pub_year');
    return $query;
  }

  public function fields() {
    return [
      'pub_id' => $this->t('ISFDB publication ID'),
      'pub_title' => $this->t('Title'),
      'pub_year' => $this->t('Date'),
      'pub_ptype' => $this->t('Format'),
      'pub_isbn' => $this->t('ISBN'),
      'publisher_name' => $this->t('Publisher'),
    ];
  }

  public function getIds() {
    return ['pub_id' => ['type' => 'integer', 'alias' => 'p']];
  }

  public function prepareRow(Row $row) {
    $id = (int) $row->getSourceProperty('pub_id');
    $title = Isfdb::text($row->getSourceProperty('pub_title'));
    $publisher = Isfdb::text($row->getSourceProperty('publisher_name')) ?: NULL;
    $this->setDate($row, 'pub_year');
    $year = $row->getSourceProperty('year');

    $books = $this->authorTitlesInPub($id, Isfdb::BOOK_TYPES);
    $row->setSourceProperty('work_ids', $this->parentIds(array_column($books, 'title_id')));
    $language = $books[0]['lang_name'] ?? NULL;
    $row->setSourceProperty('language', $language ? Isfdb::text($language) : NULL);

    $note = $this->note((int) $row->getSourceProperty('note_id'));
    // His work as titled here and under its original title, to pick out his
    // translator from notes that name several.
    $titles = array_column($books, 'title_title');
    foreach (array_filter(array_column($books, 'title_parent')) as $parent) {
      $titles[] = (string) $this->select('titles', 't')->fields('t', ['title_title'])->condition('t.title_id', $parent)->execute()->fetchField();
    }
    $titles = array_map([Isfdb::class, 'text'], $titles);
    $translators = Isfdb::translators($note, $titles);
    foreach ($books as $book) {
      $translators = $translators ?: Isfdb::translators($this->note((int) $book['note_id']), $titles);
    }

    $ids = $this->identifiers($id);
    $number = Isfdb::text($row->getSourceProperty('pub_series_num'));
    $row->setSourceProperty('title', $title);
    $row->setSourceProperty('label', implode(', ', array_filter([$title, $publisher ? explode(',', $publisher)[0] : NULL, $year])));
    $row->setSourceProperty('translator', $translators);
    $row->setSourceProperty('publisher', $publisher);
    $row->setSourceProperty('publisher_series', Isfdb::text($row->getSourceProperty('pub_series_name')) ?: NULL);
    $row->setSourceProperty('publisher_series_number', $number !== '' ? $number : NULL);
    $row->setSourceProperty('catalogue', Isfdb::text($row->getSourceProperty('pub_catalog')) ?: NULL);
    $row->setSourceProperty('isbn', Isfdb::isbn13($row->getSourceProperty('pub_isbn')));
    $row->setSourceProperty('price', Isfdb::text($row->getSourceProperty('pub_price')) ?: NULL);
    $row->setSourceProperty('pages', Isfdb::pages($row->getSourceProperty('pub_pages')));
    $row->setSourceProperty('format', Isfdb::FORMAT_MAP[$row->getSourceProperty('pub_ptype')] ?? NULL);
    // A note saying the cover isn't credited records that explicitly.
    $cover_artists = $this->creditsInPub($id, 'COVERART');
    if (!$cover_artists && Isfdb::coverUncredited($note)) {
      $cover_artists = ['Uncredited'];
    }
    $row->setSourceProperty('cover_artists', $cover_artists);
    $row->setSourceProperty('interior_artists', $this->creditsInPub($id, 'INTERIORART'));
    $row->setSourceProperty('introductions', $this->introducersOf($id));
    $row->setSourceProperty('oclc', $ids['OCLC/WorldCat'] ?? NULL);
    $row->setSourceProperty('openlibrary', $ids['Open Library'] ?? NULL);
    $row->setSourceProperty('image_url', Isfdb::text($row->getSourceProperty('pub_frontimage')) ?: NULL);
    // The anthology this publication is, if ISFDB files it as one.
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->fields('t', ['title_id'])->condition('pc.pub_id', $id)->condition('t.title_ttype', 'ANTHOLOGY')->range(0, 1);
    $row->setSourceProperty('anthology_title_id', $query->execute()->fetchField() ?: NULL);
    $row->setSourceProperty('isfdb_url', Isfdb::url('publication', $id));
    $row->setSourceProperty('attribution', Isfdb::attribution('publication', $id));
    return parent::prepareRow($row);
  }

  /**
   * Who wrote an introduction, foreword or afterword to this publication.
   *
   * ISFDB lists these as essays in the contents, titled "Introduction",
   * "Introduction (The White Papers)", "Foreword" and so on. Essays by the
   * author himself aren't included.
   *
   * @return string[]
   */
  protected function introducersOf(int $pub_id): array {
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->innerJoin('authors', 'a', 'a.author_id = ca.author_id');
    $query->fields('t', ['title_title'])->fields('a', ['author_canonical'])
      ->condition('pc.pub_id', $pub_id)
      ->condition('ca.ca_status', 1)
      ->condition('ca.author_id', $this->authorId(), '<>')
      ->condition('t.title_ttype', 'ESSAY')
      ->orderBy('pc.pubc_id');
    $names = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $essay) {
      if (preg_match('/^\s*(introduction|introductory|foreword|preface|afterword|appreciation)\b/i', Isfdb::text($essay['title_title']))) {
        $names[] = $essay['author_canonical'];
      }
    }
    return $this->uniqueNames($names);
  }

}
