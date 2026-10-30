<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

/**
 * Every national library source: the Polish National Library's open data
 * API, and the Z39.50 and SRU catalogues in the z3950 and sru settings.
 */
class LibrarySources {

  public function __construct(
    protected BibliotekaNarodowaSource $bibliotekaNarodowa,
    protected CatalogueSources $catalogueSources,
  ) {}

  /**
   * @return \Drupal\sectorgeneral_isfdb\Library\LibrarySourceInterface[]
   *   Keyed by source ID.
   */
  public function all(): array {
    return [$this->bibliotekaNarodowa->id() => $this->bibliotekaNarodowa] + $this->catalogueSources->all();
  }

  public function get(string $id): LibrarySourceInterface {
    $sources = $this->all();
    if (!isset($sources[$id])) {
      throw new \InvalidArgumentException(sprintf('Unknown library "%s"; use one of: %s.', $id, implode(', ', array_keys($sources))));
    }
    return $sources[$id];
  }

}
