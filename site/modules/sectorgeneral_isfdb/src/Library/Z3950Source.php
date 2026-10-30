<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

use Symfony\Component\Process\Process;

/**
 * Editions from a library catalogue's Z39.50 interface, in MARC 21.
 *
 * Settings, under z3950, as MarcSource describes, plus:
 * - target: host:port/database.
 * - query: the PQF search.
 *
 * fetch() searches through YAZ's zoomsh in a single session, and any error
 * from the server stops it.
 */
class Z3950Source extends MarcSource {

  /**
   * Records fetched per present request.
   */
  protected const CHUNK = 25;

  public function fetch(): array {
    $find = 'find ' . $this->settings['query'];
    $hits = $this->hits($this->zoomsh([$find]));
    $commands = [$find];
    for ($start = 0; $start < min($hits, 500); $start += self::CHUNK) {
      $commands[] = sprintf('show %d %d xml', $start, self::CHUNK);
    }
    $raw = [];
    preg_match_all('#<record\b.*?</record>#s', $this->zoomsh($commands), $matches);
    foreach ($matches[0] as $xml) {
      $marc = $this->marc($xml);
      $raw[$marc ? $this->recordId($marc) : sha1($xml)] = $xml;
    }
    return $raw;
  }

  /**
   * Runs zoomsh commands against the catalogue, returning its output.
   */
  protected function zoomsh(array $commands): string {
    $process = new Process(['zoomsh', "connect {$this->settings['target']}", 'set preferredRecordSyntax usmarc', ...$commands, 'quit']);
    $process->setTimeout(300);
    $process->run();
    $output = $process->getOutput() . $process->getErrorOutput();
    // Outside the records, any error line means the server refused or failed.
    $text = preg_replace('#<record\b.*?</record>#s', '', $output);
    if (!$process->isSuccessful() || preg_match('/^.*\berror\b.*$/mi', $text, $error)) {
      throw new \RuntimeException("{$this->label()}: " . trim($error[0] ?? $output));
    }
    return $output;
  }

  protected function hits(string $output): int {
    if (!preg_match('/: (\d+) hits/', $output, $match)) {
      throw new \RuntimeException("{$this->label()} gave no hit count: " . trim($output));
    }
    return (int) $match[1];
  }

}
