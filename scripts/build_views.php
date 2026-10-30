<?php

/**
 * Builds the bibliography views and listing pages. Safe to re-run.
 *
 * Run with: drush php:script scripts/build_views.php
 */

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\image\Entity\ImageStyle;
use Drupal\views\Entity\View;

$repo = \Drupal::service('entity_display.repository');

// A thumbnail size for tables of editions and appearances: fitted within an
// 80 x 120 box, so ordinary covers come out alike and wide scans (both sides
// of an Ace Double, say) as large as the box allows.
$style = ImageStyle::load('cover_thumb') ?? ImageStyle::create(['name' => 'cover_thumb']);
$style->set('label', 'Cover thumbnail (within 80 x 120)');
foreach ($style->getEffects() as $effect) {
  $style->deleteImageEffect($effect);
}
$style->addImageEffect(['id' => 'image_scale', 'weight' => 0, 'data' => ['width' => 80, 'height' => 120, 'upscale' => FALSE]]);
$style->save();
$style->flush();
if (!EntityViewMode::load('media.cover_thumb')) {
  EntityViewMode::create(['id' => 'media.cover_thumb', 'label' => 'Cover thumbnail', 'targetEntityType' => 'media'])->save();
}
$display = $repo->getViewDisplay('media', 'cover_image', 'cover_thumb');
$display->setStatus(TRUE);
foreach (array_keys($display->getComponents()) as $name) {
  $display->removeComponent($name);
}
$display->setComponent('field_media_image', [
  'type' => 'image',
  'label' => 'hidden',
  'settings' => ['image_style' => 'cover_thumb', 'image_link' => '', 'image_loading' => ['attribute' => 'lazy']],
]);
$display->save();

// Handler helpers.
function sgv_field($name, $label, $type, array $settings = [], array $extra = []) {
  $table = in_array($name, ['title', 'type']) ? 'node_field_data' : "node__$name";
  $handler = [
    'id' => $name,
    'table' => $table,
    'field' => $name,
    'plugin_id' => 'field',
    'label' => $label,
    'element_label_colon' => FALSE,
    'type' => $type,
    'settings' => $settings,
  ];
  if ($table === 'node_field_data') {
    $handler += ['entity_type' => 'node', 'entity_field' => $name];
  }
  return $extra + $handler;
}

function sgv_cover() {
  // Without a cover, a blank of the same size keeps the rows lined up.
  return sgv_field('field_cover', '', 'entity_reference_entity_view', ['view_mode' => 'cover_thumb'], ['empty' => '<span class="cover-thumb-placeholder"></span>']);
}

// A cover thumbnail linking to its row's page (edition, issue, anthology…),
// with the hidden link field it needs before it. Spread into a field list.
function sgv_linked_cover() {
  return [
    'view_node' => ['id' => 'view_node', 'table' => 'node', 'field' => 'view_node', 'plugin_id' => 'entity_link', 'entity_type' => 'node', 'label' => '', 'output_url_as_text' => TRUE, 'absolute' => FALSE, 'text' => '', 'exclude' => TRUE],
    'field_cover' => sgv_cover() + ['alter' => ['make_link' => TRUE, 'path' => '{{ view_node }}']],
  ];
}

function sgv_label($name, $label) {
  return sgv_field($name, $label, 'entity_reference_label', ['link' => TRUE]);
}

// People columns: placeholders like "Uncredited" show unlinked.
function sgv_people($name, $label) {
  return sgv_field($name, $label, 'sectorgeneral_people_label', ['link' => TRUE]);
}

function sgv_plain($name, $label) {
  return sgv_field($name, $label, 'string', ['link_to_entity' => FALSE]);
}

function sgv_year($name, $label) {
  return sgv_field($name, $label, 'number_integer', ['thousand_separator' => '', 'prefix_suffix' => FALSE]);
}

function sgv_base_filters(array $bundles) {
  return [
    'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
    'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'plugin_id' => 'bundle', 'value' => array_combine($bundles, $bundles), 'entity_type' => 'node', 'entity_field' => 'type', 'group' => 1],
  ];
}

function sgv_sort($field, $order = 'ASC') {
  $table = in_array($field, ['title', 'type']) ? 'node_field_data' : 'node__' . preg_replace('/_value$/', '', $field);
  return ['id' => $field, 'table' => $table, 'field' => $field, 'plugin_id' => 'standard', 'order' => $order];
}

function sgv_argument($field) {
  return [
    'id' => "{$field}_target_id",
    'table' => "node__$field",
    'field' => "{$field}_target_id",
    'plugin_id' => 'numeric',
    'default_action' => 'empty',
    'specify_validation' => TRUE,
    'validate' => ['type' => 'entity:node', 'fail' => 'empty'],
    'validate_options' => ['access' => TRUE, 'operation' => 'view', 'multiple' => 0],
  ];
}

