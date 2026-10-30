<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb;

/**
 * Helpers for reading ISFDB data.
 *
 * ISFDB stores text as Latin-1 with HTML entities for other characters, and
 * dates as YYYY-MM-DD with 00 for an unknown month or day.
 */
final class Isfdb {

  /**
   * ISFDB title types that are James White's own books.
   */
  public const BOOK_TYPES = ['NOVEL', 'COLLECTION', 'OMNIBUS', 'CHAPBOOK', 'NONFICTION'];

  /**
   * ISFDB title type → field_book_type value.
   */
  public const BOOK_TYPE_MAP = [
    'NOVEL' => 'novel',
    'COLLECTION' => 'collection',
    'OMNIBUS' => 'omnibus',
    'CHAPBOOK' => 'chapbook',
    'NONFICTION' => 'nonfiction',
  ];

  /**
   * ISFDB publication format → Format vocabulary term.
   */
  public const FORMAT_MAP = [
    'pb' => 'Paperback',
    'hc' => 'Hardback',
    'tp' => 'Trade Paperback',
    'ebook' => 'Ebook',
    'digest' => 'Digest',
    'pulp' => 'Pulp',
    'bedsheet' => 'Bedsheet',
    'audio CD' => 'Audiobook',
    'audio MP3 CD' => 'Audiobook',
    'audio cassette' => 'Audiobook',
    'digital audio download' => 'Audiobook',
  ];

