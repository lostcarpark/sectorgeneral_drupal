<?php

/**
 * Builds the Sector General content structure. Safe to re-run.
 *
 * Run with: drush php:script build_structure.php
 */

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;

$etm = \Drupal::entityTypeManager();
$repo = \Drupal::service('entity_display.repository');

// Remembers each field's type and target so widgets/formatters can be chosen.
$GLOBALS['sg_fields'] = [];

function sg_log($msg) {
  echo $msg . "\n";
}

function sg_vocab($id, $label, $description) {
  if (!Vocabulary::load($id)) {
    Vocabulary::create(['vid' => $id, 'name' => $label, 'description' => $description])->save();
    sg_log("vocab $id");
  }
}

function sg_term($vid, $name, $values = []) {
  $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
  if ($existing) {
    return reset($existing);
  }
  $term = Term::create(['vid' => $vid, 'name' => $name] + $values);
  $term->save();
  return $term;
}

/**
 * Creates (or updates) a field.
 *
 * $def keys: type, label, description, cardinality, required, target
 * (entity type), bundles (target bundles), storage (extra storage settings),
 * settings (extra field settings), auto_create.
 */
function sg_field($entity_type, $bundle, $name, array $def) {
  $storage = FieldStorageConfig::loadByName($entity_type, $name);
  if (!$storage) {
    $storage_settings = $def['storage'] ?? [];
    if (isset($def['target'])) {
      $storage_settings['target_type'] = $def['target'];
    }
    $storage = FieldStorageConfig::create([
      'entity_type' => $entity_type,
      'field_name' => $name,
      'type' => $def['type'],
      'cardinality' => $def['cardinality'] ?? 1,
      'settings' => $storage_settings,
    ]);
    $storage->save();
  }
  elseif (isset($def['cardinality']) && $storage->getCardinality() != $def['cardinality']) {
    $storage->setCardinality($def['cardinality'])->save();
  }

  $field = FieldConfig::loadByName($entity_type, $bundle, $name);
  $is_new = !$field;
  if ($is_new) {
    $field = FieldConfig::create(['field_storage' => $storage, 'bundle' => $bundle]);
  }
  $field->setLabel($def['label']);
  $field->setDescription($def['description'] ?? '');
  $field->setRequired($def['required'] ?? FALSE);
  if (isset($def['bundles'])) {
    $handler = 'default:' . $storage->getSetting('target_type');
    $handler_settings = ['target_bundles' => array_combine($def['bundles'], $def['bundles'])];
    if (!empty($def['auto_create'])) {
      $handler_settings['auto_create'] = TRUE;
      $handler_settings['auto_create_bundle'] = reset($def['bundles']);
    }
    $field->setSetting('handler', $handler);
    $field->setSetting('handler_settings', $handler_settings);
  }
  foreach ($def['settings'] ?? [] as $key => $value) {
    $field->setSetting($key, $value);
  }
  if (isset($def['default'])) {
    $field->setDefaultValue($def['default']);
  }
  $field->save();
  if ($is_new) {
    sg_log("field $entity_type.$bundle.$name");
  }
  $GLOBALS['sg_fields']["$entity_type.$name"] = [
    'type' => $storage->getType(),
    'target' => $storage->getSetting('target_type'),
    'multiple' => $storage->getCardinality() != 1,
  ];
}

function sg_widget($entity_type, $name) {
  $f = $GLOBALS['sg_fields']["$entity_type.$name"] ?? NULL;
  if ($name === 'body') {
    return ['type' => 'text_textarea_with_summary', 'settings' => ['rows' => 9, 'summary_rows' => 3, 'placeholder' => '', 'show_summary' => FALSE]];
  }
  if ($name === 'title') {
    return ['type' => 'string_textfield'];
  }
  switch ($f['type']) {
    case 'entity_reference':
      if ($f['target'] === 'media') {
        return ['type' => 'media_library_widget', 'settings' => ['media_types' => ['cover_image']]];
      }
      if ($f['target'] === 'taxonomy_term' && $f['multiple']) {
        return ['type' => 'entity_reference_autocomplete_tags'];
      }
      return ['type' => 'entity_reference_autocomplete'];

    case 'list_string':
      return ['type' => 'options_select'];

    case 'boolean':
      return ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]];

    case 'integer':
    case 'decimal':
      return ['type' => 'number'];

    case 'link':
      return ['type' => 'link_default'];

    case 'datetime':
      return ['type' => 'datetime_default'];

    case 'string_long':
      return ['type' => 'string_textarea', 'settings' => ['rows' => 3]];

    case 'text_long':
      return ['type' => 'text_textarea', 'settings' => ['rows' => 4]];

    case 'image':
      return ['type' => 'image_image', 'settings' => ['preview_image_style' => 'cover_small', 'progress_indicator' => 'throbber']];

    default:
      return ['type' => 'string_textfield'];
  }
}

// Fields naming people: placeholders like "Uncredited" show unlinked.
const SG_PEOPLE_FIELDS = ['field_editor', 'field_cover_artist', 'field_interior_artist', 'field_translator', 'field_introduction_by', 'field_narrator', 'field_other_contributors'];