function sgv_table(array $fields, array $sortable = [], array $grouping = []) {
  $columns = $info = [];
  foreach ($fields as $name) {
    $columns[$name] = $name;
    $info[$name] = ['sortable' => in_array($name, $sortable), 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => TRUE, 'responsive' => ''];
  }
  return [
    'type' => 'table',
    'options' => [
      'grouping' => $grouping,
      'row_class' => '',
      'default_row_class' => TRUE,
      'columns' => $columns,
      'default' => '-1',
      'info' => $info,
      'override' => TRUE,
      'sticky' => FALSE,
      'summary' => '',
      'empty_table' => FALSE,
      'caption' => '',
      'description' => '',
    ],
  ];
}

function sgv_eva($id, $title, $bundle, $position, array $overrides = [], $entity_type = 'node') {
  if (isset($overrides['filters'])) {
    $overrides['filter_groups'] = ['operator' => 'AND', 'groups' => [1 => 'AND']];
  }
  // The heading is a header area rather than the view title, because Views
  // hides headers when there are no results but always prints the title.
  $overrides['header'] = [
    'area_text_custom' => [
      'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom',
      'empty' => FALSE, 'tokenize' => FALSE, 'content' => '<h2>' . htmlspecialchars($title) . '</h2>',
    ],
  ];
  return [
    'id' => $id,
    'display_title' => $title,
    'display_plugin' => 'entity_view',
    'position' => $position,
    'display_options' => $overrides + [
      'display_extenders' => [],
      'entity_type' => $entity_type,
      'bundles' => [$bundle],
      'argument_mode' => 'id',
      'show_title' => FALSE,
      'title' => $title,
      'defaults' => ['title' => FALSE] + array_fill_keys(array_keys(array_diff_key($overrides, ['display_extenders' => 1])), FALSE),
    ],
  ];
}

function sgv_save($id, $label, $description, array $default_options, array $displays, $base_table = 'node_field_data', $base_field = 'nid') {
  if ($view = View::load($id)) {
    $view->delete();
  }
  $default_options += [
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'cache' => ['type' => 'tag', 'options' => []],
    'query' => ['type' => 'views_query', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => []],
    'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
    'row' => ['type' => 'fields', 'options' => []],
    'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
    'display_extenders' => [],
  ];
  $view = View::create([
    'id' => $id,
    'label' => $label,
    'description' => $description,
    'module' => 'views',
    'base_table' => $base_table,
    'base_field' => $base_field,
    'display' => [
      'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default_options],
    ] + $displays,
  ]);
  $view->save();
  echo "view $id\n";
}

// ---------------------------------------------------------------------------
// Editions of a book: English editions, and translations grouped by language.

