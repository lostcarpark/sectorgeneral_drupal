<?php

declare(strict_types=1);

namespace Drupal\sectorgeneral\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The site's own licence, CC BY 4.0, and what it doesn't cover: other
 * people's writing, cover images and photos, each credited where it appears.
 */
#[Block(
  id: 'sectorgeneral_site_licence',
  admin_label: new TranslatableMarkup('Site licence'),
)]
class SiteLicenceBlock extends BlockBase {

  public function build() {
    return [
      '#markup' => $this->t('The text and bibliographic data on this site are licensed under <a href=":licence">CC BY 4.0</a>: reuse them freely, with credit to Sector General: The James White Information Site and a link to the page. This does not cover articles, synopses and the bio-bibliography, which belong to their authors, or cover images and photos, which belong to their copyright holders; each is credited where it appears.', [
        ':licence' => 'https://creativecommons.org/licenses/by/4.0/',
      ]),
      '#prefix' => '<p class="site-licence">',
      '#suffix' => '</p>',
    ];
  }

}
