<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Isfdb;

/**
 * Finds or creates a taxonomy term by name.
 *
 * Names are matched through an alias list in sectorgeneral_isfdb.settings,
 * then ignoring case, leading articles and punctuation, then by reduced name
 * (see Isfdb::normalizeName()). A new term is created
 * only when nothing matches; each is recorded for the report.
 *
 * Configuration:
 * - vocabulary: vocabulary ID.
 * - aliases: (optional) settings key holding ISFDB name → term name.
 *
 * Accepts one name or a list, returning one term ID or a list.
 *
 * @code
 * field_publisher:
 *   plugin: isfdb_term
 *   source: publisher
 *   vocabulary: publisher
 *   aliases: publisher_aliases
 * @endcode
 */
#[MigrateProcess(id: 'isfdb_term', handle_multiples: TRUE)]
class IsfdbTerm extends ProcessPluginBase {

  /**
   * Term IDs by vocabulary and normalised name.
   */
  protected static array $index = [];

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (is_array($value)) {
      return array_values(array_filter(array_map(fn($name) => $this->transform($name, $migrate_executable, $row, $destination_property), $value)));
    }
    $name = Isfdb::text(is_scalar($value) ? (string) $value : '');
    if ($name === '') {
      return NULL;
    }
    $vid = $this->configuration['vocabulary'];
    if (!empty($this->configuration['aliases'])) {
      $aliases = \Drupal::config('sectorgeneral_isfdb.settings')->get($this->configuration['aliases']) ?? [];
      $name = $aliases[$name] ?? $name;
    }
    $index = $this->index($vid);
    foreach ([Isfdb::normalizeTitle($name), Isfdb::normalizeName($name, $vid)] as $key) {
      if (!empty($index[$key])) {
        return $index[$key];
      }
    }
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create(['vid' => $vid, 'name' => $name]);
    $term->save();
    static::$index[$vid][Isfdb::normalizeTitle($name)] = (int) $term->id();
    static::$index[$vid][Isfdb::normalizeName($name, $vid)] ??= (int) $term->id();
    $created = \Drupal::state()->get('sectorgeneral_isfdb.created_terms', []);
    $created[$vid][(int) $term->id()] = $name;
    \Drupal::state()->set('sectorgeneral_isfdb.created_terms', $created);
    return (int) $term->id();
  }

  /**
   * Term IDs keyed by the ways a name can be compared.
   *
   * Each term is indexed by its normalised title, its reduced name, and the
   * reduced name of each part of a combined name such as "Futura/Orbit". A key
   * shared by two different terms is ambiguous and stored as 0 (no match).
   */
  protected function index(string $vid): array {
    if (!isset(static::$index[$vid])) {
      static::$index[$vid] = [];
      $exact = [];
      $loose = [];
      foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $term) {
        $tid = (int) $term->id();
        $exact[Isfdb::normalizeTitle($term->label())] = $tid;
        $keys = [Isfdb::normalizeName($term->label(), $vid)];
        if (str_contains($term->label(), '/')) {
          foreach (explode('/', explode(',', $term->label())[0]) as $part) {
            $keys[] = Isfdb::normalizeName($part, $vid);
          }
        }
        foreach (array_unique($keys) as $key) {
          $loose[$key] = isset($loose[$key]) && $loose[$key] !== $tid ? 0 : $tid;
        }
      }
      // Exact keys win over reduced ones.
      static::$index[$vid] = $exact + $loose;
    }
    return static::$index[$vid];
  }

}
