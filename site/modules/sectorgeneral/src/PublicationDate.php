<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral;

/**
 * Turns a publication date as shown into a date that sorts properly.
 *
 * "May 1962" → 1962-05-01, "1 July 1958" → 1958-07-01, "Spring 1977" →
 * 1977-04-01, "Mid-1979" → 1979-07-01; a year alone sorts at its start.
 */
final class PublicationDate {

  private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

  private const SEASONS = ['early' => 2, 'spring' => 4, 'mid' => 7, 'summer' => 7, 'autumn' => 10, 'fall' => 10, 'late' => 11, 'winter' => 12];

  /**
   * The sortable date (Y-m-d), or NULL if there is no year at all.
   */
  public static function sortDate(?string $display, ?int $year, ?string $exact = NULL): ?string {
    if ($exact && preg_match('/^\d{4}-\d{2}-\d{2}/', $exact)) {
      return substr($exact, 0, 10);
    }
    $display = (string) $display;
    if (!$year && preg_match('/\b(1[89]\d\d|20\d\d)\b/', $display, $m)) {
      $year = (int) $m[1];
    }
    if (!$year) {
      return NULL;
    }
    $month = 1;
    $day = 1;
    if (preg_match('/(?:\b(\d{1,2})\s+)?\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?/i', $display, $m)) {
      $month = self::MONTHS[strtolower($m[2])];
      $day = $m[1] !== '' && (int) $m[1] <= 31 ? max(1, (int) $m[1]) : 1;
    }
    elseif (preg_match('/\b(early|spring|mid|summer|autumn|fall|late|winter)\b/i', $display, $m)) {
      $month = self::SEASONS[strtolower($m[1])];
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
  }

}
