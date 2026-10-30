<?php

/**
 * Front-end theme: the sectorgeneral_theme subtheme of Registration Theme,
 * its block layout and colours. Safe to re-run.
 *
 * Run with: drush php:script scripts/build_theme.php
 */

use Drupal\block\Entity\Block;

$theme = 'sectorgeneral_theme';
\Drupal::service('theme_installer')->install(['registration_theme', $theme]);
\Drupal::configFactory()->getEditable('system.theme')->set('default', $theme)->save();

// Placeholder palette until the design is settled: deep-space header,
// hospital teal for links and buttons.
$palette = [
  'primary_color' => '#1d6f7f',
  'neutral_color' => '#2b3440',
  'header_color' => '#14213a',
];
// Registration Theme 1.1's settings form saves to registration_theme.settings,
// but core only reads the active theme's own settings, so a subtheme needs
// them in sectorgeneral_theme.settings too. Until that's fixed upstream,
// change colours or the banner here and re-run, not on the settings form.
foreach (['registration_theme.settings', "$theme.settings"] as $name) {
  $config = \Drupal::configFactory()->getEditable($name);
  $config->setData($palette + ($config->get() ?: []) + [
    'banner_image_path' => '',
    'banner_image_position' => 'center',
  ])->save();
}

// Credit for the banner image (css/banner.css). ESA/Hubble's CC BY 4.0 terms
// ask for the full credit line, unaltered, visible, with an active link.
$credit_storage = \Drupal::entityTypeManager()->getStorage('block_content');
$credit = current($credit_storage->loadByProperties(['info' => 'Banner image credit']))
  ?: $credit_storage->create(['type' => 'basic', 'info' => 'Banner image credit']);
$credit->set('body', [
  'format' => 'basic_html',
  'value' => '<p class="banner-credit">Banner image: <a href="https://esahubble.org/images/heic1104a/">Flocculent spiral NGC 2841</a> (cropped). Credit: NASA, ESA and the Hubble Heritage (STScI/AURA)-ESA/Hubble Collaboration. Acknowledgment: M. Crockett and S. Kaviraj (Oxford University, UK), R. O\'Connell (University of Virginia), B. Whitmore (STScI) and the WFC3 Scientific Oversight Committee. <a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>.</p>',
])->save();

// Block layout. Installing a theme copies the old default theme's blocks
// into regions with the same names, so start from a clean slate.
$storage = \Drupal::entityTypeManager()->getStorage('block');
$storage->delete($storage->loadByProperties(['theme' => $theme]));
$blocks = [
  // id => [plugin, region, weight, settings].
  // The rocket beside the site name, sized to it; see css/header.css.
  'site_branding' => ['system_branding_block', 'header', 0, []],
  'main_menu' => ['system_menu_block:main', 'primary_menu', 0, [
    'label' => 'Main navigation',
    'level' => 1,
    // Two levels, expanded, for the drop-downs (Books › Translations…).
    'depth' => 2,
    'expand_all_items' => TRUE,
  ]],
  'page_title' => ['page_title_block', 'page_title', 0, []],
  'breadcrumbs' => ['system_breadcrumb_block', 'breadcrumb', 0, []],
  'messages' => ['system_messages_block', 'highlighted', -5, []],
  'primary_admin_actions' => ['local_actions_block', 'highlighted', -5, []],
  'primary_local_tasks' => ['local_tasks_block', 'highlighted', -4, ['primary' => TRUE, 'secondary' => FALSE]],
  'secondary_local_tasks' => ['local_tasks_block', 'highlighted', -2, ['primary' => FALSE, 'secondary' => TRUE]],
  'help' => ['help_block', 'help', 0, []],
  'content' => ['system_main_block', 'content', 0, []],
  // A magnifying glass after the menu; see the block template.
  'search' => ['search_form_block', 'primary_menu', 10, ['label' => 'Search']],
  'isfdb_credit' => ['sectorgeneral_isfdb_credit', 'footer_bottom', 10, []],
  'banner_credit' => ['block_content:' . $credit->uuid(), 'footer_bottom', 20, ['label' => 'Banner image credit']],
];
foreach ($blocks as $id => [$plugin, $region, $weight, $settings]) {
  $settings += ['label_display' => '0'];
  Block::create([
    'id' => "{$theme}_$id",
    'theme' => $theme,
    'region' => $region,
    'weight' => $weight,
    'plugin' => $plugin,
    'settings' => $settings,
  ])->save();
}

drupal_flush_all_caches();
echo "Theme $theme is the default, with " . count($blocks) . " blocks placed.\n";
