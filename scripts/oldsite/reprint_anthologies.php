<?php

/**
 * Creates anthologies that reprinted James White stories, as recorded in the
 * old site's story "Publication History" notes, and adds the publishers and
 * later editions the notes give for anthologies already in Drupal.
 *
 * Every fact here is from the old site. Spelling fixes are listed in
 * import-report.md; editors the old site doesn't name are left blank.
 *
 * Run after import.php and move_anthologies.php:
 *   drush php:script scripts/oldsite/reprint_anthologies.php
 * Re-runnable: anthologies are remembered in the import map.
 */

use Drupal\node\Entity\Node;

const SG_SOURCE = 'James White Information (original site)';

// title => [editors, publisher, year, story keys, notes, series, number]
$anthologies = [
  'Gateway to the Stars' => [['John Carnell'], 'Museum Press', 1955, ['assistedpassage.html'], ''],
  'The Best from New Worlds' => [[], 'T. V. Boardman', 1955, ['crossfire.html'], ''],
  'Science Fiction Stories' => [['Tom Boardman'], 'Octopus Books', 1979, ['crossfire.html'], ''],
  'Combat SF' => [['Gordon R. Dickson'], 'Ace Books', 1981, ['thescavangers.html'], 'Ace Books, New York, June 1981.'],
  'S-F: Adventures in Mutation' => [['Groff Conklin'], 'Vanguard Press', 1956, ['theconspirators.html'], 'Vanguard, New York, January 1956.'],
  'Mind to Mind' => [['Robert Silverberg'], 'Thomas Nelson', 1971, ['theconspirators.html'], 'Thomas Nelson, New York, 1971. Paperback edition February 1974.'],
  'Supernatural Cats' => [['Claire Necker'], 'Doubleday', 1973, ['theconspirators.html'], 'Doubleday, March 1973.'],
  'Strange Universe' => [[], 'Blackie', 1975, ['theconspirators.html'], ''],
  'Histoires de créatures' => [[], 'Le Livre de Poche', 1984, ['theconspirators.html'], 'Le Livre de Poche No 3787, 1984. French.'],
  'Pilot durch Zeit und Raum' => [['Ronald M. Hahn'], 'Ensslin', 1983, ['thelightsoutsidethewindow.html'], 'Ensslin hardback, June 1983. German.'],
  'The Best of New Worlds' => [['Michael Moorcock'], 'Compact Books', 1965, ['tableau.html'], 'Compact, November 1965.'],
  'Worlds Apart' => [[], 'Blackie', 1966, ['omarasorphan.html'], 'Blackie, September 1966.'],
  'Out of This World 3' => [[], 'Blackie', 1962, ['apprentice.html'], 'Blackie, May 1962.', 'Out of This World', 3],
  'Out of This World 6' => [[], 'Blackie', 1967, ['fasttrip.html'], 'Blackie, 15/-, October 1967.', 'Out of This World', 6],
  'Out of This World Omnibus' => [[], 'Blackie', 1971, ['apprentice.html'], 'Blackie, £1.25, June 1971.', 'Out of This World', NULL],
  'The Aliens' => [['Robert Silverberg'], 'Thomas Nelson', 1978, ['countercharm.html'], 'Thomas Nelson, New York, 1978.'],
  'Top SF 1' => [['Josh Pachter'], 'Loeb', 1986, ['countercharm.html'], 'Loeb, Amsterdam, 1986. Dutch.'],
  "8th Annual Year's Best SF" => [['Judith Merril'], 'Simon & Schuster', 1963, ['christmastreason.html'],
    "Simon & Schuster, 1963. Dell paperback, New York, December 1964. UK paperback as Best of Sci-Fi 4, Mayflower-Dell, 5/-, August 1965. Japanese edition, Tokyo Sogensha, early 1977."],
  'Space 1' => [['Richard Davis'], 'Abelard-Schuman', 1973, ['christmastreason.html'], 'Abelard-Schuman, September 1973.'],
  'To Follow a Star' => [['Terry Carr'], 'Thomas Nelson', 1978, ['christmastreason.html'], 'Thomas Nelson Inc, $6.95, 1978-79.'],
  'Santa 2000' => [['Michael Parry'], 'Dragon', 1984, ['christmastreason.html'], 'Dragon, December 1984.'],
  'Christmas on Ganymede' => [['Martin H. Greenberg'], 'Avon', 1990, ['christmastreason.html'], 'Avon, December 1990.'],
  'Isaac Asimov Presents The Great SF Stories 24' => [['Isaac Asimov', 'Martin H. Greenberg'], 'DAW Books', 1992, ['christmastreason.html'], 'DAW, January 1992.', 'Isaac Asimov Presents The Great SF Stories', 24],
  'Den fantastiska julen' => [['John-Henry Holmberg'], 'Timbro', 1985, ['christmastreason.html'], 'Timbro, Sweden, 1985, with the story as "Undersökningen". Swedish.'],
  '13 Above the Night' => [['Groff Conklin'], 'Dell', 1965, ['countersecurity.html'], 'Dell, November 1965.'],
  'In Time to Come' => [[], 'Macmillan', 1975, ['fasttrip.html'], 'Macmillan Topliner Collection, 1975.'],
  'Imperios galácticos 2' => [['Brian Aldiss'], 'Editorial Bruguera', 1977, ['residentphysician.html'], 'Editorial Bruguera SA, Barcelona, 1977. Spanish edition of Galactic Empires.'],
  'New Writings in SF Special One' => [['John Carnell', 'Ken Bulmer'], 'Sidgwick & Jackson', 1975, ['commuter.html', 'spacebird.html'], 'Sidgwick & Jackson, July 1975. Omnibus of New Writings in SF 21, 22 and 23.', 'New Writings in SF', NULL],
  'The Best from Galaxy 3' => [['James Baen'], 'Award Books', 1975, ['answearcametherenone.html'], 'Award Books, 1975.', 'The Best from Galaxy', 3],
];

