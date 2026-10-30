<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\source;

use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Base for sources reading a local copy of the ISFDB database.
 *
 * The database is a separate connection, by default under the "isfdb" key
 * (set the source's "key" option to change it). All queries are limited to
 * the author configured in sectorgeneral_isfdb.settings:author_id.
 */
abstract class IsfdbSourceBase extends SqlBase {

  /**
   * The ISFDB author ID whose work is imported.
   */
  protected function authorId(): int {
    $id = (int) \Drupal::config('sectorgeneral_isfdb.settings')->get('author_id');
    if ($id <= 0) {
      throw new MigrateException('Set the ISFDB author ID first: drush sg-isfdb:find-author "James White", then drush cset sectorgeneral_isfdb.settings author_id <id>');
    }
    return $id;
  }

  /**
   * Title IDs credited to the author, of the given ISFDB types.
   *
   * @return int[]
   */
  protected function authorTitleIds(array $types): array {
    $query = $this->select('titles', 't');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->fields('t', ['title_id'])
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      ->condition('t.title_ttype', $types, 'IN');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Distinct parent (canonical) title IDs among the given titles, in the order
   * first seen. Variant titles and translations map to their parent.
   *
   * @return int[]
   */
  protected function parentIds(array $title_ids): array {
    if (!$title_ids) {
      return [];
    }
    $rows = $this->select('titles', 't')
      ->fields('t', ['title_id', 'title_parent'])
      ->condition('t.title_id', $title_ids, 'IN')
      ->execute()->fetchAllKeyed();
    $parents = [];
    foreach ($title_ids as $id) {
      $parents[] = (int) ($rows[$id] ?: $id);
    }
    return array_values(array_unique($parents));
  }

  /**
   * The author's titles of the given types in a publication, in page order.
   *
   * @return array[]
   *   Rows with title_id, title_parent, title_title, lang_name, note_id.
   */
  protected function authorTitlesInPub(int $pub_id, array $types): array {
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->leftJoin('languages', 'l', 'l.lang_id = t.title_language');
    $query->fields('t', ['title_id', 'title_parent', 'title_title', 'note_id'])
      ->fields('l', ['lang_name'])
      ->condition('pc.pub_id', $pub_id)
      ->condition('ca.author_id', $this->authorId())
      ->condition('ca.ca_status', 1)
      ->condition('t.title_ttype', $types, 'IN');
    $query->addExpression('CAST(pc.pubc_page AS UNSIGNED)', 'page_order');
    $query->orderBy('page_order')->orderBy('t.title_id');
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Names credited for titles of one type in a publication, e.g. COVERART.
   *
   * @return string[]
   */
  protected function creditsInPub(int $pub_id, string|array $type, bool $exclude_author = FALSE): array {
    $query = $this->select('pub_content', 'pc');
    $query->innerJoin('titles', 't', 't.title_id = pc.title_id');
    $query->innerJoin('canonical_author', 'ca', 'ca.title_id = t.title_id');
    $query->innerJoin('authors', 'a', 'a.author_id = ca.author_id');
    $query->fields('a', ['author_canonical'])
      ->condition('pc.pub_id', $pub_id)
      ->condition('ca.ca_status', 1)
      ->condition('t.title_ttype', (array) $type, 'IN');
    if ($exclude_author) {
      $query->condition('ca.author_id', $this->authorId(), '<>');
    }
    $query->orderBy('pc.pubc_id');
    return $this->uniqueNames($query->execute()->fetchCol());
  }

  /**
   * Names of a title's credited authors, e.g. an anthology's editors.
   *
   * @return string[]
   */
  protected function titleAuthors(int $title_id): array {
    $query = $this->select('canonical_author', 'ca');
    $query->innerJoin('authors', 'a', 'a.author_id = ca.author_id');
    $query->fields('a', ['author_canonical'])
      ->condition('ca.title_id', $title_id)
      ->condition('ca.ca_status', 1)
      ->orderBy('ca.ca_id');
    return $this->uniqueNames($query->execute()->fetchCol());
  }

  /**
   * English variant titles of a title, for "Alternative titles".
   *
   * @return string[]
   */
  protected function englishVariants(int $title_id, string $own_title): array {
    $query = $this->select('titles', 't');
    $query->leftJoin('languages', 'l', 'l.lang_id = t.title_language');
    $query->fields('t', ['title_title'])
      ->condition('t.title_parent', $title_id)
      ->condition($query->orConditionGroup()->condition('l.lang_name', 'English')->isNull('l.lang_name'));
    $own = Isfdb::normalizeTitle($own_title);
    $variants = [];
    foreach ($query->execute()->fetchCol() as $title) {
      $title = Isfdb::text($title);
      if (Isfdb::normalizeTitle($title) !== $own) {
        $variants[Isfdb::normalizeTitle($title)] = $title;
      }
    }
    return array_values($variants);
  }

  protected function note(?int $note_id): string {
    if (!$note_id) {
      return '';
    }
    $note = $this->select('notes', 'n')->fields('n', ['note_note'])->condition('n.note_id', $note_id)->execute()->fetchField();
    return Isfdb::noteText($note ?: '');
  }

  /**
   * External identifiers of a publication, keyed by identifier type name.
   */
  protected function identifiers(int $pub_id): array {
    $query = $this->select('identifiers', 'i');
    $query->innerJoin('identifier_types', 'it', 'it.identifier_type_id = i.identifier_type_id');
    $query->fields('it', ['identifier_type_name'])->fields('i', ['identifier_value'])->condition('i.pub_id', $pub_id);
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Decoded, de-duplicated names.
   */
  protected function uniqueNames(array $names): array {
    return array_values(array_unique(array_filter(array_map([Isfdb::class, 'text'], $names))));
  }

  /**
   * Sets the parsed date of an ISFDB date column on the row.
   */
  protected function setDate(Row $row, string $column, string $prefix = ''): void {
    [$year, $display] = Isfdb::date($row->getSourceProperty($column));
    $row->setSourceProperty($prefix . 'year', $year);
    $row->setSourceProperty($prefix . 'date_display', $display);
  }

}
