<?php

/**
 * @file
 * Settings for sectorgeneral.com.
 *
 * This directory is symlinked into the multisite as
 * web/sites/sectorgeneral.com. Database credentials, the hash salt and
 * anything else machine-specific go in settings.local.php beside this file,
 * which is not in the repository.
 */

$databases = [];

// __DIR__ resolves the symlink, so this is the repository's config directory.
$settings['config_sync_directory'] = __DIR__ . '/../config';
$settings['file_public_path'] = $site_path . '/files';

$settings['trusted_host_patterns'] = ['^sectorgeneral\.com$'];

$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;

if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
