<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Magazine issues containing the author's short fiction.
 *
 * Source properties added: title, magazine, issue, year, date_display,
 * story_ids, content_ids (stories and serialised books), editors,
 * cover_artists, interior_artists (illustrators of James White's work only),
 * other_contributors, published_as (titles as printed), language,
 * regional_edition, price, pages,
 * image_url, isfdb_url, attribution.
 */
#[MigrateSource('isfdb_magazine_issues')]
class IsfdbMagazineIssues extends IsfdbSourceBase {

  public function query() {
    $query = $this->select('pubs', 'p');
    $query->innerJoin('pub_content', 'pc', 'pc.pub_id = p.pub_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = pc.title_id');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->fields('p', ['pub_id', 'pub_title', 'pub_price', 'pub_pages', 'pub_frontimage'])
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      // Stories, and instalments of serialised novels and stories.
      ->condition('t.title_ttype', ['SHORTFICTION', 'SERIAL'], 'IN')
      ->condition('p.pub_ctype', 'MAGAZINE')
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
      'pub_title' => $this->t('Issue title'),
      'pub_year' => $this->t('Date'),
    ];
  }

  public function getIds() {
    return ['pub_id' => ['type' => 'integer', 'alias' => 'p']];
  }

  public function prepareRow(Row $row) {
    $id = (int) $row->getSourceProperty('pub_id');
    $pub_title = Isfdb::text($row->getSourceProperty('pub_title'));
    $this->setDate($row, 'pub_year');

    // The magazine is the series of the issue's EDITOR record; failing that,
    // the issue title before its number or date.
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('series', 's', 's.series_id = t.series_id');
    $query->fields('s', ['series_title'])->condition('pc.pub_id', $id)->condition('t.title_ttype', 'EDITOR')->range(0, 1);
    $magazine = Isfdb::text($query->execute()->fetchField() ?: '') ?: trim(preg_split('/,|\s#|\s-\s/', $pub_title)[0]);
    // Use the site's name for the magazine, as the issue title shows it:
    // an alias, else an existing term with the same reduced name.
    $aliases = \Drupal::config('sectorgeneral_isfdb.settings')->get('magazine_aliases') ?? [];
    $magazine = $aliases[$magazine] ?? $magazine;
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'magazine']) as $term) {
      if (Isfdb::normalizeName($term->label(), 'magazine') === Isfdb::normalizeName($magazine, 'magazine')) {
        $magazine = $term->label();
        break;
      }
    }
    $issue = preg_match('/#\s*(\d+)/', $pub_title, $m) ? $m[1] : NULL;
    $date = $row->getSourceProperty('date_display');
    $edition = $this->regionalEdition($id, (string) $row->getSourceProperty('pub_year'), Isfdb::text($row->getSourceProperty('pub_price')), $magazine);
    $row->setSourceProperty('regional_edition', $edition);
    if ($edition) {
      $date .= " ($edition)";
    }

    $stories = $this->authorTitlesInPub($id, ['SHORTFICTION']);
    // A serial instalment's parent is the novel or story it is part of.
    $works = $this->authorTitlesInPub($id, ['SHORTFICTION', 'SERIAL']);
    $row->setSourceProperty('title', $issue ? "$magazine $issue, $date" : "$magazine, $date");
    $row->setSourceProperty('magazine', $magazine);
    $row->setSourceProperty('issue', $issue);
    $row->setSourceProperty('story_ids', $this->parentIds(array_column($stories, 'title_id')));
    $row->setSourceProperty('content_ids', $this->parentIds(array_column($works, 'title_id')));
    // Titles as printed, where they differ from the work's own title
    // (translations, serial parts such as "Turistplaneten (Part 1 of 5)").
    $row->setSourceProperty('published_as', $this->uniqueNames(array_column(array_filter($works, fn($w) => (int) $w['title_parent'] > 0), 'title_title')));
    $row->setSourceProperty('language', $works ? (Isfdb::text($works[0]['lang_name'] ?? '') ?: NULL) : NULL);
    $row->setSourceProperty('other_contributors', $this->creditsInPub($id, ['SHORTFICTION', 'SERIAL', 'POEM'], TRUE));
    $row->setSourceProperty('editors', $this->creditsInPub($id, 'EDITOR'));
    $row->setSourceProperty('cover_artists', $this->creditsInPub($id, 'COVERART'));
    $row->setSourceProperty('interior_artists', $this->illustratorsOf($id, array_column($works, 'title_title')));
    $row->setSourceProperty('price', Isfdb::text($row->getSourceProperty('pub_price')) ?: NULL);
    $row->setSourceProperty('pages', Isfdb::pages($row->getSourceProperty('pub_pages')));
    $row->setSourceProperty('image_url', Isfdb::text($row->getSourceProperty('pub_frontimage')) ?: NULL);
    $row->setSourceProperty('isfdb_url', Isfdb::url('publication', $id));
    $row->setSourceProperty('attribution', Isfdb::attribution('publication', $id));
    return parent::prepareRow($row);
  }

  /**
   * "UK edition", "US edition"…, when the same magazine has another
   * issue for the same month priced in a different currency.
   *
   * American magazines such as Galaxy had British reprint editions with the
   * same date and often the same cover; the price tells them apart.
   */
  protected function regionalEdition(int $pub_id, string $date, string $price, string $magazine): ?string {
    $mine = Isfdb::currencyEdition($price);
    if (!$mine || strlen($date) < 7) {
      return NULL;
    }
    // Other magazine issues dated the same month whose magazine comes down to
    // the same name. ISFDB may file a UK edition as a series of its own, e.g.
    // "Galaxy Science Fiction (UK)", so compare names rather than series.
    $query = $this->select('pubs', 'p');
    $query->innerJoin('pub_content', 'pc', 'pc.pub_id = p.pub_id');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('series', 's', 's.series_id = t.series_id');
    $query->fields('p', ['pub_price'])
      ->fields('s', ['series_title'])
      ->condition('p.pub_ctype', 'MAGAZINE')
      ->condition('t.title_ttype', 'EDITOR')
      ->condition('p.pub_id', $pub_id, '<>')
      ->where('SUBSTRING(CAST(p.pub_year AS CHAR), 1, 7) = :month', [':month' => substr($date, 0, 7)]);
    $name = Isfdb::normalizeName($magazine, 'magazine');
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $other) {
      if (Isfdb::normalizeName(Isfdb::text($other['series_title']), 'magazine') !== $name) {
        continue;
      }
      $theirs = Isfdb::currencyEdition(Isfdb::text($other['pub_price']));
      if ($theirs && $theirs !== $mine) {
        return $mine;
      }
    }
    return NULL;
  }

  /**
   * Artists of the issue's interior art for the given works.
   *
   * ISFDB names interior art after what it illustrates, so an illustration
   * whose title matches one of the author's works (ignoring "(Part 2 of 3)")
   * is his story's.
   *
   * @return string[]
   */
  protected function illustratorsOf(int $pub_id, array $titles): array {
    $base = fn(string $t) => Isfdb::normalizeTitle(preg_replace('/\s*\(part\s+\d+\s+of\s+\d+\)\s*$/i', '', Isfdb::text($t)));
    $wanted = array_map($base, $titles);
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->innerJoin('authors', 'a', 'a.author_id = ca.author_id');
    $query->fields('t', ['title_title'])->fields('a', ['author_canonical'])
      ->condition('pc.pub_id', $pub_id)
      ->condition('ca.ca_status', 1)
      ->condition('t.title_ttype', 'INTERIORART')
      ->orderBy('pc.pubc_id');
    $names = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $art) {
      if (in_array($base((string) $art['title_title']), $wanted, TRUE)) {
        $names[] = $art['author_canonical'];
      }
    }
    return $this->uniqueNames($names);
  }

}
