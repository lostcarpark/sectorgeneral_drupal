<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Maps a list of ISFDB IDs to node IDs through other migrations' maps.
 *
 * "migration" may be one migration or a list, tried in order.
 *
 * Unlike core's migration_lookup, takes a list of single IDs and returns the
 * list of destination IDs found, in order, without creating stubs.
 *
 * @code
 * field_contents:
 *   plugin: isfdb_lookup
 *   source: story_ids
 *   migration: isfdb_stories
 * @endcode
 */
#[MigrateProcess(id: 'isfdb_lookup', handle_multiples: TRUE)]
class IsfdbLookup extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MigrationPluginManagerInterface $migrationManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.migration'));
  }

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $maps = array_map(fn($id) => $this->migrationManager->createInstance($id)->getIdMap(), (array) $this->configuration['migration']);
    $found = [];
    foreach ((array) $value as $id) {
      // The first migration that knows this ID wins.
      foreach ($maps as $map) {
        if ($nid = $map->lookupDestinationIds([$id])[0][0] ?? NULL) {
          $found[] = (int) $nid;
          break;
        }
      }
    }
    return array_values(array_unique($found));
  }

}