$language_rel = ['field_language' => ['id' => 'field_language', 'table' => 'node__field_language', 'field' => 'field_language', 'plugin_id' => 'standard', 'required' => TRUE]];
$language_filter = fn($operator) => sgv_base_filters(['edition']) + [
  'name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'field_language', 'plugin_id' => 'string', 'operator' => $operator, 'value' => 'English', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'group' => 1],
];
// A book's own English editions: the cover and the publisher link to the
// edition; its title ("Star Surgeon, Ballantine, 1963") would only repeat the
// book, publisher and date.
$to_edition = ['alter' => ['make_link' => TRUE, 'path' => '{{ view_node }}']];
$edition_fields = [
  'view_node' => ['id' => 'view_node', 'table' => 'node', 'field' => 'view_node', 'plugin_id' => 'entity_link', 'entity_type' => 'node', 'label' => '', 'output_url_as_text' => TRUE, 'absolute' => FALSE, 'text' => '', 'exclude' => TRUE],
  'field_cover' => sgv_cover() + $to_edition,
  // An edition with no publisher still needs a link.
  'field_publisher' => sgv_field('field_publisher', 'Publisher', 'entity_reference_label', ['link' => FALSE], $to_edition + ['empty' => 'Details']),
  'field_in_anthology' => sgv_label('field_in_anthology', 'Published in'),
  'field_date_display' => sgv_plain('field_date_display', 'Date'),
  'field_format' => sgv_label('field_format', 'Format'),
  'field_printing' => sgv_plain('field_printing', 'Printing'),
  'field_isbn' => sgv_plain('field_isbn', 'ISBN'),
];
// Omnibus editions also containing this book: here the title says which.
$omnibus_fields = [
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Edition', 'string', ['link_to_entity' => TRUE]),
] + array_diff_key($edition_fields, ['view_node' => 1, 'field_cover' => 1, 'field_in_anthology' => 1, 'field_printing' => 1]);
$omnibus_fields['field_publisher'] = sgv_label('field_publisher', 'Publisher');
// An edition's first "edition of" is the book it belongs to: Star Surgeon for
// its own editions, Beginning Operations for the omnibus containing it.
$first_book = fn($operator) => ['delta' => ['id' => 'delta', 'table' => 'node__field_novel', 'field' => 'delta', 'plugin_id' => 'numeric', 'operator' => $operator, 'value' => ['value' => '0', 'min' => '', 'max' => ''], 'group' => 1]];
$translation_fields = [
  'field_language' => sgv_field('field_language', '', 'entity_reference_label', ['link' => FALSE], ['exclude' => TRUE]),
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Title', 'sectorgeneral_edition_title', ['link_to_entity' => TRUE]),
  'field_translator' => sgv_people('field_translator', 'Translator'),
  'field_publisher' => sgv_label('field_publisher', 'Publisher'),
  'field_date_display' => sgv_plain('field_date_display', 'Date'),
];
sgv_save('book_editions', 'Book editions', 'English editions and translations, shown on each book page.', [
  'relationships' => $language_rel,
  'fields' => $edition_fields,
  'arguments' => ['field_novel_target_id' => sgv_argument('field_novel')],
  'sorts' => ['field_sort_date_value' => sgv_sort('field_sort_date_value'), 'field_year_value' => sgv_sort('field_year_value'), 'title' => sgv_sort('title')],
  'style' => sgv_table(array_keys($edition_fields)),
  'filters' => $language_filter('='),
], [
  'english' => sgv_eva('english', 'English editions', 'novel', 1, ['filters' => $language_filter('=') + $first_book('=')]),
  'omnibus' => sgv_eva('omnibus', 'In omnibus editions', 'novel', 2, [
    'filters' => $language_filter('=') + $first_book('>'),
    'fields' => $omnibus_fields,
    'style' => sgv_table(array_keys($omnibus_fields)),
  ]),
  'translations' => sgv_eva('translations', 'Translations', 'novel', 3, [
    'filters' => $language_filter('!='),
    'fields' => $translation_fields,
    'sorts' => [
      'name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'field_language', 'plugin_id' => 'standard', 'order' => 'ASC'],
      'field_sort_date_value' => sgv_sort('field_sort_date_value'),
      'field_year_value' => sgv_sort('field_year_value'),
    ],
    'style' => sgv_table(array_keys($translation_fields), [], [['field' => 'field_language', 'rendered' => TRUE, 'rendered_strip' => FALSE]]),
  ]),
]);

// ---------------------------------------------------------------------------
// Where a story appeared, in three lists: magazines, anthologies by other
// editors, and James White's own books. Empty lists are not shown.

$appearance_fields = [
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Title', 'string', ['link_to_entity' => TRUE]),
  'field_published_as' => sgv_field('field_published_as', 'Published as', 'string', ['link_to_entity' => FALSE], ['delta_limit' => 0, 'group_column' => 'value', 'multi_type' => 'separator', 'separator' => '; ']),
  'field_language' => sgv_field('field_language', 'Language', 'entity_reference_label', ['link' => FALSE]),
  'field_editor' => sgv_people('field_editor', 'Editor'),
  'field_book_type' => sgv_field('field_book_type', 'Type', 'list_default'),
  'field_date_display' => sgv_plain('field_date_display', 'Date'),
  'field_first_published_year' => sgv_year('field_first_published_year', 'Year'),
];
$by_year = ['field_first_published_year_value' => sgv_sort('field_first_published_year_value'), 'title' => sgv_sort('title')];
sgv_save('story_appearances', 'Story appearances', 'Magazines, anthologies and books that include a story, shown on each story page.', [
  'fields' => $appearance_fields,
  'arguments' => ['field_contents_target_id' => sgv_argument('field_contents')],
  'filters' => sgv_base_filters(['magazine_issue']),
  'sorts' => ['field_sort_date_value' => sgv_sort('field_sort_date_value'), 'field_year_value' => sgv_sort('field_year_value'), 'title' => sgv_sort('title')],
  'style' => sgv_table(array_keys($appearance_fields)),
], [
  'magazines' => sgv_eva('magazines', 'In magazines', 'short_story', 1),
  'anthologies' => sgv_eva('anthologies', 'In anthologies', 'short_story', 2, ['filters' => sgv_base_filters(['anthology']), 'sorts' => $by_year]),
  'books' => sgv_eva('books', "In James White's books", 'short_story', 3, ['filters' => sgv_base_filters(['novel']), 'sorts' => $by_year]),
  // A whole book reprinted in an anthology.
  'book_anthologies' => sgv_eva('book_anthologies', 'In anthologies', 'novel', 4, ['filters' => sgv_base_filters(['anthology']), 'sorts' => $by_year]),
  // A novel serialised in magazines, part by part.
  'book_magazines' => sgv_eva('book_magazines', 'Serialised in', 'novel', 5),
]);

// ---------------------------------------------------------------------------
// Listing pages.