function sg_formatter($entity_type, $name) {
  $f = $GLOBALS['sg_fields']["$entity_type.$name"] ?? NULL;
  if (in_array($name, SG_PEOPLE_FIELDS, TRUE)) {
    return ['type' => 'sectorgeneral_people_label', 'label' => 'inline', 'settings' => ['link' => TRUE]];
  }
  if ($name === 'body') {
    return ['type' => 'text_default', 'label' => 'hidden'];
  }
  if ($name === 'field_data_sources') {
    return ['type' => 'sectorgeneral_data_sources', 'label' => 'above'];
  }
  switch ($f['type']) {
    case 'entity_reference':
      if ($f['target'] === 'media') {
        return ['type' => 'entity_reference_entity_view', 'label' => 'hidden', 'settings' => ['view_mode' => 'default']];
      }
      return ['type' => 'entity_reference_label', 'label' => 'inline', 'settings' => ['link' => TRUE]];

    case 'list_string':
      return ['type' => 'list_default', 'label' => 'inline'];

    case 'boolean':
      return ['type' => 'boolean', 'label' => 'inline'];

    case 'integer':
      // Years and numbers in series must not read "1,962".
      return ['type' => 'number_integer', 'label' => 'inline', 'settings' => ['thousand_separator' => $name === 'field_words' ? ',' : '']];

    case 'decimal':
      return ['type' => 'number_decimal', 'label' => 'inline'];

    case 'link':
      return ['type' => 'link', 'label' => 'inline'];

    case 'datetime':
      return ['type' => 'datetime_default', 'label' => 'inline'];

    case 'string_long':
      return ['type' => 'basic_string', 'label' => 'above'];

    case 'text_long':
      return ['type' => 'text_default', 'label' => 'above'];

    default:
      return ['type' => 'string', 'label' => 'inline'];
  }
}

/**
 * Lays out a form display: top-level fields, then collapsible groups.
 *
 * $layout is a list of field names or ['group_x' => [label, open, fields]].
 */
function sg_form_layout($entity_type, $bundle, array $layout, $mode = 'default') {
  $display = \Drupal::service('entity_display.repository')->getFormDisplay($entity_type, $bundle, $mode);
  foreach (array_keys($display->getThirdPartySettings('field_group')) as $group) {
    $display->unsetThirdPartySetting('field_group', $group);
  }
  $weight = 0;
  foreach ($layout as $key => $item) {
    if (is_string($item)) {
      $display->setComponent($item, ['weight' => $weight++] + sg_widget($entity_type, $item));
      continue;
    }
    [$label, $open, $fields] = $item;
    foreach ($fields as $field) {
      $display->setComponent($field, ['weight' => $weight++] + sg_widget($entity_type, $field));
    }
    $display->setThirdPartySetting('field_group', $key, [
      'children' => $fields,
      'label' => $label,
      'parent_name' => '',
      'region' => 'content',
      'weight' => $weight++,
      'format_type' => 'details',
      'format_settings' => ['open' => $open, 'description' => '', 'required_fields' => TRUE, 'id' => '', 'classes' => ''],
    ]);
  }
  $display->save();
}

/**
 * Lays out a view display; any field not listed is hidden.
 */
function sg_view_layout($entity_type, $bundle, array $fields, $mode = 'default', array $overrides = []) {
  $display = \Drupal::service('entity_display.repository')->getViewDisplay($entity_type, $bundle, $mode);
  foreach (array_keys($display->getComponents()) as $existing) {
    if (!in_array($existing, $fields) && $existing !== 'links') {
      $display->removeComponent($existing);
    }
  }
  foreach ($fields as $weight => $field) {
    $options = $overrides[$field] ?? sg_formatter($entity_type, $field);
    $display->setComponent($field, ['weight' => $weight] + $options);
  }
  $display->save();
}

// ---------------------------------------------------------------------------
// Image styles and the cover image media type.

foreach (['cover_small' => [160, 'Cover small (160 wide)'], 'cover_medium' => [400, 'Cover medium (400 wide)'], 'cover_large' => [800, 'Cover large (800 wide)']] as $id => [$width, $label]) {
  if (!ImageStyle::load($id)) {
    $style = ImageStyle::create(['name' => $id, 'label' => $label]);
    $style->addImageEffect(['id' => 'image_scale', 'weight' => 0, 'data' => ['width' => $width, 'height' => NULL, 'upscale' => FALSE]]);
    $style->save();
    sg_log("image style $id");
  }
}

sg_vocab('image_source', 'Image source', 'Where a cover scan was found. Used in the credit line beneath each cover.');
sg_field('taxonomy_term', 'image_source', 'field_website', ['type' => 'link', 'label' => 'Website', 'settings' => ['title' => 0, 'link_type' => 16]]);

