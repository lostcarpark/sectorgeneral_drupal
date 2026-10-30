#!/usr/bin/env bash
# Exports all site content into the sectorgeneral_content recipe, replacing
# what is there. Path aliases are not exported separately: each node, term and
# media item carries its alias in its path field, and a path_alias export would
# pin the old entity IDs (/node/123).
#
# Run from the Drupal project root (where vendor/ is), giving this script's
# path, e.g.: sectorgeneral/scripts/export_content.sh
# Set DRUSH to choose a Drush other than vendor/bin/drush, and pass the site
# with --uri in DRUSH if Drush's default site is not this one.
set -euo pipefail

drush=${DRUSH:-vendor/bin/drush}
dir="$(cd "$(dirname "$0")/.." && pwd)/recipes/sectorgeneral_content/content"
rm -rf "$dir"
mkdir -p "$dir"
for type in node taxonomy_term media block_content menu_link_content redirect; do
  $drush content:export "$type" --with-dependencies --dir="$dir"
done