$menu = fn($title, $weight) => ['type' => 'normal', 'title' => $title, 'description' => '', 'weight' => $weight, 'menu_name' => 'main', 'parent' => '', 'context' => '0', 'expanded' => FALSE, 'enabled' => TRUE];

sgv_save('books', 'Books', 'Cover grid of James White\'s books.', [
  'title' => 'Books by James White',
  // Each book is a book-card component (sectorgeneral_theme), with the
  // fields below mapped to its props and cover slot by Views Row SDC.
  'fields' => [
    'title' => sgv_plain('title', 'Title'),
    'view_node' => ['id' => 'view_node', 'table' => 'node', 'field' => 'view_node', 'plugin_id' => 'entity_link', 'entity_type' => 'node', 'label' => 'URL', 'output_url_as_text' => TRUE, 'absolute' => FALSE, 'text' => ''],
    'field_cover' => sgv_field('field_cover', 'Cover', 'entity_reference_entity_view', ['view_mode' => 'cover_card']),
    'field_first_published_year' => sgv_year('field_first_published_year', 'Year'),
    'field_book_type' => sgv_field('field_book_type', 'Type', 'list_default'),
    'field_series' => sgv_field('field_series', 'Series', 'entity_reference_label', ['link' => FALSE]),
    'field_series_number' => sgv_year('field_series_number', 'Number in series'),
  ],
  'row' => ['type' => 'sdc_component', 'options' => [
    'component_id' => 'sectorgeneral_theme:book-card',
    'props' => array_map(fn($field) => ['field' => $field, 'static' => '', 'type' => 'string'], [
      'title' => 'title',
      'url' => 'view_node',
      'year' => 'field_first_published_year',
      'type' => 'field_book_type',
      'series' => 'field_series',
      'series_number' => 'field_series_number',
    ]),
    'slots' => ['cover' => ['field' => 'field_cover']],
  ]],
  // Five across on a wide screen, two on a phone.
  'style' => ['type' => 'grid_responsive', 'options' => ['grouping' => [], 'columns' => 5, 'cell_min_width' => 140, 'grid_gutter' => 24, 'alignment' => 'horizontal']],
  'header' => ['result' => ['id' => 'result', 'table' => 'views', 'field' => 'result', 'plugin_id' => 'result', 'empty' => FALSE, 'content' => '@total books']],
  'filters' => sgv_base_filters(['novel']) + [
    'field_book_type_value' => [
      'id' => 'field_book_type_value', 'table' => 'node__field_book_type', 'field' => 'field_book_type_value', 'plugin_id' => 'list_field',
      'operator' => 'or', 'value' => [], 'group' => 1, 'exposed' => TRUE,
      'expose' => ['operator_id' => 'field_book_type_value_op', 'label' => 'Type', 'identifier' => 'type', 'multiple' => FALSE, 'remember' => FALSE, 'use_operator' => FALSE, 'operator' => 'field_book_type_value_op', 'reduce' => FALSE, 'required' => FALSE],
    ],
    'field_series_target_id' => [
      'id' => 'field_series_target_id', 'table' => 'node__field_series', 'field' => 'field_series_target_id', 'plugin_id' => 'taxonomy_index_tid',
      'operator' => 'or', 'value' => [], 'group' => 1, 'exposed' => TRUE, 'type' => 'select', 'vid' => 'series', 'limit' => TRUE, 'hierarchy' => FALSE,
      'expose' => ['operator_id' => 'field_series_target_id_op', 'label' => 'Series', 'identifier' => 'series', 'multiple' => FALSE, 'remember' => FALSE, 'use_operator' => FALSE, 'operator' => 'field_series_target_id_op', 'reduce' => FALSE, 'required' => FALSE],
    ],
  ],
  'sorts' => ['field_first_published_year_value' => sgv_sort('field_first_published_year_value'), 'title' => sgv_sort('title')],
  'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => 'No books match these filters.']],
], [
  'page' => ['id' => 'page', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => 'books', 'menu' => $menu('Books', 1), 'display_extenders' => []]],
]);

$story_fields = [
  'title' => sgv_field('title', 'Title', 'string', ['link_to_entity' => TRUE]),
  'field_first_published_year' => sgv_year('field_first_published_year', 'First published'),
  'field_words' => sgv_field('field_words', 'Words', 'number_integer', ['thousand_separator' => ',', 'prefix_suffix' => FALSE]),
  'field_series' => sgv_label('field_series', 'Series'),
];
sgv_save('short_stories', 'Short stories', 'Sortable list of James White\'s short fiction.', [
  'title' => 'Short stories by James White',
  'fields' => $story_fields,
  'filters' => sgv_base_filters(['short_story']),
  'sorts' => ['field_first_published_year_value' => sgv_sort('field_first_published_year_value'), 'title' => sgv_sort('title')],
  'style' => sgv_table(array_keys($story_fields), array_keys($story_fields)),
  'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => 'No short stories have been added yet.']],
], [
  'page' => ['id' => 'page', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => 'short-stories', 'menu' => $menu('Short stories', 2), 'display_extenders' => []]],
]);

