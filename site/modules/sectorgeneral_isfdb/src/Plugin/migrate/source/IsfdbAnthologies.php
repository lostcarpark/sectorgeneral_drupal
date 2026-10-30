<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Anthologies by other editors that include the author's short fiction.
 *
 * One row per ISFDB anthology title (translations are separate titles). The
 * earliest publication supplies the publisher and year; later ones are
 * listed in the notes.
 *
 * Source properties added: title, year, editors, publisher, series,
 * series_number, story_ids, content_ids (stories and books), other_contributors,
 * notes, attribution.
 */
#[MigrateSource('isfdb_anthologies')]
class IsfdbAnthologies extends IsfdbSourceBase {

  public function query() {
    // Anthology titles whose publications contain one of the author's stories,
    // or a whole book of his (a novel reprinted in an anthology).
    $with_story = $this->select('pub_content', 'pa');
    $with_story->innerJoin('pub_content', 'ps', 'ps.pub_id = pa.pub_id');
    $with_story->innerJoin('canonical_author', 'ca', 'ca.title_id = ps.title_id');
    $with_story->innerJoin('titles', 'st', 'st.title_id = ps.title_id');
    $with_story->fields('pa', ['title_id'])
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      ->condition('st.title_ttype', array_merge(['SHORTFICTION'], Isfdb::BOOK_TYPES), 'IN');

    $query = $this->select('titles', 't');
    $query->leftJoin('series', 's', 's.series_id = t.series_id');
    $query->fields('t', ['title_id', 'title_title', 'title_seriesnum'])
      ->fields('s', ['series_title'])
      ->condition('t.title_ttype', 'ANTHOLOGY')
      ->condition('t.title_id', $with_story, 'IN')
      ->orderBy('t.title_id');
    // Dates are read as text: ISFDB dates such as 1977-03-00 are zeroed by
    // MySQL's strict mode when a query needs a temporary table (DISTINCT).
    $query->addExpression('CAST(t.title_copyright AS CHAR)', 'title_copyright');
    return $query;
  }

  public function fields() {
    return [
      'title_id' => $this->t('ISFDB title ID'),
      'title_title' => $this->t('Title'),
      'title_copyright' => $this->t('Date'),
    ];
  }

  public function getIds() {
    return ['title_id' => ['type' => 'integer', 'alias' => 't']];
  }

  public function prepareRow(Row $row) {
    $id = (int) $row->getSourceProperty('title_id');
    $row->setSourceProperty('title', Isfdb::text($row->getSourceProperty('title_title')));
    $this->setDate($row, 'title_copyright');
    $row->setSourceProperty('editors', $this->titleAuthors($id));
    $row->setSourceProperty('series', Isfdb::text($row->getSourceProperty('series_title')) ?: NULL);
    $number = $row->getSourceProperty('title_seriesnum');
    $row->setSourceProperty('series_number', is_numeric($number) && (int) $number > 0 ? (int) $number : NULL);

    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('pubs', 'p', 'p.pub_id = pc.pub_id');
    $query->leftJoin('publishers', 'pu', 'pu.publisher_id = p.publisher_id');
    $query->fields('p', ['pub_id', 'pub_ptype'])
      ->fields('pu', ['publisher_name'])
      ->condition('pc.title_id', $id)
      ->orderBy('p.pub_year')
      ->orderBy('p.pub_id');
    $query->addExpression('CAST(p.pub_year AS CHAR)', 'pub_year');
    $pubs = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $stories = [];
    $contents = [];
    $notes = [];
    foreach ($pubs as $i => $pub) {
      $stories = array_merge($stories, array_column($this->authorTitlesInPub((int) $pub['pub_id'], ['SHORTFICTION']), 'title_id'));
      $contents = array_merge($contents, array_column($this->authorTitlesInPub((int) $pub['pub_id'], array_merge(['SHORTFICTION'], Isfdb::BOOK_TYPES)), 'title_id'));
      [, $date] = Isfdb::date($pub['pub_year']);
      $notes[] = implode(', ', array_filter([Isfdb::text($pub['publisher_name']), $date, Isfdb::FORMAT_MAP[$pub['pub_ptype']] ?? NULL])) . '.';
    }
    $first = $pubs[0] ?? NULL;
    $row->setSourceProperty('publisher', $first ? (Isfdb::text($first['publisher_name']) ?: NULL) : NULL);
    $row->setSourceProperty('year', $row->getSourceProperty('year') ?? ($first ? Isfdb::date($first['pub_year'])[0] : NULL));
    $row->setSourceProperty('story_ids', $this->parentIds($stories));
    // Stories and whole books, in page order, for the Contents field.
    $row->setSourceProperty('content_ids', $this->parentIds($contents));
    $row->setSourceProperty('other_contributors', $first ? $this->creditsInPub((int) $first['pub_id'], ['SHORTFICTION', 'POEM'], TRUE) : []);
    $row->setSourceProperty('notes', $notes ? '<p>' . htmlspecialchars(implode(' ', $notes)) . '</p>' : NULL);
    $row->setSourceProperty('attribution', Isfdb::attribution('title', $id));
    return parent::prepareRow($row);
  }

}
