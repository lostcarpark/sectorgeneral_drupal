<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Merges duplicate nodes and terms left by the ISFDB import.
 *
 * The kept entity's values always win; the duplicate only fills gaps.
 * References, the import's ID maps and the duplicate's URL all move to the
 * kept entity, so nothing points at the deleted one.
 */
class Merger {

  /**
   * Vocabularies whose merged names become import aliases.
   */
  protected const ALIAS_KEYS = ['publisher' => 'publisher_aliases', 'magazine' => 'magazine_aliases'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected MigrationPluginManagerInterface $migrationManager,
    protected AliasManagerInterface $aliasManager,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {}

  /**
   * Node fields that reference entities of the given type.
   *
   * @return array[]
   *   [entity type, field name] pairs.
   */
  public function referenceFields(string $target_type): array {
    $fields = [];
    foreach (['node', 'media', 'taxonomy_term'] as $entity_type) {
      foreach ($this->entityFieldManager->getFieldStorageDefinitions($entity_type) as $name => $storage) {
        if ($storage->getType() === 'entity_reference' && $storage->getSetting('target_type') === $target_type && str_starts_with($name, 'field_')) {
          $fields[] = [$entity_type, $name];
        }
      }
    }
    return $fields;
  }

  /**
   * How many entities reference the given entity.
   */
  public function countReferences(string $target_type, int $id): int {
    $count = 0;
    foreach ($this->referenceFields($target_type) as [$entity_type, $field]) {
      $count += (int) $this->entityTypeManager->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->condition($field, $id)->count()->execute();
    }
    return $count;
  }

  /**
   * Points every reference to $from at $to instead, without duplicating.
   *
   * @return int
   *   The number of entities changed.
   */
  public function replaceReferences(string $target_type, int $from, int $to, string $message): int {
    $changed = 0;
    foreach ($this->referenceFields($target_type) as [$entity_type, $field]) {
      foreach ($this->entityTypeManager->getStorage($entity_type)->loadByProperties([$field => $from]) as $entity) {
        $ids = array_map('intval', array_column($entity->get($field)->getValue(), 'target_id'));
        $ids = array_values(array_unique(array_map(fn($id) => $id === $from ? $to : $id, $ids)));
        $entity->set($field, $ids);
        $this->saveRevision($entity, $message);
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * Merges $duplicate into $keep, then deletes $duplicate.
   *
   * @param bool $any_type
   *   Allow a node of another type, such as a book that should have been a
   *   story. Only fields both types have are filled.
   *
   * @return string[]
   *   What happened, for the command output.
   */
  public function mergeNodes(NodeInterface $keep, NodeInterface $duplicate, bool $any_type = FALSE): array {
    if (!$any_type && $keep->bundle() !== $duplicate->bundle()) {
      throw new \InvalidArgumentException("Can't merge a {$duplicate->bundle()} into a {$keep->bundle()}.");
    }
    $log = $this->fillFrom($keep, $duplicate);
    $message = "Merged duplicate node {$duplicate->id()} (\"{$duplicate->label()}\").";
    $this->saveRevision($keep, $message);
    $log[] = sprintf('updated references on %d nodes', $this->replaceReferences('node', (int) $duplicate->id(), (int) $keep->id(), $message));
    $log[] = sprintf('moved %d import map entries', $this->moveMapEntries((int) $duplicate->id(), (int) $keep->id()));
    $this->redirect('/node/' . $duplicate->id(), 'entity:node/' . $keep->id());
    $created = $this->state->get('sectorgeneral_isfdb.created_node');
    if (is_array($created)) {
      unset($created[(int) $duplicate->id()]);
      $this->state->set('sectorgeneral_isfdb.created_node', $created);
    }
    $duplicate->delete();
    $log[] = "deleted node {$duplicate->id()}; its address redirects to node {$keep->id()}";
    return $log;
  }

  /**
   * Merges term $duplicate into $keep, then deletes $duplicate.
   */
  public function mergeTerms(TermInterface $keep, TermInterface $duplicate): array {
    if ($keep->bundle() !== $duplicate->bundle()) {
      throw new \InvalidArgumentException("Can't merge a {$duplicate->bundle()} term into a {$keep->bundle()} term.");
    }
    $log = $this->fillFrom($keep, $duplicate);
    $keep->save();
    $message = "Merged duplicate term \"{$duplicate->label()}\" into \"{$keep->label()}\".";
    $log[] = sprintf('updated references on %d items', $this->replaceReferences('taxonomy_term', (int) $duplicate->id(), (int) $keep->id(), $message));
    if ($key = self::ALIAS_KEYS[$keep->bundle()] ?? NULL) {
      $config = $this->configFactory->getEditable('sectorgeneral_isfdb.settings');
      $aliases = $config->get($key) ?? [];
      $aliases[$duplicate->label()] = $keep->label();
      $config->set($key, $aliases)->save();
      $log[] = "added import alias \"{$duplicate->label()}\" → \"{$keep->label()}\"";
    }
    $created = $this->state->get('sectorgeneral_isfdb.created_terms', []);
    unset($created[$duplicate->bundle()][$duplicate->id()]);
    $this->state->set('sectorgeneral_isfdb.created_terms', $created);
    $this->redirect('/taxonomy/term/' . $duplicate->id(), 'entity:taxonomy_term/' . $keep->id());
    $duplicate->delete();
    $log[] = "deleted term {$duplicate->id()}";
    return $log;
  }

  /**
   * Deletes terms the import created that nothing references any more.
   *
   * @return string[]
   *   Names of the deleted terms.
   */
  public function deleteUnusedCreatedTerms(): array {
    $created = $this->state->get('sectorgeneral_isfdb.created_terms', []);
    $deleted = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($created as $vid => $terms) {
      foreach ($terms as $tid => $name) {
        $term = $storage->load($tid);
        if ($term && $this->countReferences('taxonomy_term', (int) $tid)) {
          // Still in use (e.g. filled into content the site already had).
          continue;
        }
        if ($term) {
          $term->delete();
          $deleted[] = "$vid: $name";
        }
        unset($created[$vid][$tid]);
      }
    }
    $this->state->set('sectorgeneral_isfdb.created_terms', array_filter($created));
    return $deleted;
  }

  /**
   * Copies $from's values into $to's empty fields and adds missing list items.
   */
  protected function fillFrom(ContentEntityInterface $to, ContentEntityInterface $from): array {
    $log = [];
    foreach ($from->getFields() as $name => $items) {
      if ((!str_starts_with($name, 'field_') && !in_array($name, ['body', 'description'], TRUE)) || $items->isEmpty() || !$to->hasField($name)) {
        continue;
      }
      $target = $to->get($name);
      $multiple = $target->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
      if ($target->isEmpty()) {
        $target->setValue($items->getValue());
        $log[] = "filled {$name}";
      }
      elseif ($name === 'field_data_sources') {
        if (!str_contains((string) $target->value, (string) $items->value)) {
          $target->value = trim($target->value . "\n" . $items->value);
          $log[] = 'added data sources';
        }
      }
      elseif ($multiple) {
        $have = array_map('serialize', $target->getValue());
        foreach ($items->getValue() as $value) {
          if (!in_array(serialize($value), $have, TRUE)) {
            $target->appendItem($value);
            $log[] = "added to {$name}";
          }
        }
      }
    }
    return array_values(array_unique($log));
  }

  /**
   * Repoints the ISFDB import's map entries from one node to another, so a
   * re-run updates the kept node instead of recreating the duplicate.
   */
  protected function moveMapEntries(int $from, int $to): int {
    $moved = 0;
    foreach ($this->migrationManager->createInstancesByTag('isfdb') as $migration) {
      $map = $migration->getIdMap();
      if ($map instanceof Sql && $map->getDatabase()->schema()->tableExists($map->mapTableName())) {
        // The kept node existed before the import: rollback must leave it be.
        $moved += $map->getDatabase()->update($map->mapTableName())
          ->fields(['destid1' => $to, 'rollback_action' => MigrateIdMapInterface::ROLLBACK_PRESERVE])
          ->condition('destid1', $from)
          ->execute();
      }
    }
    return $moved;
  }

  protected function redirect(string $system_path, string $target): void {
    $alias = $this->aliasManager->getAliasByPath($system_path);
    $storage = $this->entityTypeManager->getStorage('redirect');
    foreach (array_unique([$alias, $system_path]) as $source) {
      if (!$storage->loadByProperties(['redirect_source__path' => ltrim($source, '/')])) {
        $storage->create(['redirect_source' => ltrim($source, '/'), 'redirect_redirect' => $target, 'status_code' => 301, 'language' => 'und'])->save();
      }
    }
  }

  protected function saveRevision(ContentEntityInterface $entity, string $message): void {
    if ($entity instanceof NodeInterface) {
      $entity->setNewRevision(TRUE);
      $entity->setRevisionLogMessage($message);
    }
    $entity->save();
  }

}