// Places stories appeared, as cover grids under Short stories.
$child = fn($title, $weight) => ['parent' => 'views_view:views.short_stories.page'] + $menu($title, $weight);
$grid = ['type' => 'grid_responsive', 'options' => ['grouping' => [], 'columns' => 4, 'cell_min_width' => 180, 'grid_gutter' => 16, 'alignment' => 'horizontal']];
sgv_save('anthologies', 'Anthologies', 'Anthologies by other editors that include James White stories.', [
  'title' => 'Anthologies with James White stories',
  'row' => ['type' => 'entity:node', 'options' => ['view_mode' => 'teaser']],
  'style' => $grid,
  'filters' => sgv_base_filters(['anthology']),
  'sorts' => $by_year,
], [
  'page' => ['id' => 'page', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => 'anthologies', 'menu' => $child('Anthologies', 2), 'display_extenders' => []]],
]);
sgv_save('magazines', 'Magazines', 'Magazine issues containing James White stories.', [
  'title' => 'Magazine appearances',
  'row' => ['type' => 'entity:node', 'options' => ['view_mode' => 'teaser']],
  'style' => $grid,
  'filters' => sgv_base_filters(['magazine_issue']) + [
    'field_magazine_target_id' => [
      'id' => 'field_magazine_target_id', 'table' => 'node__field_magazine', 'field' => 'field_magazine_target_id', 'plugin_id' => 'taxonomy_index_tid',
      'operator' => 'or', 'value' => [], 'group' => 1, 'exposed' => TRUE, 'type' => 'select', 'vid' => 'magazine', 'limit' => TRUE, 'hierarchy' => FALSE,
      'expose' => ['operator_id' => 'field_magazine_target_id_op', 'label' => 'Magazine', 'identifier' => 'magazine', 'multiple' => FALSE, 'remember' => FALSE, 'use_operator' => FALSE, 'operator' => 'field_magazine_target_id_op', 'reduce' => FALSE, 'required' => FALSE],
    ],
  ],
  'sorts' => ['field_sort_date_value' => sgv_sort('field_sort_date_value'), 'field_year_value' => sgv_sort('field_year_value'), 'title' => sgv_sort('title')],
], [
  'page' => ['id' => 'page', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => 'magazines', 'menu' => $child('Magazines', 1), 'display_extenders' => []]],
]);

// ---------------------------------------------------------------------------
// People by role: a tab per role listing people with their number of credits,
// and on each person's page their work grouped by role.

