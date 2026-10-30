<?php

/**
 * Imports the old site's articles and other pages as Basic pages.
 *
 * Run with:
 *   drush php:script scripts/oldsite/import_pages.php -- <pages.json>
 *
 * Run after import.php, so links to books and stories can be rewritten to
 * their new addresses. Re-runnable: pages are found again by their alias.
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;

[$json_path] = array_slice($extra ?? $_SERVER['argv'], -1);
$pages = json_decode(file_get_contents($json_path), TRUE);
$aliases = \Drupal::service('path_alias.manager');
$map = \Drupal::state()->get('sectorgeneral.oldsite_import', []);

// Old file → new path, for pages and for imported books and stories.
$paths = ['books.html' => '/books', 'shortstories.html' => '/short-stories', 'index.html' => '/'];
foreach ($pages as $page) {
  if (empty($page['front'])) {
    $paths[$page['file']] = '/' . $page['slug'];
  }
}
foreach ($map as $key => $nid) {
  if (preg_match('/^(book|story):(.+\.html)$/', $key, $m)) {
    $paths[($m[1] === 'book' ? 'books/' : 'shortstories/') . $m[2]] = $aliases->getAliasByPath("/node/$nid");
  }
}

$rewrite = function (string $html) use ($paths): string {
  return preg_replace_callback('/href="([^"]+)"/', function ($m) use ($paths) {
    $old = preg_replace('#^(\.\./)+#', '', html_entity_decode($m[1]));
    $old = preg_replace('#^https?://(www\.)?sectorgeneral\.com/#', '', $old);
    return isset($paths[$old]) ? 'href="' . $paths[$old] . '"' : $m[0];
  }, $html);
};

$nids = [];
foreach ($pages as $page) {
  $alias = '/' . $page['slug'];
  $path = $aliases->getPathByAlias($alias);
  $node = preg_match('#^/node/(\d+)$#', $path, $m) ? Node::load($m[1]) : NULL;
  $node ??= Node::create(['type' => 'page', 'uid' => 1, 'status' => 1, 'path' => ['alias' => $alias, 'pathauto' => 0]]);
  $node->setTitle($page['title']);
  $node->set('body', ['value' => $rewrite($page['html']), 'format' => 'basic_html']);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Imported from the original James White Information site.');
  $node->save();
  $nids[$page['file']] = $node->id();
  echo "page {$node->id()}: {$page['title']} ($alias)\n";

  if (empty($page['front'])) {
    $storage = \Drupal::entityTypeManager()->getStorage('redirect');
    if (!$storage->loadByProperties(['redirect_source__path' => $page['file']])) {
      Redirect::create(['redirect_source' => $page['file'], 'redirect_redirect' => 'entity:node/' . $node->id(), 'status_code' => 301, 'language' => 'und'])->save();
    }
  }
}

// The old home page becomes the front page.
$front = array_values(array_filter($pages, fn($p) => !empty($p['front'])))[0];
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/node/' . $nids[$front['file']])->save();

// Main menu links for the new sections.
foreach (['articles.html' => ['Articles', 3], 'biobiblio.html' => ['Bio-bibliography', 4]] as $file => [$title, $weight]) {
  $link = 'entity:node/' . $nids[$file];
  $existing = \Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['menu_name' => 'main', 'link.uri' => $link]);
  if (!$existing) {
    MenuLinkContent::create(['title' => $title, 'link' => ['uri' => $link], 'menu_name' => 'main', 'weight' => $weight])->save();
  }
}
echo "Front page set to node {$nids[$front['file']]}.\n";
