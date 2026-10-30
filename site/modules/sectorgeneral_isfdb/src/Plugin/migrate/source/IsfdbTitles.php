<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * The author's canonical titles of the configured types: books or stories.
 *
 * Configuration:
 * - types: ISFDB title types, e.g. [NOVEL, COLLECTION] or [SHORTFICTION].
 * - languages: (optional) only titles in these languages, e.g. [English].
 * - contents: if TRUE, adds "story_ids": the author's short fiction in the
 *   title's earliest publication, in page order.
 *
 * Source properties added: title, year, date_display, book_type, series,
 * series_number, language, alt_titles, isfdb_url, attribution, story_ids.
 */
#[MigrateSource('isfdb_titles')]
class IsfdbTitles extends IsfdbSourceBase {

  public function query() {
    $query = $this->select('titles', 't');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->leftJoin('series', 's', 's.series_id = t.series_id');
    $query->leftJoin('languages', 'l', 'l.lang_id = t.title_language');
    $query->fields('t', ['title_id', 'title_title', 'title_ttype', 'title_seriesnum'])
      ->fields('s', ['series_title'])
      ->fields('l', ['lang_name'])
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      ->condition('t.title_parent', 0)
      ->orderBy('t.title_id');
    // Only works first published in these languages. A collection or omnibus
    // that exists only in translation (an Italian omnibus of two novels, say)
    // is a publication of the books it contains, not a book of his.
    if (!empty($this->configuration['languages'])) {
      $query->condition($query->orConditionGroup()
        ->condition('l.lang_name', $this->configuration['languages'], 'IN')
        ->isNull('l.lang_name'));
    }
    // Whether the work was published as a book of its own: in some
    // publication other than a magazine, under its own title or a variant.
    // A novel that was only ever serialised, such as Field Hospital (New
    // Worlds, later part of Star Surgeon), was never a book, so it's a story.
    //   book_publication: required  - only titles with such a publication.
    //   book_publication: absent    - adds titles of the 'serial_types' that
    //                                 have none, to the 'types' given.
    $types = $this->configuration['types'];
    $mode = $this->configuration['book_publication'] ?? NULL;
    if ($mode) {
      // Titles in a book, and titles with a variant in a book. Lists rather
      // than a subquery per title, which MySQL is slow to run.
      $in_book = $this->select('pub_content', 'bpc');
      $in_book->innerJoin('pubs', 'bp', 'bp.pub_id = bpc.pub_id');
      $in_book->fields('bpc', ['title_id'])
        ->condition('bp.pub_ctype', ['MAGAZINE', 'FANZINE'], 'NOT IN')
        // A NULL would make NOT IN match nothing.
        ->isNotNull('bpc.title_id');
      $variant_in_book = $this->select('titles', 'bv');
      $variant_in_book->fields('bv', ['title_parent'])
        ->condition('bv.title_parent', 0, '>')
        ->condition('bv.title_id', $in_book, 'IN');
      $book = $query->orConditionGroup()
        ->condition('t.title_id', $in_book, 'IN')
        ->condition('t.title_id', $variant_in_book, 'IN');
      $no_book = $query->andConditionGroup()
        ->condition('t.title_id', $in_book, 'NOT IN')
        ->condition('t.title_id', $variant_in_book, 'NOT IN');
    }
    if ($mode === 'required') {
      $query->condition('t.title_ttype', $types, 'IN')->condition($book);
    }
    elseif ($mode === 'absent') {
      $query->condition($query->orConditionGroup()
        ->condition('t.title_ttype', $types, 'IN')
        ->condition($no_book->condition('t.title_ttype', $this->configuration['serial_types'], 'IN')));
    }
    else {
      $query->condition('t.title_ttype', $types, 'IN');
    }
    // Dates are read as text: ISFDB dates such as 1977-03-00 are zeroed by
    // MySQL's strict mode when a query needs a temporary table (DISTINCT).
    $query->addExpression('CAST(t.title_copyright AS CHAR)', 'title_copyright');
    return $query;
  }

  public function fields() {
    return [
      'title_id' => $this->t('ISFDB title ID'),
      'title_title' => $this->t('Title'),
      'title_copyright' => $this->t('First publication date'),
      'title_ttype' => $this->t('Title type'),
      'series_title' => $this->t('Series'),
      'title_seriesnum' => $this->t('Number in series'),
      'lang_name' => $this->t('Language'),
    ];
  }

  public function getIds() {
    return ['title_id' => ['type' => 'integer', 'alias' => 't']];
  }

  public function prepareRow(Row $row) {
    $id = (int) $row->getSourceProperty('title_id');
    $title = Isfdb::text($row->getSourceProperty('title_title'));
    $row->setSourceProperty('title', $title);
    $this->setDate($row, 'title_copyright');
    $row->setSourceProperty('book_type', Isfdb::BOOK_TYPE_MAP[$row->getSourceProperty('title_ttype')] ?? NULL);
    $row->setSourceProperty('series', Isfdb::text($row->getSourceProperty('series_title')) ?: NULL);
    $number = $row->getSourceProperty('title_seriesnum');
    $row->setSourceProperty('series_number', is_numeric($number) && (int) $number > 0 ? (int) $number : NULL);
    $row->setSourceProperty('alt_titles', $this->englishVariants($id, $title));
    $row->setSourceProperty('attribution', Isfdb::attribution('title', $id));

    $stories = [];
    if (!empty($this->configuration['contents'])) {
      // The earliest publication of the title, or any of its variants, that
      // lists the author's stories (translations often have no contents).
      // Only the book's own publications - a novel's novel editions, an
      // omnibus's omnibus editions: an omnibus or collection that reprints a
      // novel lists its other stories too, and they aren't the novel's.
      $query = $this->select('pub_content', 'pc');
      $query->innerJoin('pubs', 'p', 'p.pub_id = pc.pub_id');
      $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
      $query->fields('p', ['pub_id'])
        ->condition('p.pub_ctype', $row->getSourceProperty('title_ttype'))
        ->condition($query->orConditionGroup()->condition('t.title_id', $id)->condition('t.title_parent', $id))
        ->where("CAST(p.pub_year AS CHAR) > '0000-00-00' AND CAST(p.pub_year AS CHAR) < '8888-00-00'")
        ->orderBy('p.pub_year')
        ->orderBy('p.pub_id');
      foreach ($query->execute()->fetchCol() as $pub_id) {
        if ($in_pub = $this->authorTitlesInPub((int) $pub_id, ['SHORTFICTION'])) {
          $stories = $this->parentIds(array_column($in_pub, 'title_id'));
          break;
        }
      }
    }
    $row->setSourceProperty('story_ids', $stories);
    return parent::prepareRow($row);
  }

}
