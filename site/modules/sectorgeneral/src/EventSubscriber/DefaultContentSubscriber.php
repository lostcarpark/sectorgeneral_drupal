<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\EventSubscriber;

use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\path\Plugin\Field\FieldType\PathItem;
use Drupal\pathauto\PathautoItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps Pathauto's per-item setting in exported content.
 *
 * Core exports only the alias. On import Pathauto then treats every entity as
 * "generate automatically", which drops hand-set aliases where no pattern
 * exists (the basic pages). Exporting the flag keeps manual aliases manual.
 */
class DefaultContentSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After core's path subscriber, so this callback replaces its one.
    return [PreExportEvent::class => ['preExport', -10]];
  }

  /**
   * Exports path items with their Pathauto state.
   */
  public function preExport(PreExportEvent $event): void {
    $event->setCallback('field_item:path', function (PathItem $item): array {
      $values = $item->getValue();
      // Never export the path ID; it is recreated on import.
      unset($values['pid']);
      if ($item instanceof PathautoItem) {
        $values['pathauto'] = (int) $item->get('pathauto')->getValue();
      }
      return $values;
    });
  }

}