if (!MediaType::load('cover_image')) {
  $type = MediaType::create([
    'id' => 'cover_image',
    'label' => 'Cover image',
    'description' => 'A scan of a book, magazine or edition cover, with its source credit.',
    'source' => 'image',
    'source_configuration' => ['source_field' => 'field_media_image'],
    'field_map' => ['name' => 'name'],
  ]);
  $type->save();
  sg_log('media type cover_image');
}
sg_field('media', 'cover_image', 'field_media_image', [
  'type' => 'image',
  'label' => 'Image',
  'description' => 'Upload the largest, best-quality scan available. Smaller sizes are generated automatically.',
  'required' => TRUE,
  'settings' => ['file_directory' => 'covers/[date:custom:Y-m]', 'alt_field_required' => TRUE, 'file_extensions' => 'png gif jpg jpeg webp', 'max_resolution' => '', 'min_resolution' => ''],
]);
sg_field('media', 'cover_image', 'field_image_source', [
  'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['image_source'], 'auto_create' => TRUE,
  'label' => 'Image source', 'description' => 'Where this scan was found (e.g. ISFDB, Open Library, own scan).',
]);
sg_field('media', 'cover_image', 'field_source_url', [
  'type' => 'link', 'label' => 'Source page', 'description' => 'The exact page the scan was taken from, if online.',
  'settings' => ['title' => 0, 'link_type' => 16],
]);
sg_field('media', 'cover_image', 'field_scanned_by', ['type' => 'string', 'label' => 'Scanned by', 'description' => 'Credit for a reader or editor who supplied their own scan.']);
sg_field('media', 'cover_image', 'field_takedown_date', [
  'type' => 'datetime', 'storage' => ['datetime_type' => 'date'], 'label' => 'Taken down on',
  'description' => 'If this image was unpublished following a complaint, record when.',
]);
sg_field('media', 'cover_image', 'field_takedown_reason', ['type' => 'string_long', 'label' => 'Takedown reason', 'description' => 'Who asked and why. Keeps the image from being re-added by mistake.']);
sg_field('media', 'cover_image', 'field_image_url', [
  'type' => 'link', 'label' => 'Image file address',
  'description' => 'Where the image file itself was downloaded from. Used to avoid adding the same image twice.',
  'settings' => ['title' => 0, 'link_type' => 16],
]);
sg_field('media', 'cover_image', 'field_image_hash', [
  'type' => 'string', 'label' => 'Image fingerprint',
  'description' => 'SHA-1 of the image file, so the same image from different addresses is recognised.',
]);
$GLOBALS['sg_fields']['media.name'] = ['type' => 'string'];

sg_form_layout('media', 'cover_image', [
  'name', 'field_media_image', 'field_image_source', 'field_source_url', 'field_scanned_by',
  'group_takedown' => ['Takedown record', FALSE, ['field_takedown_date', 'field_takedown_reason']],
  'group_file' => ['Image file', FALSE, ['field_image_url', 'field_image_hash']],
]);
sg_form_layout('media', 'cover_image', ['name', 'field_media_image'], 'media_library');

// cover_card: the book cards on listing pages (about 210px wide, so the
// 400px style keeps them sharp on high-resolution screens).
foreach (['cover_small' => 'Cover small', 'cover_card' => 'Cover card'] as $mode => $label) {
  if (!EntityViewMode::load("media.$mode")) {
    EntityViewMode::create(['id' => "media.$mode", 'label' => $label, 'targetEntityType' => 'media'])->save();
  }
}
$image_formatter = fn($style, $link = '') => ['type' => 'image', 'label' => 'hidden', 'settings' => ['image_style' => $style, 'image_link' => $link, 'image_loading' => ['attribute' => 'lazy']]];
sg_view_layout('media', 'cover_image', ['field_media_image', 'cover_credit'], 'default', [
  'field_media_image' => $image_formatter('cover_medium', 'file'),
  'cover_credit' => [],
]);
foreach (['cover_small' => 'cover_small', 'cover_card' => 'cover_medium'] as $mode => $style) {
  sg_view_layout('media', 'cover_image', ['field_media_image'], $mode, [
    'field_media_image' => $image_formatter($style),
  ]);
  $repo->getViewDisplay('media', 'cover_image', $mode)->setStatus(TRUE)->save();
}

// ---------------------------------------------------------------------------
// Vocabularies.

sg_vocab('people', 'People', 'Cover artists, illustrators, translators, editors and other contributors.');
sg_vocab('series', 'Series', 'Series of related books and stories, such as Sector General.');
sg_vocab('magazine', 'Magazine', 'Magazines in which stories appeared, such as New Worlds or Nebula.');
sg_vocab('publisher_series', 'Publisher series', 'Imprint lines such as the Corgi SF Collectors\' Library.');

sg_field('taxonomy_term', 'people', 'field_isfdb_id', ['type' => 'string', 'label' => 'ISFDB ID', 'description' => 'The number at the end of the person\'s ISFDB page address.']);
sg_field('taxonomy_term', 'people', 'field_wikipedia', ['type' => 'link', 'label' => 'Wikipedia', 'settings' => ['title' => 0, 'link_type' => 16]]);
sg_field('taxonomy_term', 'series', 'field_isfdb_id', ['type' => 'string', 'label' => 'ISFDB ID']);
sg_field('taxonomy_term', 'magazine', 'field_isfdb_id', ['type' => 'string', 'label' => 'ISFDB ID']);
sg_field('taxonomy_term', 'magazine', 'field_website', ['type' => 'link', 'label' => 'Reference page', 'description' => 'e.g. the magazine\'s Galactic Central or Wikipedia page.', 'settings' => ['title' => 0, 'link_type' => 16]]);

