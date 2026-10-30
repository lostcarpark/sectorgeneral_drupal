<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Library;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sectorgeneral_isfdb\CoverFetcher;

/**
 * The MARC 21 library catalogues, one per entry in the z3950 and sru
 * settings.
 *
 * Adding a library that speaks Z39.50 or SRU and MARC 21 needs only a
 * settings entry; see MarcSource and its subclasses for the keys.
 */
class CatalogueSources {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected CoverFetcher $covers,
  ) {}

  /**
   * @return \Drupal\sectorgeneral_isfdb\Library\MarcSource[]
   *   Keyed by source ID.
   */
  public function all(): array {
    $settings = $this->configFactory->get('sectorgeneral_isfdb.settings');
    $sources = [];
    foreach ($settings->get('z3950') ?? [] as $id => $library) {
      $sources[$id] = new Z3950Source($id, $library, $this->configFactory);
    }
    foreach ($settings->get('sru') ?? [] as $id => $library) {
      $sources[$id] = new SruSource($id, $library, $this->configFactory, $this->covers);
    }
    return $sources;
  }

}
