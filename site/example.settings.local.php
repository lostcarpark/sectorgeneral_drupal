<?php

/**
 * @file
 * Copy to settings.local.php and fill in for this machine.
 */

$databases['default']['default'] = [
  'database' => 'sectorgeneral',
  'username' => '',
  'password' => '',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// A long random string, e.g. from: openssl rand -hex 32
$settings['hash_salt'] = '';

// Only needed for the sectorgeneral_isfdb import commands: a local copy of
// an ISFDB database backup.
// $databases['isfdb']['default'] = [
//   'database' => 'isfdb',
//   'username' => '',
//   'password' => '',
//   'host' => 'localhost',
//   'port' => '3306',
//   'driver' => 'mysql',
//   'prefix' => '',
//   'collation' => 'utf8mb4_general_ci',
// ];