// Defaults to unticked, so every person has a value the People lists can
// filter on (an empty value would drop them from the lists).
sg_field('taxonomy_term', 'people', 'field_not_a_person', [
  'type' => 'boolean', 'label' => 'Not a person', 'default' => [['value' => 0]],
  'description' => 'For placeholders such as "Uncredited": shown as plain text, not a link, and left out of the People lists.',
]);
$people_form = $repo->getFormDisplay('taxonomy_term', 'people');
$people_form->setComponent('field_not_a_person', ['type' => 'boolean_checkbox', 'weight' => 5, 'settings' => ['display_label' => TRUE]])->save();
$people_view = $repo->getViewDisplay('taxonomy_term', 'people');
$people_view->removeComponent('field_not_a_person')->save();
$uncredited = sg_term('people', 'Uncredited', ['description' => ['value' => 'Use when the edition does not credit the cover artist and no attribution is known.', 'format' => 'basic_html']]);
if (!$uncredited->get('field_not_a_person')->value) {
  $uncredited->set('field_not_a_person', TRUE)->save();
}
// Terms created before the field existed have no value: give them "no".
$db = \Drupal::database();
foreach (['taxonomy_term__field_not_a_person' => 'taxonomy_term_field_data', 'taxonomy_term_revision__field_not_a_person' => 'taxonomy_term_field_revision'] as $table => $base) {
  $db->query("INSERT INTO {" . $table . "} (bundle, deleted, entity_id, revision_id, langcode, delta, field_not_a_person_value)
    SELECT 'people', 0, t.tid, t.revision_id, t.langcode, 0, 0 FROM {" . $base . "} t
    INNER JOIN {taxonomy_term_data} d ON d.tid = t.tid
    LEFT JOIN {" . $table . "} f ON f.entity_id = t.tid" . ($table === 'taxonomy_term_revision__field_not_a_person' ? ' AND f.revision_id = t.revision_id' : '') . "
    WHERE d.vid = 'people' AND f.entity_id IS NULL");
}
\Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache();
foreach ([
  'ISFDB' => 'https://www.isfdb.org/',
  'Open Library' => 'https://openlibrary.org/',
  'Galactic Central' => 'https://www.philsp.com/',
  'James White Information (original site)' => 'http://www.sectorgeneral.com/',
  'Own scan' => NULL,
  'Reader contribution' => NULL,
] as $name => $url) {
  sg_term('image_source', $name, $url ? ['field_website' => ['uri' => $url]] : []);
}
sg_term('series', 'Sector General');
foreach (['New Worlds', 'Nebula', 'Science Fantasy', 'Astounding Science Fiction', 'Analog Science Fiction and Fact', 'The Magazine of Fantasy & Science Fiction', 'Galaxy'] as $name) {
  sg_term('magazine', $name);
}

// ---------------------------------------------------------------------------
// Content types.

$novel = NodeType::load('novel');
$novel->set('name', 'Book');
$novel->set('description', 'A book by James White: novel, collection, omnibus or non-fiction. Also anthologies by other editors that include his stories.');
$novel->save();

foreach ([
  'short_story' => ['Short story', 'A short story, novelette or novella. Link it to collections and magazines from their Contents field.'],
  'magazine_issue' => ['Magazine issue', 'A single issue of a magazine in which a story appeared. Title it like "New Worlds 95, June 1960".'],
  'anthology' => ['Anthology', 'A book of stories by several authors, edited by someone else, that includes at least one James White story.'],
] as $id => [$label, $description]) {
  if (!NodeType::load($id)) {
    $type = NodeType::create(['type' => $id, 'name' => $label, 'description' => $description]);
    $type->setDisplaySubmitted(FALSE);
    $type->save();
    sg_log("node type $id");
  }
}
node_add_body_field(NodeType::load('short_story'), 'Synopsis');
node_add_body_field(NodeType::load('magazine_issue'), 'Notes');
node_add_body_field(NodeType::load('anthology'), 'Notes');
foreach (['novel' => 'Synopsis', 'edition' => 'Notes'] as $bundle => $label) {
  FieldConfig::loadByName('node', $bundle, 'body')->setLabel($label)->save();
}
foreach (['novel', 'edition', 'short_story', 'magazine_issue', 'anthology'] as $bundle) {
  $type = NodeType::load($bundle);
  $type->setDisplaySubmitted(FALSE);
  $type->save();
}

// Shared definitions, reused across bundles.
$people = fn($label, $description) => [
  'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['people'], 'auto_create' => TRUE,
  'cardinality' => -1, 'label' => $label, 'description' => $description . ' Separate several names with commas.',
];
$link_multi = fn($label, $description) => [
  'type' => 'link', 'cardinality' => -1, 'label' => $label, 'description' => $description,
  'settings' => ['title' => 1, 'link_type' => 16],
];
$defs = [
  'field_cover' => [
    'type' => 'entity_reference', 'target' => 'media', 'bundles' => ['cover_image'],
    'label' => 'Cover', 'description' => 'Choose an existing cover from the library or upload a new one.',
  ],
  'field_series' => [
    'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['series'], 'auto_create' => TRUE,
    'label' => 'Series',
  ],
  'field_series_number' => ['type' => 'integer', 'label' => 'Number in series', 'description' => 'Reading order within the series, e.g. 1 for Hospital Station.'],
  'field_synopsis_credit' => ['type' => 'string', 'label' => 'Synopsis by', 'description' => 'e.g. Graham Andrews.'],
  'field_alternative_titles' => [
    'type' => 'string', 'cardinality' => -1, 'label' => 'Alternative titles',
    'description' => 'Other English titles this work was published under (e.g. The Escape Orbit). Translated titles belong on the edition.',
  ],
  'field_first_published_year' => ['type' => 'integer', 'label' => 'First published', 'description' => 'Year of first publication, used for sorting.'],
  'field_isfdb_id' => ['type' => 'string', 'label' => 'ISFDB ID', 'description' => 'The number at the end of the ISFDB page address for this record.'],
  'field_wikidata_id' => ['type' => 'string', 'label' => 'Wikidata ID', 'description' => 'e.g. Q123456.'],
  'field_wikipedia' => ['type' => 'link', 'label' => 'Wikipedia', 'settings' => ['title' => 0, 'link_type' => 16]],
  'field_reviews' => $link_multi('Reviews', 'Links to reviews. Put the reviewer and publication in the link text.'),
  'field_data_sources' => ['type' => 'string_long', 'label' => 'Data sources', 'description' => 'Where these details came from (e.g. ISFDB, original James White Information site, own copy). Shown as attribution.'],
  'field_contents' => [
    'type' => 'entity_reference', 'target' => 'node', 'bundles' => ['short_story'], 'cardinality' => -1,
    'label' => 'Contents', 'description' => 'Stories in this volume, in order. Drag to reorder. Each story\'s page lists where it appeared automatically.',
  ],
  'field_editor' => $people('Editor', 'Editor of the anthology or magazine issue.'),
  'field_cover_artist' => $people('Cover artist', 'Use "Uncredited" if the artist is unknown.'),
  'field_interior_artist' => $people('Interior artist', 'Illustrator of interior artwork.'),
  'field_pages' => ['type' => 'integer', 'label' => 'Pages'],
  'field_price' => ['type' => 'string', 'label' => 'Price', 'description' => 'As printed, e.g. 3/6d or 50 cents.'],
  'field_year' => ['type' => 'integer', 'label' => 'Year', 'description' => 'Year of publication, used for sorting.'],
  'field_date_display' => ['type' => 'string', 'label' => 'Date as shown', 'description' => 'As precise as is known, e.g. "May 1962", "mid-1979" or "1975".'],
  'field_words' => ['type' => 'integer', 'label' => 'Words', 'description' => 'Approximate word count.'],
];

// Book.
foreach (['field_cover', 'field_series', 'field_series_number', 'field_synopsis_credit', 'field_alternative_titles', 'field_first_published_year', 'field_isfdb_id', 'field_wikidata_id', 'field_wikipedia', 'field_reviews', 'field_data_sources', 'field_contents', 'field_editor'] as $name) {
  sg_field('node', 'novel', $name, $defs[$name]);
}
// Anthologies by other editors have their own content type.
$book_types = [
  'novel' => 'Novel',
  'fixup' => 'Fix-up novel',
  'collection' => 'Collection',
  'omnibus' => 'Omnibus',
  'chapbook' => 'Chapbook',
  'nonfiction' => 'Non-fiction',
];
sg_field('node', 'novel', 'field_book_type', [
  'type' => 'list_string', 'label' => 'Type of book', 'required' => TRUE, 'default' => [['value' => 'novel']],
  'description' => 'A fix-up novel is one built from stories first published separately, such as Hospital Station.',
  'storage' => ['allowed_values' => $book_types],
]);
// Allowed values are only set when the storage is created; keep them current.
// Values still in use stay until that content has moved on.
$in_use = \Drupal::database()->query('SELECT DISTINCT field_book_type_value FROM {node__field_book_type}')->fetchCol();
$storage = FieldStorageConfig::loadByName('node', 'field_book_type');
$current = $storage->getSetting('allowed_values');
foreach (array_diff($in_use, array_keys($book_types)) as $value) {
  $book_types[$value] = $current[$value] ?? $value;
  sg_log("book type '$value' kept: still in use");
}
$storage->setSetting('allowed_values', $book_types)->save();
sg_field('node', 'novel', 'field_contained_books', [
  'type' => 'entity_reference', 'target' => 'node', 'bundles' => ['novel'], 'cardinality' => -1,
  'label' => 'Books included', 'description' => 'For omnibus volumes: the books it collects, in order.',
]);
sg_field('node', 'novel', 'field_publisher', ['label' => 'Original publisher', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['publisher'], 'auto_create' => TRUE]);
sg_field('node', 'novel', 'field_words', $defs['field_words']);

// Edition.
foreach (['field_cover', 'field_cover_artist', 'field_interior_artist', 'field_pages', 'field_price', 'field_year', 'field_date_display', 'field_isfdb_id', 'field_data_sources'] as $name) {
  sg_field('node', 'edition', $name, $defs[$name]);
}
sg_field('node', 'edition', 'field_novel', [
  'type' => 'entity_reference', 'target' => 'node', 'bundles' => ['novel'], 'cardinality' => -1, 'required' => TRUE,
  'label' => 'Book', 'description' => 'The book this is an edition of. Add more than one if the volume combines several books.',
]);
sg_field('node', 'edition', 'field_translator', $people('Translator', 'For translated editions.'));
sg_field('node', 'edition', 'field_introduction_by', $people('Introduction by', 'Writers of an introduction, foreword or afterword.'));
sg_field('node', 'edition', 'field_narrator', $people('Narrator', 'For audiobooks.'));
sg_field('node', 'edition', 'field_publisher', ['label' => 'Publisher', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['publisher'], 'auto_create' => TRUE]);
sg_field('node', 'edition', 'field_language', ['label' => 'Language', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['language'], 'auto_create' => TRUE]);
sg_field('node', 'edition', 'field_format', ['label' => 'Format', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['format']]);
sg_field('node', 'edition', 'field_publisher_series', [
  'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['publisher_series'], 'auto_create' => TRUE,
  'label' => 'Publisher series', 'description' => 'e.g. SF Collectors\' Library.',
]);
sg_field('node', 'edition', 'field_publisher_series_number', ['type' => 'string', 'label' => 'Number in publisher series']);
sg_field('node', 'edition', 'field_publisher_reference', ['type' => 'string', 'label' => 'Catalogue number', 'description' => 'Publisher\'s stock number, e.g. "No 595" or "GS7651".']);
sg_field('node', 'edition', 'field_publication_date', ['type' => 'datetime', 'label' => 'Exact publication date', 'description' => 'Only if the exact date is known. Otherwise just fill in Year and Date as shown.']);
sg_field('node', 'edition', 'field_printing', ['type' => 'string', 'label' => 'Edition / printing', 'description' => 'e.g. "First edition", "4th printing", "Book club edition".']);
sg_field('node', 'edition', 'field_isbn', ['type' => 'string', 'label' => 'ISBN', 'description' => 'ISBN-13 preferred, without hyphens. Leave blank for books published before ISBNs.']);
sg_field('node', 'edition', 'field_oclc', ['type' => 'string', 'label' => 'OCLC / WorldCat number']);
sg_field('node', 'edition', 'field_openlibrary_id', ['type' => 'string', 'label' => 'Open Library ID', 'description' => 'e.g. OL12345M.']);
sg_field('node', 'edition', 'field_buy_links', $link_multi('Where to buy', 'Retailer links, for editions still in print.'));
sg_field('node', 'edition', 'field_verified', ['type' => 'boolean', 'label' => 'Verified against a physical copy']);
sg_field('node', 'edition', 'field_verified_note', ['type' => 'string', 'label' => 'Verification note', 'description' => 'Who checked it and when.']);
sg_field('node', 'edition', 'field_in_anthology', [
  'type' => 'entity_reference', 'target' => 'node', 'bundles' => ['anthology'],
  'label' => 'Published in', 'description' => 'If this edition is part of an anthology, such as a novel reprinted in Out of This World 8.',
]);

// Short story.
foreach (['field_series', 'field_series_number', 'field_synopsis_credit', 'field_alternative_titles', 'field_first_published_year', 'field_words', 'field_isfdb_id', 'field_wikidata_id', 'field_wikipedia', 'field_reviews', 'field_data_sources'] as $name) {
  sg_field('node', 'short_story', $name, $defs[$name]);
}
sg_field('node', 'short_story', 'field_opening_excerpt', ['type' => 'text_long', 'label' => 'Opening lines', 'description' => 'A short quotation from the start of the story.']);
sg_field('node', 'short_story', 'field_publication_notes', ['type' => 'text_long', 'label' => 'Publication notes', 'description' => 'Other appearances: anthologies by other editors, translations, broadcasts.']);

// Magazine issue.
foreach (['field_cover', 'field_contents', 'field_editor', 'field_cover_artist', 'field_interior_artist', 'field_pages', 'field_price', 'field_year', 'field_date_display', 'field_isfdb_id'] as $name) {
  sg_field('node', 'magazine_issue', $name, $defs[$name]);
}
sg_field('node', 'magazine_issue', 'field_magazine', [
  'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['magazine'], 'auto_create' => TRUE, 'required' => TRUE,
  'label' => 'Magazine',
]);
sg_field('node', 'magazine_issue', 'field_issue_number', ['type' => 'string', 'label' => 'Issue number', 'description' => 'As printed, e.g. "95" or "Vol. 3 No. 2".']);
// A serialised novel's instalment lists the book itself.
sg_field('node', 'magazine_issue', 'field_contents', ['description' => 'The James White stories in this issue, and any book serialised in it.', 'bundles' => ['short_story', 'novel']] + $defs['field_contents']);
sg_field('node', 'magazine_issue', 'field_interior_artist', ['label' => 'Illustrations by', 'description' => 'Who illustrated James White\'s story or serial in this issue.'] + $defs['field_interior_artist']);
sg_field('node', 'magazine_issue', 'field_other_contributors', $people('Other contributors', 'Other authors with fiction or poetry in this issue.'));
sg_field('node', 'magazine_issue', 'field_published_as', [
  'type' => 'string', 'cardinality' => -1, 'label' => 'Published as',
  'description' => 'James White\'s work as titled in this issue, e.g. "Turistplaneten (Part 1 of 5)". Serial parts are numbered from this when it says.',
]);
sg_field('node', 'magazine_issue', 'field_regional_edition', [
  'type' => 'string', 'label' => 'Edition',
  'description' => 'When a magazine came out in more than one country\'s edition for the same month, which this is, e.g. "UK edition".',
]);
sg_field('node', 'magazine_issue', 'field_language', ['label' => 'Language', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['language'], 'auto_create' => TRUE]);
sg_field('node', 'magazine_issue', 'field_data_sources', $defs['field_data_sources']);

// A hidden date to sort editions and issues by, worked out on save from
// the year and date as shown (sectorgeneral's SortDateHooks).
foreach (['edition', 'magazine_issue'] as $bundle) {
  sg_field('node', $bundle, 'field_sort_date', [
    'type' => 'datetime', 'storage' => ['datetime_type' => 'date'], 'label' => 'Sort date',
    'description' => 'Worked out automatically from the year and date as shown.',
  ]);
}

// Anthology.
foreach (['field_cover', 'field_contents', 'field_editor', 'field_series', 'field_isfdb_id', 'field_data_sources'] as $name) {
  sg_field('node', 'anthology', $name, $defs[$name]);
}
// Anthologies sometimes reprint a whole (often shortened) novel.
sg_field('node', 'anthology', 'field_contents', ['description' => 'The James White stories in this volume, in order, and any whole book it reprints.', 'bundles' => ['short_story', 'novel']] + $defs['field_contents']);
sg_field('node', 'anthology', 'field_series_number', ['label' => 'Volume number', 'description' => 'e.g. 18 for New Writings in SF 18.'] + $defs['field_series_number']);
sg_field('node', 'anthology', 'field_first_published_year', ['label' => 'Year', 'description' => 'Year of publication.'] + $defs['field_first_published_year']);
sg_field('node', 'anthology', 'field_publisher', ['label' => 'Publisher', 'type' => 'entity_reference', 'target' => 'taxonomy_term', 'bundles' => ['publisher'], 'auto_create' => TRUE]);
sg_field('node', 'anthology', 'field_other_contributors', $people('Other contributors', 'Other authors with stories in this volume.'));

$GLOBALS['sg_fields']['node.title'] = ['type' => 'string'];
$GLOBALS['sg_fields']['node.body'] = ['type' => 'text_with_summary'];

echo "Structure built.\n";

// ---------------------------------------------------------------------------
// Form layouts. Core fields (path, status, author…) keep their own places.

$refs = ['field_isfdb_id', 'field_wikidata_id', 'field_wikipedia', 'field_reviews', 'field_data_sources'];

sg_form_layout('node', 'novel', [
  'title', 'field_book_type', 'field_cover', 'body', 'field_synopsis_credit',
  'group_publication' => ['Series and publication', TRUE, ['field_series', 'field_series_number', 'field_first_published_year', 'field_publisher', 'field_words', 'field_alternative_titles']],
  'group_contents' => ['Contents', TRUE, ['field_contents', 'field_contained_books', 'field_editor']],
  'group_references' => ['References and sources', FALSE, $refs],
]);

sg_form_layout('node', 'edition', [
  'title', 'field_novel', 'field_cover', 'field_language', 'field_format',
  'group_publication' => ['Publication', TRUE, ['field_in_anthology', 'field_publisher', 'field_year', 'field_date_display', 'field_publication_date', 'field_printing', 'field_publisher_series', 'field_publisher_series_number', 'field_publisher_reference', 'field_isbn', 'field_pages', 'field_price']],
  'group_people' => ['Contributors', TRUE, ['field_cover_artist', 'field_interior_artist', 'field_translator', 'field_introduction_by', 'field_narrator']],
  'group_notes' => ['Notes and links', FALSE, ['body', 'field_buy_links']],
  'group_references' => ['References and verification', FALSE, ['field_isfdb_id', 'field_oclc', 'field_openlibrary_id', 'field_data_sources', 'field_verified', 'field_verified_note']],
]);

sg_form_layout('node', 'short_story', [
  'title', 'field_first_published_year', 'field_words', 'body', 'field_synopsis_credit', 'field_opening_excerpt', 'field_publication_notes',
  'group_series' => ['Series and other titles', FALSE, ['field_series', 'field_series_number', 'field_alternative_titles']],
  'group_references' => ['References and sources', FALSE, $refs],
]);

sg_form_layout('node', 'magazine_issue', [
  'title', 'field_magazine', 'field_regional_edition', 'field_issue_number', 'field_year', 'field_date_display', 'field_language', 'field_cover', 'field_contents', 'field_published_as',
  'group_details' => ['Issue details', FALSE, ['field_editor', 'field_cover_artist', 'field_interior_artist', 'field_other_contributors', 'field_pages', 'field_price', 'body', 'field_isfdb_id', 'field_data_sources']],
]);

sg_form_layout('node', 'anthology', [
  'title', 'field_cover', 'field_editor', 'field_first_published_year', 'field_publisher', 'field_contents',
  'group_series' => ['Series and other contributors', TRUE, ['field_series', 'field_series_number', 'field_other_contributors']],
  'group_references' => ['Notes and sources', FALSE, ['body', 'field_isfdb_id', 'field_data_sources']],
]);

// ---------------------------------------------------------------------------
// View layouts. Full pages show everything useful; teasers show a small cover.

$small_cover = ['type' => 'entity_reference_entity_view', 'label' => 'hidden', 'settings' => ['view_mode' => 'cover_small']];
$contents = ['type' => 'entity_reference_label', 'label' => 'above', 'settings' => ['link' => TRUE]];

// The book page's opening (cover, summary line, synopsis) and its References
// section are laid out by the theme (node--novel--full.html.twig); the order
// here is the order of everything else.
$book_contents = ['type' => 'sectorgeneral_contents_list', 'label' => 'above'];
sg_view_layout('node', 'novel', ['field_cover', 'field_book_type', 'field_series', 'field_series_number', 'field_first_published_year', 'field_publisher', 'field_words', 'field_alternative_titles', 'field_editor', 'body', 'field_synopsis_credit', 'series_nav', 'field_contents', 'field_contained_books', 'field_reviews', 'field_wikipedia', 'field_data_sources'], 'default', [
  'series_nav' => [],
  'field_contents' => $book_contents,
  'field_contained_books' => $book_contents,
  'field_reviews' => ['type' => 'link', 'label' => 'above'],
]);
// Laid out by the theme like a book page (node--edition--full.html.twig).
sg_view_layout('node', 'edition', ['field_cover', 'field_novel', 'field_in_anthology', 'field_language', 'field_format', 'field_publisher', 'field_date_display', 'field_printing', 'field_publisher_series', 'field_publisher_series_number', 'field_publisher_reference', 'field_isbn', 'field_pages', 'field_price', 'field_cover_artist', 'field_interior_artist', 'field_translator', 'field_introduction_by', 'field_narrator', 'body', 'field_buy_links', 'field_verified', 'edition_nav', 'field_data_sources'], 'default', [
  'edition_nav' => [],
]);
sg_view_layout('node', 'short_story', ['field_series', 'field_first_published_year', 'field_words', 'field_alternative_titles', 'field_opening_excerpt', 'body', 'field_synopsis_credit', 'field_publication_notes', 'field_reviews', 'field_wikipedia', 'field_data_sources'], 'default', [
  'field_opening_excerpt' => ['type' => 'text_default', 'label' => 'hidden'],
  'field_reviews' => ['type' => 'link', 'label' => 'above'],
]);
sg_view_layout('node', 'magazine_issue', ['field_cover', 'field_magazine', 'field_regional_edition', 'field_issue_number', 'field_date_display', 'field_language', 'field_editor', 'field_cover_artist', 'field_interior_artist', 'field_pages', 'field_price', 'field_contents', 'field_published_as', 'serial_parts', 'field_other_contributors', 'body', 'field_data_sources'], 'default', [
  'field_contents' => $contents,
  'field_other_contributors' => ['type' => 'sectorgeneral_people_label', 'label' => 'above', 'settings' => ['link' => TRUE]],
  'serial_parts' => [],
]);
sg_view_layout('node', 'anthology', ['field_cover', 'field_editor', 'field_first_published_year', 'field_publisher', 'field_series', 'field_series_number', 'field_contents', 'field_other_contributors', 'body', 'field_data_sources'], 'default', [
  'field_contents' => $contents,
  'field_other_contributors' => ['type' => 'sectorgeneral_people_label', 'label' => 'above', 'settings' => ['link' => TRUE]],
]);

foreach (['novel' => ['field_cover', 'field_book_type', 'field_first_published_year'], 'edition' => ['field_cover', 'field_publisher', 'field_date_display', 'field_language'], 'short_story' => ['field_first_published_year', 'field_words'], 'magazine_issue' => ['field_cover', 'field_date_display'], 'anthology' => ['field_cover', 'field_editor', 'field_first_published_year']] as $bundle => $fields) {
  $overrides = in_array('field_cover', $fields) ? ['field_cover' => $small_cover] : [];
  sg_view_layout('node', $bundle, $fields, 'teaser', $overrides);
  $repo->getViewDisplay('node', $bundle, 'teaser')->setStatus(TRUE)->save();
}

// Languages: a summary line at the top of each language's page, and a "card"
// view mode for the Translations index, laid out by the theme
// (taxonomy-term--language--card.html.twig) from LanguageStats.
$language_display = $repo->getViewDisplay('taxonomy_term', 'language', 'default');
$language_display->setComponent('language_summary', ['weight' => -20])->save();
if (!EntityViewMode::load('taxonomy_term.card')) {
  EntityViewMode::create(['id' => 'taxonomy_term.card', 'label' => 'Card', 'targetEntityType' => 'taxonomy_term'])->save();
}
$card = $repo->getViewDisplay('taxonomy_term', 'language', 'card');
foreach (array_keys($card->getComponents()) as $name) {
  $card->removeComponent($name);
}
$card->setStatus(TRUE)->save();

// ---------------------------------------------------------------------------
// Permissions: everyone may view published covers; editors manage content.

$available = array_keys(\Drupal::service('user.permissions')->getPermissions());
$grant = function ($role_id, array $permissions) use ($available) {
  if ($role = Role::load($role_id)) {
    foreach (array_intersect($permissions, $available) as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }
};
$grant('anonymous', ['view media']);
$grant('authenticated', ['view media']);
$editor = ['access media overview', 'create cover_image media', 'edit any cover_image media', 'delete any cover_image media', 'view own unpublished media', 'view any unpublished media'];
foreach (['novel', 'edition', 'short_story', 'magazine_issue', 'anthology'] as $bundle) {
  array_push($editor, "create $bundle content", "edit any $bundle content", "edit own $bundle content", "delete own $bundle content", "view $bundle revisions", "revert $bundle revisions");
}
foreach (['people', 'series', 'magazine', 'publisher_series', 'image_source', 'publisher', 'language', 'format'] as $vid) {
  array_push($editor, "create terms in $vid", "edit terms in $vid");
}
$grant('content_editor', $editor);

echo "Layouts and permissions done.\n";