  public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  /**
   * Decodes ISFDB text: HTML entities to UTF-8, whitespace tidied.
   */
  public static function text(?string $value): string {
    $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Plain text from an ISFDB note, which may contain HTML.
   */
  public static function noteText(?string $note): string {
    // Keep ISFDB's line breaks: each line of a note is usually one fact
    // ("Translated by …", "Cover artist is not credited."), and running them
    // together lets one fact swallow the next.
    $note = preg_replace('#<(br|li|p|div)\b[^>]*>#i', "\n", (string) $note);
    $note = html_entity_decode(strip_tags($note), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_filter(array_map(fn($line) => trim(preg_replace('/[ \t]+/u', ' ', $line)), preg_split('/\R/u', $note)));
    return implode("\n", $lines);
  }

  /**
   * Parses an ISFDB date into [year, display].
   *
   * Returns [NULL, NULL] for unknown (0000), unpublished (8888) and
   * forthcoming (9999) dates.
   */
  public static function date(?string $date): array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $m)) {
      return [NULL, NULL];
    }
    [, $y, $mo, $d] = array_map('intval', $m);
    if ($y === 0 || $y >= 8888) {
      return [NULL, NULL];
    }
    if ($mo < 1 || $mo > 12) {
      return [$y, (string) $y];
    }
    $month = self::MONTHS[$mo - 1];
    return [$y, $d > 0 ? "$d $month $y" : "$month $y"];
  }

  /**
   * Which country's edition a price suggests: "US edition" for $,
   * "UK edition" for £ or shillings and pence, and so on.
   */
  public static function currencyEdition(string $price): ?string {
    $price = trim($price);
    return match (TRUE) {
      $price === '' => NULL,
      (bool) preg_match('/^(C\$|CA\$|CAN)/i', $price) => 'Canadian edition',
      (bool) preg_match('/^(A\$|AU\$|AUD)/i', $price) => 'Australian edition',
      (bool) preg_match('/^\$|cents?$|¢/i', $price) => 'US edition',
      (bool) preg_match('/^£|^\d+\/[\d-]+d?$|^\d+d$|^\d+p$|^\d+\/-$/i', $price) => 'UK edition',
      default => NULL,
    };
  }

  /**
   * The month (1-12) named in a date as shown, e.g. "1 July 1958"; 0 if none.
   */
  public static function month(string $date): int {
    foreach (self::MONTHS as $i => $name) {
      if (stripos($date, $name) !== FALSE) {
        return $i + 1;
      }
    }
    return 0;
  }

  /**
   * Normalises an ISBN or SBN to 13 digits, or NULL if it isn't valid.
   */
  public static function isbn13(?string $raw): ?string {
    $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $raw));
    if (strlen($digits) === 9) {
      $digits = '0' . $digits;
    }
    if (strlen($digits) === 10) {
      $sum = 0;
      foreach (str_split($digits) as $i => $c) {
        $sum += (10 - $i) * ($c === 'X' ? 10 : (int) $c);
      }
      if ($sum % 11 !== 0) {
        return NULL;
      }
      $digits = '978' . substr($digits, 0, 9);
      $digits .= (string) ((10 - self::ean13Sum($digits) % 10) % 10);
      return $digits;
    }
    if (strlen($digits) === 13 && ctype_digit($digits) && self::ean13Sum($digits) % 10 === 0) {
      return $digits;
    }
    return NULL;
  }

  private static function ean13Sum(string $digits): int {
    $sum = 0;
    foreach (str_split($digits) as $i => $c) {
      $sum += ($i % 2 ? 3 : 1) * (int) $c;
    }
    return $sum;
  }

  /**
   * A title reduced for comparison: lower case, no leading article or
   * punctuation, so "The Watch Below" matches "Watch Below, The".
   */
  public static function normalizeTitle(string $title): string {
    $title = str_replace('ß', 'ss', mb_strtolower(self::text($title)));
    $title = preg_replace('/^(the|a|an)\s+/u', '', $title);
    $title = preg_replace('/,\s*(the|a|an)$/u', '', $title);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
  }

  /**
   * Words that don't distinguish one publisher or magazine from another.
   */
  private const NAME_NOISE = [
    'publisher' => '/\b(books?|press|publishing|publishers|publications|paperbacks?|ltd|limited|inc|co|company|corp|verlag|editions?|éditions?|editora|editorial|editrice|editore|edizioni|uitgeverij|förlag|forlag|wydawnictw[oa]|dom\s+wydawniczy|oficyna)\b/u',
    'publisher_series' => '/\b(books?|series|library)\b/u',
    'magazine' => '/\b(science[ -]?fiction|sf|s-f|magazine|monthly|quarterly|of)\b/u',
  ];

  /**
   * A publisher, magazine or series name reduced for comparison.
   *
   * Beyond normalizeTitle(), drops anything after a comma (usually a city),
   * parenthetical notes, "&"/"and" differences and common words such as
   * "Books" or "Science Fiction", so "Ballantine Books" matches "Ballantine
   * Books, New York" and "New Worlds Science Fiction" matches "New Worlds".
   */
  public static function normalizeName(string $name, string $vocabulary): string {
    $name = mb_strtolower(self::text($name));
    $name = preg_replace('/\([^)]*\)/u', ' ', $name);
    $name = explode(',', $name)[0];
    $name = str_replace(['&', '+'], ' and ', $name);
    $name = preg_replace('/^the\s+/u', '', $name);
    if (isset(self::NAME_NOISE[$vocabulary])) {
      $name = preg_replace(self::NAME_NOISE[$vocabulary], ' ', $name);
    }
    $key = self::normalizeTitle($name);
    return $key !== '' ? $key : self::normalizeTitle($name . 'x');
  }

  /**
   * The translators of the author's work named in a note.
   *
   * Notes on publications holding several works (Mondadori's Urania volumes,
   * for instance) give a translator for each: "… translated by Mario Galli as
   * "L'astronave del massacro". Mission to Universe was translated by Bianca
   * Russo as "La cosmonave dei ventiquattro" …". Each "by NAME as "TITLE""
   * pair is matched against the titles of the author's work in the
   * publication; if the note pairs translators with titles but none of them
   * is his, no translator is taken rather than someone else's.
   *
   * Otherwise the line that says "Translated by …" names them.
   *
   * @param string[] $titles
   *   The author's work as titled in this publication, and its original title.
   *
   * @return string[]
   */
  public static function translators(string $note, array $titles = []): array {
    $wanted = array_filter(array_map([self::class, 'normalizeTitle'], $titles));
    if (preg_match_all('/\bby\s+([^"“«\n]+?)\s+as\s+["“«]([^"”»\n]+)["”»]/u', $note, $pairs, PREG_SET_ORDER)) {
      // One volume can hold more than one of his works: take every match.
      $found = [];
      foreach ($pairs as [, $names, $title]) {
        if (in_array(self::normalizeTitle($title), $wanted, TRUE)) {
          array_push($found, ...self::names($names));
        }
      }
      if ($found) {
        return array_values(array_unique($found));
      }
      // Several works, several translators, none of them clearly his.
      if (count($pairs) > 1 || $wanted) {
        return [];
      }
    }
    foreach (preg_split('/\R/u', $note) as $line) {
      if (preg_match('/\b(?:translated|translation)\b(?:\s+(?:from|into)(?:\s+the)?\s+[\p{L}-]+)*\s+by\s+(.+)$/iu', $line, $m)
        || preg_match('/^\s*translators?\s*:\s*(.+)$/iu', $line, $m)) {
        return self::names($m[1]);
      }
    }
    return [];
  }

  /**
   * Person names from the text after "Translated by": up to the end of the
   * sentence, split at "and", "&" and commas.
   *
   * A full stop after an initial ("A. de Vries") doesn't end the sentence;
   * "Bianca Russo as "Vita con gli automi"" gives "Bianca Russo".
   * Where note lines have run together, the name also stops at the start of
   * a typical note, e.g. "Bert Rozeboom Cover artist is not credited".
   *
   * @return string[]
   */
  public static function names(string $text): array {
    // Up to the end of the sentence, a quotation, or "as <title>".
    $text = preg_split('/(?<=\p{L}{2})\.(?=\s|$)|[;(\["“«]|\s+as\s+(?=["“«\p{Lu}])/u', $text)[0];
    $text = preg_split('/\s+(?=(?:Cover|Covers|Price|Prices|Pages?|Printed|Printing|First|Second|Third|Data|Number|Interior|Also|Note|Notes|Source|Verified|Reprint|Reprinted|Published|ISBN|Catalog|Catalogue)\b)/u', $text)[0];
    $names = preg_split('/\s*(?:,|&|\band\b)\s*/u', trim($text));
    return array_values(array_filter(array_map(fn($n) => trim($n, " \t."), $names), fn($n) => mb_strlen($n) > 1));
  }

  /**
   * Whether a note says the cover art isn't credited, e.g. "Cover artist is
   * not credited." or "Uncredited cover."
   */
  public static function coverUncredited(string $note): bool {
    return (bool) preg_match('/\bcover(?:\s+(?:art|artist|artwork|painting|illustration|design))?\s+(?:is\s+|was\s+)?(?:not\s+credited|uncredited|unknown|not\s+known|unsigned|unattributed)\b|\buncredited\s+cover\b/iu', $note);
  }

  /**
   * The last number in an ISFDB page count such as "[8]+189".
   */
  public static function pages(?string $pages): ?int {
    return preg_match_all('/\d+/', (string) $pages, $m) ? (int) end($m[0]) : NULL;
  }

  /**
   * Links to an ISFDB record, for attribution.
   */
  public static function url(string $type, int|string $id): string {
    $script = ['publication' => 'pl.cgi', 'title' => 'title.cgi', 'author' => 'ea.cgi'][$type];
    return "https://www.isfdb.org/cgi-bin/$script?$id";
  }

  /**
   * The attribution line added to field_data_sources.
   */
  public static function attribution(string $type, int|string $id): string {
    return 'ISFDB ' . $type . ' record ' . self::url($type, $id) . ' (CC BY 4.0).';
  }

  /**
   * A picture prepared for comparison: plain borders trimmed, then shrunk to
   * a small greyscale grid (stored as rows of brightness values).
   *
   * @return array|null
   *   ['w' => width, 'h' => height, 'px' => brightness values], or NULL if the
   *   file can't be read as an image.
   */
  public static function picture(string $uri): ?array {
    $data = @file_get_contents($uri);
    $image = $data ? @imagecreatefromstring($data) : FALSE;
    if (!$image) {
      return NULL;
    }
    [$x, $y, $w, $h] = self::contentBox($image);
    // A working copy big enough to crop a few percent off each side.
    $W = 48;
    $H = 72;
    $small = imagecreatetruecolor($W, $H);
    imagecopyresampled($small, $image, 0, 0, $x, $y, $W, $H, $w, $h);
    $px = [];
    for ($j = 0; $j < $H; $j++) {
      for ($i = 0; $i < $W; $i++) {
        $c = imagecolorat($small, $i, $j);
        $px[] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
      }
    }
    return ['w' => $W, 'h' => $H, 'px' => $px];
  }

  /**
   * How alike two pictures are, allowing for different framing and fading.
   *
   * Tries small crops of the second picture (scans are often framed a little
   * differently) and returns the best scores found:
   * - brightness: the pattern of light and dark;
   * - edges: where brightness changes, which fading barely affects.
   * Copies of the same cover, even a faded thumbnail of a later scan, score
   * at least about 0.75 and 0.70; different covers fall well short on edges.
   *
   * @return float[]
   *   ['brightness' => …, 'edges' => …], each from -1 to 1.
   */
  public static function pictureSimilarity(array $a, array $b): array {
    $sa = self::grid($a, [0, 0, 1, 1]);
    $best = ['brightness' => -1.0, 'edges' => -1.0];
    $steps = [0, 0.04, 0.08];
    foreach ($steps as $l) {
      foreach ($steps as $r) {
        foreach ($steps as $t) {
          foreach ($steps as $bt) {
            $sb = self::grid($b, [$l, $t, 1 - $r, 1 - $bt]);
            $best['brightness'] = max($best['brightness'], self::dot($sa['brightness'], $sb['brightness']));
            $best['edges'] = max($best['edges'], self::dot($sa['edges'], $sb['edges']));
          }
        }
      }
    }
    return $best;
  }

  /**
   * A 12 x 18 brightness grid of part of a prepared picture, and its edges,
   * each centred and normalised.
   */
  private static function grid(array $p, array $box): array {
    [$x0, $y0, $x1, $y1] = $box;
    $W = 12;
    $H = 18;
    $values = [];
    for ($j = 0; $j < $H; $j++) {
      for ($i = 0; $i < $W; $i++) {
        // Average the working-copy pixels falling in this cell.
        $ax = (int) floor(($x0 + ($x1 - $x0) * $i / $W) * $p['w']);
        $bx = max($ax + 1, (int) floor(($x0 + ($x1 - $x0) * ($i + 1) / $W) * $p['w']));
        $ay = (int) floor(($y0 + ($y1 - $y0) * $j / $H) * $p['h']);
        $by = max($ay + 1, (int) floor(($y0 + ($y1 - $y0) * ($j + 1) / $H) * $p['h']));
        $sum = 0;
        $n = 0;
        for ($yy = $ay; $yy < min($by, $p['h']); $yy++) {
          for ($xx = $ax; $xx < min($bx, $p['w']); $xx++) {
            $sum += $p['px'][$yy * $p['w'] + $xx];
            $n++;
          }
        }
        $values[] = $n ? $sum / $n : 0;
      }
    }
    $edges = [];
    for ($j = 0; $j < $H; $j++) {
      for ($i = 0; $i < $W; $i++) {
        $c = $values[$j * $W + $i];
        $edges[] = $i + 1 < $W ? $values[$j * $W + $i + 1] - $c : 0;
        $edges[] = $j + 1 < $H ? $values[($j + 1) * $W + $i] - $c : 0;
      }
    }
    return ['brightness' => self::normalise($values), 'edges' => self::normalise($edges)];
  }

  /**
   * The part of an image inside any plain border, as [x, y, width, height].
   */
  private static function contentBox(\GdImage $image): array {
    $w = imagesx($image);
    $h = imagesy($image);
    $brightness = function (int $x, int $y) use ($image): float {
      $c = imagecolorat($image, $x, $y);
      return 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
    };
    $plain = function (array $line): bool {
      $mean = array_sum($line) / count($line);
      $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $line)) / count($line);
      return sqrt($variance) < 12;
    };
    $column = fn(int $x) => array_map(fn($y) => $brightness($x, $y), range(0, $h - 1, max(1, intdiv($h, 60))));
    $row = fn(int $y) => array_map(fn($x) => $brightness($x, $y), range(0, $w - 1, max(1, intdiv($w, 60))));
    $left = 0;
    while ($left < $w * 0.15 && $plain($column($left))) {
      $left++;
    }
    $right = $w - 1;
    while ($right > $w * 0.85 && $plain($column($right))) {
      $right--;
    }
    $top = 0;
    while ($top < $h * 0.15 && $plain($row($top))) {
      $top++;
    }
    $bottom = $h - 1;
    while ($bottom > $h * 0.85 && $plain($row($bottom))) {
      $bottom--;
    }
    return [$left, $top, $right - $left + 1, $bottom - $top + 1];
  }

  private static function normalise(array $values): array {
    $mean = array_sum($values) / count($values);
    $values = array_map(fn($v) => $v - $mean, $values);
    $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $values))) ?: 1;
    return array_map(fn($v) => $v / $norm, $values);
  }

  private static function dot(array $a, array $b): float {
    $sum = 0.0;
    foreach ($a as $i => $value) {
      $sum += $value * $b[$i];
    }
    return $sum;
  }

}