// Publishers and later editions for anthologies already in Drupal.
$existing = [
  'New Writings in SF 7' => [NULL, 'Dennis Dobson, January 1966. Corgi paperback, August 1966.'],
  'New Writings in SF 12' => [NULL, 'Corgi paperback, May 1968. Dennis Dobson hardback.'],
  'New Writings in SF 14' => [NULL, 'Dennis Dobson, January 1969. Corgi, April 1969.'],
  'New Writings in SF 16' => [NULL, 'Dobson, January 1970. Corgi paperback, March 1970.'],
  'New Writings in SF 18' => [NULL, 'Corgi, March 1971. Dennis Dobson, June 1971.'],
  'New Writings in SF 21' => [NULL, 'Sidgwick & Jackson, November 1972. Corgi paperback, September 1973.'],
  'New Writings in SF 22' => [NULL, 'Sidgwick & Jackson, March 1973. Corgi paperback, April 1974.'],
  'Galactic Empires' => ['Futura', 'Volume 1. Futura, Autumn 1976; Weidenfeld & Nicolson hardcover. Avon paperback, $2.25, February 1979. Volumes 1 and 2 combined, Arrow Books, £4.99, 1988.'],
  'The Best of British SF 2' => ['Futura', 'Futura, Autumn 1977.'],
];

$state = \Drupal::state();
$map = $state->get('sectorgeneral.oldsite_import', []);
$term = function (string $vid, ?string $name): ?int {
  if (!$name) {
    return NULL;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $found = $storage->loadByProperties(['vid' => $vid, 'name' => $name]);
  $t = $found ? reset($found) : $storage->create(['vid' => $vid, 'name' => $name]);
  $t->isNew() && $t->save();
  return (int) $t->id();
};
$notes = fn(string $text) => $text ? ['value' => '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>', 'format' => 'basic_html'] : NULL;

foreach ($anthologies as $title => $row) {
  [$editors, $publisher, $year, $stories, $note] = $row;
  $series = $row[5] ?? NULL;
  $number = $row[6] ?? NULL;
  $key = 'anthology:' . $title;
  $node = isset($map[$key]) ? Node::load($map[$key]) : NULL;
  $is_new = !$node;
  $node ??= Node::create(['type' => 'anthology', 'uid' => 1, 'status' => 1, 'title' => $title]);
  $fill = function (string $field, $value) use ($node) {
    if ($value !== NULL && $value !== [] && $node->get($field)->isEmpty()) {
      $node->set($field, $value);
    }
  };
  $fill('field_editor', array_map(fn($n) => $term('people', $n), $editors));
  $fill('field_publisher', $term('publisher', $publisher));
  $fill('field_first_published_year', $year);
  $fill('field_series', $term('series', $series));
  $fill('field_series_number', $number);
  $fill('body', $notes($note));
  $fill('field_data_sources', SG_SOURCE . ' (story publication histories).');
  // Add the stories, keeping any an editor has already listed.
  $current = array_column($node->get('field_contents')->getValue(), 'target_id');
  foreach ($stories as $story) {
    $nid = $map['story:' . $story] ?? NULL;
    if ($nid && !in_array($nid, $current)) {
      $node->get('field_contents')->appendItem($nid);
    }
  }
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Reprint anthology from the old site\'s story publication histories.');
  $node->save();
  $map[$key] = (int) $node->id();
  echo ($is_new ? 'created' : 'updated') . " {$node->id()}: $title (" . $node->get('field_contents')->count() . " stories)\n";
}

foreach ($existing as $title => [$publisher, $note]) {
  $node = Node::load($map['anthology:' . $title] ?? 0);
  if (!$node) {
    continue;
  }
  if ($publisher && $node->get('field_publisher')->isEmpty()) {
    $node->set('field_publisher', $term('publisher', $publisher));
  }
  if ($node->get('body')->isEmpty()) {
    $node->set('body', $notes($note));
  }
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Publisher and editions from the old site\'s story publication histories.');
  $node->save();
  echo "updated {$node->id()}: $title\n";
}

$state->set('sectorgeneral.oldsite_import', $map);
