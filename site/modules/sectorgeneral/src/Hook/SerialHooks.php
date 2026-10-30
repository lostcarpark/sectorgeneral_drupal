<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * "Part N of M" with previous and next links on serial instalments.
 *
 * Worked out from the data, so nothing needs entering: the parts of a serial
 * are the issues of the same magazine (and the same country's edition of it)
 * that contain the same story or book, in date order. A gap of more than a year starts a new run, so a later
 * reprint in the same magazine isn't counted as another part.
 */
class SerialHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra['node']['magazine_issue']['display']['serial_parts'] = [
      'label' => $this->t('Serial parts'),
      'description' => $this->t('For a serial instalment: which part this is, with links to the others.'),
      'weight' => 20,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for nodes.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($node->bundle() !== 'magazine_issue' || !$display->getComponent('serial_parts')) {
      return;
    }
    $cache = new CacheableMetadata();
    $cache->addCacheTags(['node_list:magazine_issue']);
    $items = [];
    foreach ($node->get('field_contents')->referencedEntities() as $work) {
      $parts = $this->parts($node, $work);
      if (count($parts) < 2) {
        continue;
      }
      $position = array_search((int) $node->id(), array_map(fn($p) => (int) $p->id(), $parts), TRUE);
      $links = [];
      if ($position > 0) {
        $links[] = ['#type' => 'link', '#title' => '‹ ' . $parts[$position - 1]->label(), '#url' => $parts[$position - 1]->toUrl()];
      }
      if ($position < count($parts) - 1) {
        $links[] = ['#type' => 'link', '#title' => $parts[$position + 1]->label() . ' ›', '#url' => $parts[$position + 1]->toUrl()];
      }
      // The title as printed in this issue, if recorded and different.
      $printed = $this->printedTitle($node, $work);
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<p class="serial-parts"><strong>{{ work }}</strong>{% if original %} ({{ original }}){% endif %}: {{ part }}{% if links %} · {{ links|safe_join(" · ") }}{% endif %}</p>',
        '#context' => [
          'work' => ['#type' => 'link', '#title' => $printed ?? $work->label(), '#url' => $work->toUrl()],
          'original' => $printed && $printed !== $work->label() ? $work->label() : NULL,
          'part' => $this->t('part @n of @m', ['@n' => $position + 1, '@m' => count($parts)]),
          'links' => $links,
        ],
      ];
      $cache->addCacheableDependency($work);
    }
    if ($items) {
      $build['serial_parts'] = $items;
    }
    $cache->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
  }

  /**
   * The issues making up this issue's run of a serial, in order.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function parts(NodeInterface $issue, NodeInterface $work): array {
    $magazine = $issue->get('field_magazine')->target_id;
    // A British reprint edition is its own run of the serial.
    $edition = (string) ($issue->hasField('field_regional_edition') ? $issue->get('field_regional_edition')->value : '');
    $issues = array_filter(
      $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'magazine_issue', 'field_contents' => $work->id()]),
      fn(NodeInterface $i) => $i->get('field_magazine')->target_id == $magazine && (string) $i->get('field_regional_edition')->value === $edition,
    );
    $key = fn(NodeInterface $i) => [
      (string) ($i->hasField('field_sort_date') ? $i->get('field_sort_date')->value : '') ?: sprintf('%04d-%02d', (int) $i->get('field_year')->value, $this->month($i)),
      (int) preg_replace('/\D/', '', (string) $i->get('field_issue_number')->value),
      (int) $i->id(),
    ];
    usort($issues, fn($a, $b) => $key($a) <=> $key($b));
    // Split into runs wherever the year jumps by more than one.
    $runs = [[]];
    foreach ($issues as $i) {
      $last = end($runs[count($runs) - 1]);
      if ($last && (int) $i->get('field_year')->value - (int) $last->get('field_year')->value > 1) {
        $runs[] = [];
      }
      $runs[count($runs) - 1][] = $i;
    }
    foreach ($runs as $run) {
      if (in_array((int) $issue->id(), array_map(fn($i) => (int) $i->id(), $run), TRUE)) {
        // Where every issue says which part it carries ("(Part 2 of 5)"),
        // that is the order.
        $numbers = array_map(fn($i) => $this->partNumber($i, $work), $run);
        if (!in_array(NULL, $numbers, TRUE) && count(array_unique($numbers)) === count($numbers)) {
          array_multisort($numbers, $run);
        }
        return array_values($run);
      }
    }
    return [];
  }

  /**
   * The month an issue is dated, from its date as shown (0 if none).
   */
  protected function month(NodeInterface $issue): int {
    if (preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December)/i', (string) $issue->get('field_date_display')->value, $m)) {
      return (int) date('n', strtotime($m[1] . ' 1 2000'));
    }
    return 0;
  }

  /**
   * The work's title as printed in this issue, without any part number.
   */
  protected function printedTitle(NodeInterface $issue, NodeInterface $work): ?string {
    $titles = array_column($issue->get('field_published_as')->getValue(), 'value');
    foreach ($titles as $title) {
      $base = trim(preg_replace('/\s*\(part\s+\d+\s+of\s+\d+\)\s*$/i', '', $title));
      if ($base !== '') {
        return $base;
      }
    }
    return NULL;
  }

  /**
   * The part number this issue carries, from "(Part 2 of 5)", if recorded.
   */
  protected function partNumber(NodeInterface $issue, NodeInterface $work): ?int {
    foreach (array_column($issue->get('field_published_as')->getValue(), 'value') as $title) {
      if (preg_match('/\(part\s+(\d+)\s+of\s+\d+\)/i', $title, $m)) {
        return (int) $m[1];
      }
    }
    return NULL;
  }

}
