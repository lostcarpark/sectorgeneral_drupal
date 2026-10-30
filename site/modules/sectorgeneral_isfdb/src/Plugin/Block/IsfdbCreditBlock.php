<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral_isfdb\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The site-wide credit required by ISFDB's CC BY 4.0 licence.
 */
#[Block(
  id: 'sectorgeneral_isfdb_credit',
  admin_label: new TranslatableMarkup('ISFDB credit'),
)]
class IsfdbCreditBlock extends BlockBase {

  public function build() {
    return [
      '#markup' => $this->t('Bibliographic data includes material from the <a href=":isfdb">Internet Speculative Fiction Database (ISFDB)</a>, licensed under <a href=":licence">CC BY 4.0</a>.', [
        ':isfdb' => 'https://www.isfdb.org/',
        ':licence' => 'https://creativecommons.org/licenses/by/4.0/',
      ]),
      '#prefix' => '<p class="isfdb-credit">',
      '#suffix' => '</p>',
    ];
  }

}
