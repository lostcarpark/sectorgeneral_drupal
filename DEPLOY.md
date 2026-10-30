# Deploying Sector General

The site runs as one site of a Drupal 11 multisite at **sectorgeneral.com**.
`www.sectorgeneral.com` and `new.sectorgeneral.com` redirect to it.

This repository holds everything specific to the site:

```
site/      the multisite site directory: settings.php, files/, and the
           site's own modules and theme
config/    the configuration to import (content types, fields, views,
           theme settings)
recipes/   sectorgeneral_content: all the content (books, editions, stories,
           people, covers, photos, redirects, menu links)
scripts/   build and import scripts used in development
```

The Drupal codebase (Composer project) is the multisite's own, and is not in
this repository.

## 1. Shared codebase

The multisite needs Drupal core 11.3 or later (the recipe uses core's default
content importer). Development is on 11.4. Add these to the multisite's
`composer.json` if they are not already there:

```
composer require drupal/eva:^3.1 drupal/field_group:^4.0 \
  drupal/navigation_extra_tools:^1.3 drupal/pathauto:^1.15 \
  drupal/redirect:^1.13 drupal/registration_theme:^1.1 \
  drupal/views_row_sdc:^1.2 drupal/upgrade_status:^4.3
```

(`token` and `ctools` come in as dependencies. `upgrade_status` is only
needed because it is enabled in `config/`.)

## 2. Site directory

Clone this repository outside the web root, and link its `site/` directory
into the multisite:

```
git clone <repository> /srv/sectorgeneral
ln -s /srv/sectorgeneral/site /path/to/multisite/web/sites/sectorgeneral.com
```

`site/files/` must be writable by the web server. The site's modules and
theme live in `site/`, so the multisite's other sites never see them.

Add the site to the multisite's `web/sites/sites.php`:

```php
$sites['sectorgeneral.com'] = 'sectorgeneral.com';
```

`site/settings.php` is in the repository and finds `config/` by itself.
Copy `site/example.settings.local.php` to `site/settings.local.php` and fill
in the database credentials and a new hash salt (`openssl rand -hex 32`).
`settings.local.php` is not in the repository.

The `sectorgeneral_isfdb` module works without the `isfdb` database
connection. It only needs that connection, and `zoomsh` (yaz), for the
import and fetch commands, which belong on the development copy.

If PHP has `open_basedir` set, it must include the clone's path. The web
server must follow symlinks. Drupal's `.htaccess` turns this on for Apache,
and nginx follows them by default.

## 3. Install

**First check that Drush targets the new site and its empty database.** Drupal
only looks past `sites/default` when `sites/sites.php` has an entry for the host.
`site:install` installs into `sites/default` unless it is given
`--sites-subdir`.

```
D="vendor/bin/drush --uri=https://sectorgeneral.com"
$D status --fields=db-name,site    # must show the new database and sites/sectorgeneral.com

$D site:install --existing-config --sites-subdir=sectorgeneral.com \
  --account-name=sector_admin --account-mail=info@sectorgeneral.com -y
$D config:import -y
$D recipe:apply /srv/sectorgeneral/recipes/sectorgeneral_content -y
$D cache:rebuild
```

Check: about 601 nodes, 1,279 terms, 532 media, 266 redirects and 1,868
path aliases.

After the import, the live site holds the master copy of the content.
Applying the recipe again skips entities that already exist, so it cannot
update content. Make content edits on the live site. To refresh development,
copy the live database down.

## 4. Web server and DNS

Point DNS for `sectorgeneral.com`, `www.sectorgeneral.com` and
`new.sectorgeneral.com` at the multisite server. Get one certificate for all
three names (`certbot -d sectorgeneral.com -d www.sectorgeneral.com -d
new.sectorgeneral.com`). Do the redirects at the web server, so Drupal only
ever sees one host.

nginx:

```nginx
server {
  listen 443 ssl;
  server_name www.sectorgeneral.com new.sectorgeneral.com;
  # ssl_certificate ... (same certificate)
  return 301 https://sectorgeneral.com$request_uri;
}
server {
  listen 80;
  server_name sectorgeneral.com www.sectorgeneral.com new.sectorgeneral.com;
  return 301 https://sectorgeneral.com$request_uri;
}
# sectorgeneral.com itself: add it to the multisite's existing server block,
# or copy that block with server_name sectorgeneral.com.
```

Apache:

```apache
<VirtualHost *:443>
  ServerName www.sectorgeneral.com
  ServerAlias new.sectorgeneral.com
  # SSLCertificateFile ... (same certificate)
  Redirect permanent / https://sectorgeneral.com/
</VirtualHost>
```

Once the new site is live, the Drupal 10 install that served
`new.sectorgeneral.com` can be retired.

## Development

The development copy uses the same layout: the repository is cloned beside
the Drupal project, `web/sites/sectorgeneral.com` links to its `site/`
directory, and `sites.php` maps the local host name to it.

To regenerate the content recipe, run this from the Drupal project root:

```
sectorgeneral/scripts/export_content.sh
```

This replaces `recipes/sectorgeneral_content/content` with a fresh export.
Pathauto's per-item setting is included (see
`site/modules/sectorgeneral/src/EventSubscriber/DefaultContentSubscriber.php`),
so pages with hand-set aliases keep them.
