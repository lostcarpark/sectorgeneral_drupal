<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\migrate\id_map\Sql;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\sectorgeneral_isfdb\Plugin\Derivative\FillEntityDeriver;

/**
 * Creates entities, or fills in existing ones without overwriting.
 *
 * For an existing entity (the row sets its ID):
 * - an empty field takes the imported value;
 * - a "merge" field gains any imported values it doesn't already have;
 * - an "append" text field gains the imported text if it isn't there yet;
 * - any other field that already has a different value is left alone, and
 *   the difference is logged as a migrate message for editors to review.
 * Changes are saved as a new revision. Rollback deletes only entities the
 * migration created; filled-in entities are kept (revert via revisions).
 *
 * Use as sg_fill:node or sg_fill:taxonomy_term. Configuration:
 * - merge: multi-value fields to merge rather than fill.
 * - append: text fields to append to.
 * - ignore_conflicts: fields whose differences aren't worth reporting.
 * - refresh_created: fields to update on entities this import created.
 */
#[MigrateDestination(id: 'sg_fill', deriver: FillEntityDeriver::class)]
class FillEntity extends EntityContentBase {

  /**
   * Whether the row being imported updated an existing entity.
   */
  protected bool $updated = FALSE;

  public function import(Row $row, array $old_destination_id_values = []) {
    $this->updated = FALSE;
    $ids = parent::import($row, $old_destination_id_values);
    if (!$this->updated && $ids) {
      $created = $this->createdIds();
      $created[(int) reset($ids)] = TRUE;
      $this->saveCreatedIds($created);
    }
    return $ids;
  }

  /**
   * Deletes only entities this import created.
   *
   * Core decides from the ID map, which can't be trusted once two records
   * point at one entity (after sg-isfdb:merge-nodes): rolling back the first
   * removes both map rows, and core then deletes the entity for the second.
   * Content that existed before the import must never be deleted here.
   */
  public function rollback(array $destination_identifier) {
    $id = (int) reset($destination_identifier);
    $created = $this->createdIds();
    if (!isset($created[$id])) {
      return;
    }
    parent::rollback($destination_identifier);
    unset($created[$id]);
    $this->saveCreatedIds($created);
  }

  /**
   * IDs of entities of this type the ISFDB import created.
   *
   * Built from the import's ID maps the first time, for imports run before
   * this list was kept.
   */
  protected function createdIds(): array {
    $key = 'sectorgeneral_isfdb.created_' . $this->storage->getEntityTypeId();
    $created = \Drupal::state()->get($key);
    if ($created === NULL) {
      $created = [];
      foreach (\Drupal::service('plugin.manager.migration')->createInstancesByTag('isfdb') as $migration) {
        $map = $migration->getIdMap();
        $destination = $migration->getDestinationConfiguration()['plugin'] ?? '';
        if ($destination === 'sg_fill:' . $this->storage->getEntityTypeId() && $map instanceof Sql && $map->getDatabase()->schema()->tableExists($map->mapTableName())) {
          $ids = $map->getDatabase()->select($map->mapTableName(), 'm')->fields('m', ['destid1'])
            ->condition('rollback_action', MigrateIdMapInterface::ROLLBACK_DELETE)->isNotNull('destid1')->execute()->fetchCol();
          $created += array_fill_keys(array_map('intval', $ids), TRUE);
        }
      }
      $this->saveCreatedIds($created);
    }
    return $created;
  }

  protected function saveCreatedIds(array $created): void {
    \Drupal::state()->set('sectorgeneral_isfdb.created_' . $this->storage->getEntityTypeId(), $created);
  }

  protected function updateEntity(EntityInterface $entity, Row $row) {
    $this->updated = TRUE;
    assert($entity instanceof ContentEntityInterface);
    $merge = $this->configuration['merge'] ?? [];
    // Fields the import may update on content it created itself, e.g. titles
    // it builds; content the site already had is never overwritten.
    $refresh = isset($this->createdIds()[(int) $entity->id()]) ? ($this->configuration['refresh_created'] ?? []) : [];
    $append = $this->configuration['append'] ?? [];
    $quiet = array_merge(['nid', 'type', 'uid', 'status'], $this->configuration['ignore_conflicts'] ?? []);
    $changed = FALSE;

    foreach ($row->getDestination() as $field_name => $values) {
      if (str_starts_with($field_name, '_') || !$entity->hasField($field_name) || $values === NULL || $values === [] || $values === '') {
        continue;
      }
      $field = $entity->get($field_name);
      $incoming = $this->normalise($values);
      if (!$incoming) {
        continue;
      }
      $current = $this->normalise($field->getValue());

      if ($field->isEmpty() || (in_array($field_name, $refresh, TRUE) && $incoming != $current)) {
        $field->setValue($values);
        $changed = TRUE;
      }
      elseif (in_array($field_name, $merge, TRUE)) {
        foreach (array_diff($incoming, $current) as $value) {
          $field->appendItem($value);
          $changed = TRUE;
        }
      }
      elseif (in_array($field_name, $append, TRUE)) {
        $text = (string) $field->value;
        $add = (string) reset($incoming);
        if (!str_contains($text, $add)) {
          $field->value = trim($text . "\n" . $add);
          $changed = TRUE;
        }
      }
      elseif ($incoming != $current && !in_array($field_name, $quiet, TRUE)) {
        $this->conflict($row, $entity, $field_name, $current, $incoming);
      }
    }

    if ($changed && $entity instanceof RevisionLogInterface) {
      $entity->setNewRevision(TRUE);
      $entity->setRevisionLogMessage('Filled in from ISFDB: ' . implode(' ', (array) $row->getSourceProperty('attribution')));
    }
    $this->setRollbackAction($row->getIdMap(), MigrateIdMapInterface::ROLLBACK_PRESERVE);
    return $entity;
  }

  /**
   * Reduces field values to a comparable list of main values.
   */
  protected function normalise($values): array {
    if (!is_array($values)) {
      $values = [$values];
    }
    $out = [];
    foreach ($values as $value) {
      if (is_array($value)) {
        $value = $value['target_id'] ?? $value['value'] ?? $value['uri'] ?? reset($value);
      }
      if ($value !== NULL && $value !== '') {
        $out[] = is_string($value) ? trim($value) : (string) $value;
      }
    }
    return array_values(array_unique($out));
  }

  protected function conflict(Row $row, EntityInterface $entity, string $field_name, array $current, array $incoming): void {
    $label = $entity->getFieldDefinition($field_name)?->getLabel() ?? $field_name;
    $show = fn(array $values) => implode('; ', array_map(fn($v) => $this->describe($field_name, $entity, $v), $values));
    $message = sprintf('Conflict: "%s" (%s/%s) %s is "%s" here, ISFDB has "%s". %s',
      $entity->label(), $entity->getEntityTypeId(), $entity->id(), $label, $show($current), $show($incoming),
      implode(' ', (array) $row->getSourceProperty('attribution')));
    $this->migration->getIdMap()->saveMessage($row->getSourceIdValues(), $message, MigrationInterface::MESSAGE_NOTICE);
  }

  /**
   * Shows referenced entities by label rather than ID.
   */
  protected function describe(string $field_name, EntityInterface $entity, string $value): string {
    $definition = $entity->getFieldDefinition($field_name);
    if ($definition?->getType() === 'entity_reference' && ctype_digit($value)) {
      $target = \Drupal::entityTypeManager()->getStorage($definition->getSetting('target_type'))->load($value);
      return $target ? $target->label() : $value;
    }
    return $value;
  }

}