$roles = [
  'cover_artists' => ['Cover artists', 'field_cover_artist', 'Cover art', 'Covers'],
  'interior_artists' => ['Interior artists', 'field_interior_artist', 'Interior art', 'Credits'],
  'editors' => ['Editors', 'field_editor', 'Edited', 'Credits'],
  'translators' => ['Translators', 'field_translator', 'Translated', 'Translations'],
  'introductions' => ['Introductions', 'field_introduction_by', 'Introductions', 'Credits'],
  'narrators' => ['Narrators', 'field_narrator', 'Narrated', 'Credits'],
  'anthology_contributors' => ['Fellow contributors', 'field_other_contributors', 'Appeared alongside James White in', 'Shared appearances'],
];
$credits_rel = fn($field) => ['credits' => [
  'id' => 'credits', 'table' => 'taxonomy_term_field_data', 'field' => "reverse__node__$field", 'plugin_id' => 'entity_reverse',
  'entity_type' => 'taxonomy_term', 'required' => TRUE,
]];
$count = fn(array $extra = []) => $extra + [
  'id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'relationship' => 'credits', 'group_type' => 'count_distinct',
  'entity_type' => 'node', 'entity_field' => 'nid',
];
$people_fields = fn($count_label) => [
  'name' => [
    'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
    'plugin_id' => 'field', 'label' => 'Name', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
    'group_type' => 'group', 'group_column' => 'value', 'group_columns' => [], 'element_label_colon' => FALSE,
  ],
  'nid' => $count(['plugin_id' => 'field', 'label' => $count_label, 'type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE], 'element_label_colon' => FALSE]),
];
$people_filters = [
  'vid' => ['id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'plugin_id' => 'bundle', 'value' => ['people' => 'people'], 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'group' => 1],
  'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'relationship' => 'credits', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
  // Placeholders such as "Uncredited" are not people.
  'field_not_a_person_value' => ['id' => 'field_not_a_person_value', 'table' => 'taxonomy_term__field_not_a_person', 'field' => 'field_not_a_person_value', 'plugin_id' => 'boolean', 'value' => '0', 'accept_null' => TRUE, 'group' => 1],
  'name' => [
    'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'string', 'operator' => 'contains', 'value' => '',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'group' => 1, 'exposed' => TRUE,
    'expose' => ['operator_id' => 'name_op', 'label' => 'Name contains', 'identifier' => 'name', 'use_operator' => FALSE, 'operator' => 'name_op', 'remember' => FALSE, 'required' => FALSE, 'multiple' => FALSE],
  ],
];
$people_style = sgv_table(['name', 'nid'], ['name', 'nid']);
$people_style['options']['default'] = 'nid';
$people_style['options']['info']['nid']['default_sort_order'] = 'desc';
$people_displays = [];
$position = 1;
foreach ($roles as $id => [$title, $field, $credit_heading, $count_label]) {
  $filters = $people_filters;
  if ($id === 'anthology_contributors') {
    // HAVING COUNT(DISTINCT nid) >= N: people in at least N of the same
    // anthologies or magazine issues.
    $filters['nid'] = $count([
      'plugin_id' => 'numeric', 'operator' => '>=', 'value' => ['min' => '', 'max' => '', 'value' => ''], 'group' => 1, 'exposed' => TRUE,
      'expose' => ['operator_id' => 'nid_op', 'label' => 'Minimum shared appearances', 'identifier' => 'min', 'use_operator' => FALSE, 'operator' => 'nid_op', 'remember' => FALSE, 'required' => FALSE, 'multiple' => FALSE, 'placeholder' => '2'],
    ]);
  }
  $menu_options = $position === 1
    ? ['type' => 'default tab', 'title' => $title, 'weight' => 0, 'menu_name' => 'main', 'context' => '0', 'expanded' => FALSE, 'enabled' => TRUE, 'description' => '', 'parent' => '']
    : ['type' => 'tab', 'title' => $title, 'weight' => $position, 'menu_name' => 'main', 'context' => '0', 'expanded' => FALSE, 'enabled' => TRUE, 'description' => '', 'parent' => ''];
  $page = [
    'display_extenders' => [],
    'path' => 'people/' . ($id === 'anthology_contributors' ? 'fellow-contributors' : str_replace('_', '-', $id)),
    'title' => $title,
    'menu' => $menu_options,
    'relationships' => $credits_rel($field),
    'fields' => $people_fields($count_label),
    'filters' => $filters,
    'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
    'defaults' => ['title' => FALSE, 'relationships' => FALSE, 'fields' => FALSE, 'filters' => FALSE, 'filter_groups' => FALSE],
  ];
  if ($position === 1) {
    // The default tab also gives /people itself, a main menu item.
    $page['tab_options'] = ['type' => 'normal', 'title' => 'People', 'description' => 'People who worked on James White\'s books and magazines', 'weight' => 5, 'menu_name' => 'main'];
  }
  $people_displays[$id] = ['id' => $id, 'display_title' => $title, 'display_plugin' => 'page', 'position' => $position++, 'display_options' => $page];
}
sgv_save('people_by_role', 'People by role', 'Browse cover artists, editors, translators and other contributors.', [
  'title' => 'People',
  'group_by' => TRUE,
  'relationships' => $credits_rel('field_cover_artist'),
  'fields' => $people_fields('Credits'),
  'filters' => $people_filters,
  'sorts' => [
    'nid' => $count(['plugin_id' => 'standard', 'order' => 'DESC']),
    'name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard', 'order' => 'ASC', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name'],
  ],
  'style' => $people_style,
  'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50, 'offset' => 0, 'id' => 0, 'quantity' => 9, 'tags' => ['next' => 'Next ›', 'previous' => '‹ Previous', 'first' => '« First', 'last' => 'Last »'], 'expose' => ['items_per_page' => FALSE, 'items_per_page_label' => 'Items per page', 'items_per_page_options' => '25, 50, 100', 'items_per_page_options_all' => FALSE, 'items_per_page_options_all_label' => '- All -', 'offset' => FALSE, 'offset_label' => 'Offset'], 'total_pages' => NULL]],
  'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => 'No one matches.']],
], $people_displays, 'taxonomy_term_field_data', 'tid');

// A person's page: their work in each role.
$person_argument = fn($field) => ["{$field}_target_id" => [
  'id' => "{$field}_target_id", 'table' => "node__$field", 'field' => "{$field}_target_id", 'plugin_id' => 'numeric',
  'default_action' => 'empty', 'specify_validation' => TRUE,
  'validate' => ['type' => 'entity:taxonomy_term', 'fail' => 'empty'],
  'validate_options' => ['access' => TRUE, 'operation' => 'view', 'multiple' => 0, 'bundles' => ['people' => 'people']],
]];
$credit_fields = [
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Title', 'string', ['link_to_entity' => TRUE]),
  'type' => sgv_field('type', 'Type', 'entity_reference_label', ['link' => FALSE]),
  'field_year' => sgv_year('field_year', 'Year'),
  'field_first_published_year' => sgv_year('field_first_published_year', 'Year'),
];
$person_displays = [];
$position = 1;
foreach ($roles as $id => [$title, $field, $credit_heading]) {
  $person_displays[$id] = sgv_eva($id, $credit_heading, 'people', $position++, ['arguments' => $person_argument($field)], 'taxonomy_term');
}
sgv_save('person_credits', 'Person credits', 'Work by role, shown on each person\'s page.', [
  'fields' => $credit_fields,
  'arguments' => $person_argument('field_cover_artist'),
  'filters' => ['status' => sgv_base_filters([])['status']],
  'sorts' => [
    'field_year_value' => sgv_sort('field_year_value'),
    'field_first_published_year_value' => sgv_sort('field_first_published_year_value'),
    'field_sort_date_value' => sgv_sort('field_sort_date_value'),
    'title' => sgv_sort('title'),
  ],
  'style' => sgv_table(array_keys($credit_fields)),
], $person_displays);

// The person page shows their description, links, then each role's list.
$display = $repo->getViewDisplay('taxonomy_term', 'people', 'default');
$weight = 10;
foreach (array_keys($roles) as $id) {
  $display->setComponent("person_credits_$id", ['weight' => $weight++]);
}
$display->save();

// ---------------------------------------------------------------------------
// Publications in each language, on the language's page, and an index of
// the languages his work has been translated into.

$language_argument = ['field_language_target_id' => [
  'id' => 'field_language_target_id', 'table' => 'node__field_language', 'field' => 'field_language_target_id', 'plugin_id' => 'numeric',
  'default_action' => 'empty', 'specify_validation' => TRUE,
  'validate' => ['type' => 'entity:taxonomy_term', 'fail' => 'empty'],
  'validate_options' => ['access' => TRUE, 'operation' => 'view', 'multiple' => 0, 'bundles' => ['language' => 'language']],
]];
$by_date = ['field_sort_date_value' => sgv_sort('field_sort_date_value'), 'title' => sgv_sort('title')];
$language_edition_fields = [
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Title', 'sectorgeneral_edition_title', ['link_to_entity' => TRUE]),
  'field_novel' => sgv_label('field_novel', 'Translation of'),
  'field_translator' => sgv_people('field_translator', 'Translator'),
  'field_publisher' => sgv_label('field_publisher', 'Publisher'),
  'field_date_display' => sgv_plain('field_date_display', 'Date'),
];
$language_issue_fields = [
  ...sgv_linked_cover(),
  'title' => sgv_field('title', 'Issue', 'string', ['link_to_entity' => TRUE]),
  'field_published_as' => sgv_field('field_published_as', 'Published as', 'string', ['link_to_entity' => FALSE], ['delta_limit' => 0, 'group_column' => 'value', 'multi_type' => 'separator', 'separator' => '; ']),
  'field_contents' => sgv_label('field_contents', 'Work'),
  'field_date_display' => sgv_plain('field_date_display', 'Date'),
];
sgv_save('language_publications', 'Publications by language', 'Editions and magazine issues in a language, shown on its page.', [
  'fields' => $language_edition_fields,
  'arguments' => $language_argument,
  'filters' => sgv_base_filters(['edition']),
  'sorts' => $by_date,
  'style' => sgv_table(array_keys($language_edition_fields)),
], [
  'editions' => sgv_eva('editions', 'Editions', 'language', 1, [], 'taxonomy_term'),
  'issues' => sgv_eva('issues', 'Magazine issues', 'language', 2, [
    'fields' => $language_issue_fields,
    'filters' => sgv_base_filters(['magazine_issue']),
    'style' => sgv_table(array_keys($language_issue_fields)),
  ], 'taxonomy_term'),
]);
$display = $repo->getViewDisplay('taxonomy_term', 'language', 'default');
$display->setComponent('language_publications_editions', ['weight' => 10]);
$display->setComponent('language_publications_issues', ['weight' => 11]);
$display->save();

// The Translations index: each language other than English that has
// publications, as a card (taxonomy-term--language--card.html.twig).
$translations_rel = ['publications' => [
  'id' => 'publications', 'table' => 'taxonomy_term_field_data', 'field' => 'reverse__node__field_language', 'plugin_id' => 'entity_reverse',
  'entity_type' => 'taxonomy_term', 'required' => TRUE,
]];
sgv_save('translations', 'Translations', 'Languages James White has been published in, as cards.', [
  'title' => 'James White in translation',
  // One row per language, however many publications it has. (DISTINCT
  // wouldn't do: Views adds the publication's ID to load it; aggregation
  // takes the lowest instead.)
  'group_by' => TRUE,
  'relationships' => $translations_rel,
  'row' => ['type' => 'entity:taxonomy_term', 'options' => ['view_mode' => 'card']],
  'style' => ['type' => 'grid_responsive', 'options' => ['grouping' => [], 'columns' => 4, 'cell_min_width' => 220, 'grid_gutter' => 24, 'alignment' => 'horizontal']],
  'filters' => [
    'vid' => ['id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'plugin_id' => 'bundle', 'value' => ['language' => 'language'], 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'group' => 1],
    'name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'string', 'operator' => '!=', 'value' => 'English', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'group' => 1],
    'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'relationship' => 'publications', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
  ],
  'sorts' => ['name' => ['id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard', 'order' => 'ASC', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name']],
  'header' => ['result' => ['id' => 'result', 'table' => 'views', 'field' => 'result', 'plugin_id' => 'result', 'empty' => FALSE, 'content' => '<p class="view-intro">James White\'s work has appeared in @total languages besides English. Choose one to see every edition and magazine appearance, by date.</p>']],
], [
  'page' => ['id' => 'page', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => [
    'path' => 'translations',
    'menu' => ['type' => 'normal', 'title' => 'Translations', 'description' => '', 'weight' => 1, 'menu_name' => 'main', 'parent' => 'views_view:views.books.page', 'context' => '0', 'expanded' => FALSE, 'enabled' => TRUE],
    'display_extenders' => [],
  ]],
], 'taxonomy_term_field_data', 'tid');

// ---------------------------------------------------------------------------
// Place the EVA attachments within the node displays.

\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$place = function ($bundle, array $after) use ($repo) {
  $display = $repo->getViewDisplay('node', $bundle, 'default');
  $components = $display->getComponents();
  uasort($components, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
  $weight = 0;
  foreach ($components as $name => $options) {
    $display->setComponent($name, ['weight' => $weight++] + $options);
    if (isset($after[$name])) {
      foreach ($after[$name] as $extra) {
        $display->setComponent($extra, ['weight' => $weight++]);
      }
    }
  }
  $display->save();
};
// Remove first so re-runs re-place them cleanly.
foreach (['novel' => ['book_editions_english', 'book_editions_omnibus', 'book_editions_translations', 'story_appearances_book_magazines', 'story_appearances_book_anthologies'], 'short_story' => ['story_appearances_appeared_in', 'story_appearances_magazines', 'story_appearances_anthologies', 'story_appearances_books']] as $bundle => $extras) {
  $display = $repo->getViewDisplay('node', $bundle, 'default');
  foreach ($extras as $extra) {
    $display->removeComponent($extra);
  }
  $display->save();
}
$place('novel', ['field_contained_books' => ['book_editions_english', 'book_editions_omnibus', 'book_editions_translations', 'story_appearances_book_magazines', 'story_appearances_book_anthologies']]);
$place('short_story', ['field_synopsis_credit' => ['story_appearances_magazines', 'story_appearances_anthologies', 'story_appearances_books']]);
// EVA fields should not leak into teasers.
foreach (['novel' => ['book_editions_english', 'book_editions_omnibus', 'book_editions_translations', 'story_appearances_book_magazines', 'story_appearances_book_anthologies'], 'short_story' => ['story_appearances_appeared_in', 'story_appearances_magazines', 'story_appearances_anthologies', 'story_appearances_books']] as $bundle => $extras) {
  $display = $repo->getViewDisplay('node', $bundle, 'teaser');
  foreach ($extras as $extra) {
    $display->removeComponent($extra);
  }
  $display->save();
}

echo "Views placed.\n";

// Teasers sit in grids and lists, where field labels are clutter.
foreach (['novel', 'edition', 'short_story', 'magazine_issue', 'anthology'] as $bundle) {
  $display = $repo->getViewDisplay('node', $bundle, 'teaser');
  foreach ($display->getComponents() as $name => $options) {
    if (isset($options['label'])) {
      $display->setComponent($name, ['label' => 'hidden'] + $options);
    }
  }
  $display->save();
}

// Views doesn't create a menu link for a default tab's parent page, so add
// the People link to the main menu directly.
$links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
if (!$links->loadByProperties(['menu_name' => 'main', 'link.uri' => 'internal:/people'])) {
  $links->create(['title' => 'People', 'link' => ['uri' => 'internal:/people'], 'menu_name' => 'main', 'weight' => 5])->save();
  echo "menu link People\n";
}

// The Fellow contributors tab used to be Anthology contributors.
$redirects = \Drupal::entityTypeManager()->getStorage('redirect');
if (!$redirects->loadByProperties(['redirect_source__path' => 'people/anthology-contributors'])) {
  $redirects->create(['redirect_source' => 'people/anthology-contributors', 'redirect_redirect' => 'internal:/people/fellow-contributors', 'status_code' => 301, 'language' => 'und'])->save();
}
