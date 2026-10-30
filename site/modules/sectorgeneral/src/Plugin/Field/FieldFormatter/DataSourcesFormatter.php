<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows plain-text source notes with their web addresses as links.
 *
 * "ISFDB title record https://…" becomes a link reading "ISFDB title record"
 * (likewise publication, author and Open Library records);
 * any other address links to itself. Each line is shown on its own line.
 * The stored text stays plain, so imports and editors needn't write HTML.
 */
#[FieldFormatter(
  id: 'sectorgeneral_data_sources',
  label: new TranslatableMarkup('Data sources, with links'),
  field_types: ['string_long', 'string'],
)]
class DataSourcesFormatter extends FormatterBase {

  /**
   * A named record and its address: "ISFDB title record https://…".
   */
  protected const RECORD = '#((?:ISFDB (?:title|publication|author)|Open Library|Biblioteka Narodowa|OPAC SBN|Národní knihovna ČR|Deutsche Nationalbibliothek) record) (https?://\S+?)(?=[.,;:)]*(?:\s|$))#';

  /**
   * Record kinds that appear more than once in the item being formatted.
   *
   * @var string[]
   */
  protected array $repeated = [];

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      $lines = array_filter(array_map('trim', preg_split('/\R/', (string) $item->value)));
      // Record kinds named more than once (two ISFDB publication records,
      // after merging duplicates) get their record number, to tell them apart.
      preg_match_all(self::RECORD, (string) $item->value, $matches);
      $this->repeated = array_keys(array_filter(array_count_values($matches[1]), fn($n) => $n > 1));
      $elements[$delta] = ['#markup' => implode('<br>', array_map([$this, 'linkify'], $lines))];
    }
    return $elements;
  }

  protected function linkify(string $text): string {
    // Named ISFDB records: the name becomes the link text.
    $parts = preg_split(self::RECORD, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    for ($i = 0; $i < count($parts); $i++) {
      if ($i % 3 === 0) {
        $html .= $this->autolink($parts[$i]);
      }
      elseif ($i % 3 === 1) {
        $label = $parts[$i];
        if (in_array($label, $this->repeated, TRUE) && preg_match('#(\w+)/?$#', $parts[$i + 1], $id)) {
          $label .= ' ' . $id[1];
        }
        $html .= $this->link($parts[$i + 1], $label);
        $i++;
      }
    }
    return $html;
  }

  /**
   * Escapes text, linking any bare web addresses in it.
   */
  protected function autolink(string $text): string {
    $parts = preg_split('#(https?://[^\s<>"]*[^\s<>".,;:)\]])#', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts as $i => $part) {
      $html .= $i % 2 ? $this->link($part, $part) : Html::escape($part);
    }
    return $html;
  }

  protected function link(string $url, string $text): string {
    return '<a href="' . Html::escape($url) . '">' . Html::escape($text) . '</a>';
  }

}
