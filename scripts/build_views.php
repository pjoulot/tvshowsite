<?php

/**
 * @file
 * Developer tool: (re)builds the site's listing Views and exports them.
 *
 * Run on a scratch site that has tvshow_core enabled:
 *   php scripts/drupal-run.php scripts/build_views.php
 * Every listing of the site is a display of one of these views; the result is
 * written to web/modules/custom/tvshow_core/config/install. On a real site,
 * edit the views in the UI and export them with the rest of the config.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\views\Entity\View;

// View modes the listing rows are rendered in.
$modes = [
  'node.card' => 'Carte',
  'node.row' => 'Ligne compacte',
  'node.line' => 'Ligne avec résumé',
  'node.feature' => 'À la une',
  'node.role' => 'Rôle (casting)',
  'taxonomy_term.card' => 'Carte',
];
foreach ($modes as $id => $label) {
  if (!EntityViewMode::load($id)) {
    EntityViewMode::create(['id' => $id, 'label' => $label, 'targetEntityType' => explode('.', $id)[0], 'cache' => TRUE])->save();
  }
}

/* ---- Handler helpers ---------------------------------------------------- */

$node = fn(string $field, string $plugin, array $more = []) => ['id' => $field, 'table' => 'node_field_data', 'field' => $field, 'entity_type' => 'node', 'entity_field' => $field, 'plugin_id' => $plugin] + $more;
$term = fn(string $field, string $plugin, array $more = []) => ['id' => $field, 'table' => 'taxonomy_term_field_data', 'field' => $field, 'entity_type' => 'taxonomy_term', 'entity_field' => $field, 'plugin_id' => $plugin] + $more;
// A column of a field table: $column is "value" or "target_id".
$field = fn(string $entity, string $name, string $column, string $plugin, array $more = []) => ['id' => "{$name}_{$column}", 'table' => "{$entity}__{$name}", 'field' => "{$name}_{$column}", 'plugin_id' => $plugin] + $more;

$published = ['status' => $node('status', 'boolean', ['value' => '1', 'group' => 1])];
$bundle = fn(string ...$bundles) => ['type' => $node('type', 'bundle', ['value' => array_combine($bundles, $bundles), 'group' => 1])];
$vocabulary = fn(string $vid) => [
  'status' => $term('status', 'boolean', ['value' => '1', 'group' => 1]),
  'vid' => $term('vid', 'bundle', ['value' => [$vid => $vid], 'group' => 1]),
];
$newest = ['created' => $node('created', 'date', ['order' => 'DESC']), 'nid' => $node('nid', 'standard', ['order' => 'DESC'])];
$by_title = ['title' => $node('title', 'standard', ['order' => 'ASC'])];
$by_weight = ['weight' => $term('weight', 'standard', ['order' => 'ASC']), 'name' => $term('name', 'standard', ['order' => 'ASC'])];
// Contextual filter on a reference field; the embedding page passes the id.
$reference = fn(string $entity, string $name) => ["{$name}_target_id" => $field($entity, $name, 'target_id', 'numeric', ['default_action' => 'empty', 'break_phrase' => FALSE, 'not' => FALSE])];

// Optional contextual filter: "all" (or nothing) shows everything.
$optional = fn(string $entity, string $name) => ["{$name}_target_id" => $field($entity, $name, 'target_id', 'numeric', ['default_action' => 'ignore', 'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'Tout'], 'break_phrase' => FALSE, 'not' => FALSE])];
// First letter of the title ("a"…"z"), or "all".
$letter = ['title' => $node('title', 'string', ['default_action' => 'ignore', 'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'Tout'], 'glossary' => TRUE, 'limit' => 1, 'case' => 'lower', 'path_case' => 'lower', 'transform_dash' => FALSE, 'break_phrase' => FALSE])];

$rows = fn(string $entity, string $mode) => ['type' => "entity:$entity", 'options' => ['view_mode' => $mode]];
$plain = ['type' => 'default', 'options' => ['row_class' => '', 'default_row_class' => FALSE, 'uses_fields' => FALSE]];
$full = fn(int $per_page) => ['type' => 'full', 'options' => ['items_per_page' => $per_page, 'offset' => 0, 'id' => 0, 'quantity' => 5, 'tags' => ['previous' => '← Précédent', 'next' => 'Suivant →', 'first' => '«', 'last' => '»']]];
$some = fn(int $items) => ['type' => 'some', 'options' => ['items_per_page' => $items, 'offset' => 0]];
$all = ['type' => 'none', 'options' => ['items_per_page' => 0, 'offset' => 0]];
$empty = fn(string $html) => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => '<p class="tv-empty">' . $html . '</p>']];
$header = fn(array $options) => ['tvshow_page_header' => ['id' => 'tvshow_page_header', 'table' => 'views', 'field' => 'tvshow_page_header', 'plugin_id' => 'tvshow_page_header', 'empty' => TRUE] + $options];

/**
 * A display whose options override the view's defaults.
 */
function tv_display(string $id, string $plugin, string $title, array $options, int $position): array {
  $overridable = ['title', 'css_class', 'filters', 'filter_groups', 'sorts', 'arguments', 'relationships', 'fields', 'pager', 'style', 'row', 'empty', 'header', 'footer', 'query', 'cache', 'exposed_block'];
  $defaults = [];
  foreach ($overridable as $key) {
    if (array_key_exists($key, $options)) {
      $defaults[$key] = FALSE;
    }
  }
  if (isset($defaults['filters'])) {
    $defaults['filter_groups'] = FALSE;
    $options += ['filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']]];
  }
  if ($defaults) {
    $options['defaults'] = $defaults;
  }
  $options += ['display_extenders' => []];
  return ['id' => $id, 'display_title' => $title, 'display_plugin' => $plugin, 'position' => $position, 'display_options' => $options];
}

/**
 * Creates or replaces one view.
 */
function tv_view(string $id, string $label, string $description, string $base_table, array $default, array $displays): void {
  if ($existing = View::load($id)) {
    $existing->delete();
  }
  $default += [
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'cache' => ['type' => 'tag', 'options' => []],
    'query' => ['type' => 'views_query', 'options' => ['distinct' => FALSE]],
    'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Rechercher', 'reset_button' => FALSE]],
    'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
    'use_ajax' => FALSE,
    'display_extenders' => [],
  ];
  $display = ['default' => ['id' => 'default', 'display_title' => 'Défaut', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default]];
  $position = 1;
  foreach ($displays as $display_id => [$plugin, $title, $options]) {
    $display[$display_id] = tv_display($display_id, $plugin, $title, $options, $position++);
  }
  View::create([
    'id' => $id,
    'label' => $label,
    'description' => $description,
    'module' => 'views',
    'tag' => 'tvshow',
    'base_table' => $base_table,
    'base_field' => $base_table === 'node_field_data' ? 'nid' : 'tid',
    'langcode' => 'fr',
    'status' => TRUE,
    'display' => $display,
  ])->save();
  print "  $id: " . implode(', ', array_keys($displays)) . "\n";
}

/* ---- Actualités --------------------------------------------------------- */

tv_view('tvshow_news', 'Actualités', 'Liste des actualités, flux RSS, blocs de la page d’accueil et rubriques.', 'node_field_data', [
  'title' => 'Actualités',
  'filters' => $published + $bundle('article'),
  'sorts' => $newest,
  'row' => $rows('node', 'card'),
  'style' => $plain,
  'pager' => $full(12),
  'css_class' => 'tv-newslist tv-newslist--wide',
  'empty' => $empty('Aucune actualité pour le moment.'),
], [
  'page' => ['page', 'Page /actualites', [
    'path' => 'actualites',
    'header' => $header(['kicker' => '', 'feed_link' => TRUE, 'vocabulary' => 'article_category', 'all_label' => 'Toutes', 'layout' => 'news']),
  ]],
  'feed' => ['feed', 'Flux RSS', [
    'path' => 'actualites/rss.xml',
    'pager' => $some(20),
    'style' => ['type' => 'rss', 'options' => ['description' => 'Les dernières actualités du site.', 'grouping' => [], 'uses_fields' => FALSE]],
    'row' => ['type' => 'node_rss', 'options' => ['view_mode' => 'rss']],
    'displays' => ['page' => 'page', 'default' => '0'],
    'sitename_title' => TRUE,
  ]],
  'category' => ['embed', 'Rubrique', [
    'arguments' => $reference('node', 'field_article_category'),
    'empty' => $empty('Aucune actualité dans cette rubrique pour le moment.'),
  ]],
  'featured' => ['embed', 'Accueil : à la une', [
    'filters' => $published + $bundle('article') + [
      // "À la une" is shown large: only pictures big enough for it.
      'field_image_width' => $field('node', 'field_image', 'width', 'numeric', ['operator' => '>=', 'value' => ['value' => '300', 'min' => '', 'max' => ''], 'group' => 1]),
    ],
    'sorts' => ['promote' => $node('promote', 'standard', ['order' => 'DESC'])] + $newest,
    'row' => $rows('node', 'feature'),
    'pager' => $some(2),
    'css_class' => 'tv-features',
    'empty' => [],
  ]],
  'latest' => ['embed', 'Accueil : dernières actualités', [
    // The front page passes the featured articles so they are not repeated.
    'arguments' => ['nid' => $node('nid', 'node_nid', ['default_action' => 'ignore', 'break_phrase' => TRUE, 'not' => TRUE])],
    'pager' => $some(6),
    'css_class' => 'tv-newslist',
  ]],
  'lead' => ['embed', 'Accueil : la plus récente, en grand', [
    'row' => $rows('node', 'feature'),
    'pager' => $some(1),
    'css_class' => 'tv-lead-news',
    'empty' => [],
  ]],
  'more' => ['embed', 'Accueil : les suivantes', [
    'pager' => ['type' => 'some', 'options' => ['items_per_page' => 9, 'offset' => 1]],
    'css_class' => 'tv-newslist',
    'empty' => [],
  ]],
  'short' => ['embed', 'Trois dernières (page 404)', [
    'pager' => $some(3),
    'empty' => [],
  ]],
]);

tv_view('tvshow_tagged', 'Contenus par tag', 'Tous les contenus portant un tag.', 'node_field_data', [
  'title' => 'Tag',
  'filters' => $published,
  'sorts' => $newest,
  'arguments' => $reference('node', 'field_tags'),
  'row' => $rows('node', 'line'),
  'style' => $plain,
  'pager' => $full(12),
  'css_class' => 'tv-newslist tv-newslist--wide',
  'empty' => $empty('Aucun contenu avec ce tag pour le moment.'),
], [
  'tag' => ['embed', 'Tag', []],
]);

/* ---- Wiki --------------------------------------------------------------- */

tv_view('tvshow_wiki', 'Wiki', 'Fiches du wiki : dernières fiches, rubriques et personnages du casting.', 'node_field_data', [
  'title' => 'Wiki',
  'filters' => $published + $bundle('editorial'),
  'sorts' => ['changed' => $node('changed', 'date', ['order' => 'DESC'])],
  'row' => $rows('node', 'card'),
  'style' => $plain,
  'pager' => $some(12),
  'css_class' => 'tv-grid tv-grid--tiles',
  'empty' => $empty('Le wiki est encore vide.'),
], [
  'latest' => ['embed', 'Dernières fiches', [
    'sorts' => ['changed' => $node('changed', 'date', ['order' => 'DESC']), 'random' => ['id' => 'random', 'table' => 'views', 'field' => 'random', 'plugin_id' => 'random']],
  ]],
  'front' => ['embed', 'Accueil : dernières fiches', [
    // Fiches imported together share their dates: mix the rubrics.
    'sorts' => ['changed' => $node('changed', 'date', ['order' => 'DESC']), 'random' => ['id' => 'random', 'table' => 'views', 'field' => 'random', 'plugin_id' => 'random']],
    'filters' => $published + $bundle('editorial') + ['field_image_target_id' => $field('node', 'field_image', 'target_id', 'numeric', ['operator' => 'not empty', 'group' => 1])],
    'row' => $rows('node', 'row'),
    'pager' => $some(6),
    'css_class' => 'tv-wikilist',
  ]],
  'category' => ['embed', 'Rubrique', [
    // Rubric, then optionally a series and a first letter.
    'arguments' => $reference('node', 'field_category') + $optional('node', 'field_serie') + $letter,
    'sorts' => $by_title,
    'pager' => $all,
    'empty' => $empty('Aucune fiche dans cette rubrique pour le moment.'),
  ]],
  'cast' => ['embed', 'Casting : personnages', [
    'filters' => $published + $bundle('editorial') + ['field_actor_target_id' => $field('node', 'field_actor', 'target_id', 'numeric', ['operator' => 'not empty', 'group' => 1])],
    'sorts' => ['created' => $node('created', 'date', ['order' => 'ASC'])] + $by_title,
    'row' => $rows('node', 'role'),
    'pager' => $all,
    'css_class' => 'tv-grid tv-grid--posters',
    'query' => ['type' => 'views_query', 'options' => ['distinct' => TRUE]],
    'empty' => $empty('Le casting n’a pas encore été renseigné.'),
  ]],
]);

/* ---- Personnalités ------------------------------------------------------ */

tv_view('tvshow_people', 'Personnalités', 'Acteurs et équipe : liste complète, par métier, et équipe hors casting.', 'node_field_data', [
  'title' => 'Personnalités',
  'filters' => $published + $bundle('people'),
  'sorts' => $by_title,
  'row' => $rows('node', 'card'),
  'style' => $plain,
  'pager' => $full(48),
  'css_class' => 'tv-grid tv-grid--tiles',
  'empty' => $empty('Aucune personnalité pour le moment.'),
], [
  'page' => ['page', 'Page /personnalites', [
    'path' => 'personnalites',
    'header' => $header(['kicker' => '', 'feed_link' => FALSE, 'vocabulary' => '', 'all_label' => '', 'layout' => 'people']),
  ]],
  'job' => ['embed', 'Métier', [
    // Job, then optionally a series.
    'arguments' => $reference('node', 'field_job') + $optional('node', 'field_series'),
  ]],
  'crew' => ['embed', 'Casting : le reste de l’équipe', [
    // People no wiki entry names as an actor.
    'relationships' => ['reverse__node__field_actor' => ['id' => 'reverse__node__field_actor', 'table' => 'node_field_data', 'field' => 'reverse__node__field_actor', 'entity_type' => 'node', 'plugin_id' => 'entity_reverse', 'admin_label' => 'Fiche du personnage joué', 'required' => FALSE]],
    'filters' => $published + $bundle('people') + ['field_actor_target_id' => $field('node', 'field_actor', 'target_id', 'numeric', ['relationship' => 'reverse__node__field_actor', 'operator' => 'empty', 'group' => 1])],
    'pager' => $some(12),
    'empty' => [],
  ]],
]);

/* ---- Produits dérivés --------------------------------------------------- */

tv_view('tvshow_products', 'Produits dérivés', 'Produits d’un type (et de ses sous-types), éventuellement d’une série.', 'node_field_data', [
  'title' => 'Produits dérivés',
  'filters' => $published + $bundle('product'),
  // Creation order: the order of the old site (saison 1, 2… 10).
  'sorts' => ['nid' => $node('nid', 'standard', ['order' => 'ASC'])],
  'arguments' => [
    'term_node_tid_depth' => ['id' => 'term_node_tid_depth', 'table' => 'node_field_data', 'field' => 'term_node_tid_depth', 'plugin_id' => 'taxonomy_index_tid_depth', 'default_action' => 'ignore', 'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'Tout'], 'depth' => 2, 'break_phrase' => FALSE, 'use_taxonomy_term_path' => FALSE],
  ] + $optional('node', 'field_series'),
  'row' => $rows('node', 'card'),
  'style' => $plain,
  'pager' => $full(24),
  'css_class' => 'tv-grid tv-grid--products',
  'query' => ['type' => 'views_query', 'options' => ['distinct' => TRUE]],
  'empty' => $empty('Aucun produit dans ce rayon pour le moment.'),
], [
  'type' => ['embed', 'Type de produit', []],
]);

/* ---- Épisodes ----------------------------------------------------------- */

tv_view('tvshow_episodes', 'Épisodes', 'Épisodes d’une saison, dans l’ordre de diffusion.', 'node_field_data', [
  'title' => 'Épisodes',
  'filters' => $published + $bundle('episode'),
  'sorts' => ['field_episode_value' => $field('node', 'field_episode', 'value', 'standard', ['order' => 'ASC'])],
  'arguments' => $reference('node', 'field_season'),
  'row' => $rows('node', 'card'),
  'style' => $plain,
  'pager' => $all,
  'css_class' => 'tv-grid tv-grid--episodes',
  'empty' => $empty('Aucun épisode pour cette saison.'),
], [
  'season' => ['embed', 'Saison', []],
]);

/* ---- Termes : séries, saisons, rubriques, partenaires ------------------- */

tv_view('tvshow_terms', 'Séries, saisons, rubriques et partenaires', 'Listes de termes : séries, saisons d’une série, rubriques du wiki, partenaires.', 'taxonomy_term_field_data', [
  'title' => 'Séries',
  'filters' => $vocabulary('serie'),
  'sorts' => $by_weight,
  'row' => $rows('taxonomy_term', 'card'),
  'style' => $plain,
  'pager' => $all,
  'css_class' => 'tv-grid tv-grid--posters',
  'empty' => $empty('Aucune série n’a encore été ajoutée.'),
], [
  'series' => ['embed', 'Séries', []],
  'seasons' => ['embed', 'Saisons d’une série', [
    'filters' => $vocabulary('saison'),
    'arguments' => $reference('taxonomy_term', 'field_serie'),
    'sorts' => ['field_season_number_value' => $field('taxonomy_term', 'field_season_number', 'value', 'standard', ['order' => 'ASC'])],
    'empty' => $empty('Aucune saison n’a encore été ajoutée.'),
  ]],
  'wiki_categories' => ['embed', 'Rubriques du wiki', [
    'filters' => $vocabulary('category'),
    // Rubrics without any entry print nothing (see tvshow_core.module).
    'css_class' => 'tv-categories',
    'empty' => [],
  ]],
  'partners' => ['embed', 'Partenaires', [
    'filters' => $vocabulary('partenaires'),
    'css_class' => 'tv-grid tv-grid--partners',
    'empty' => $empty('Aucun partenaire pour le moment. <a href="/contact">Contactez-nous</a> pour proposer un échange de liens.'),
  ]],
]);

/* ---- Recherche (repli sans Search API) ---------------------------------- */

tv_view('tvshow_search', 'Recherche (base de données)', 'Recherche dans les titres et les textes. Sert de repli quand Search API n’est pas installé.', 'node_field_data', [
  'title' => 'Recherche',
  'filters' => $published + ['combine' => [
    'id' => 'combine', 'table' => 'views', 'field' => 'combine', 'plugin_id' => 'combine', 'operator' => 'allwords', 'value' => '', 'group' => 1,
    'exposed' => TRUE,
    'expose' => ['operator_id' => 'combine_op', 'label' => 'Rechercher', 'identifier' => 's', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE],
    'fields' => ['title' => 'title', 'body' => 'body', 'field_synopsis' => 'field_synopsis'],
  ]],
  'fields' => [
    'title' => $node('title', 'field'),
    'body' => ['id' => 'body', 'table' => 'node__body', 'field' => 'body', 'plugin_id' => 'field', 'type' => 'text_default'],
    'field_synopsis' => ['id' => 'field_synopsis', 'table' => 'node__field_synopsis', 'field' => 'field_synopsis', 'plugin_id' => 'field', 'type' => 'text_default'],
  ],
  'sorts' => $newest,
  'row' => $rows('node', 'line'),
  'style' => $plain,
  'pager' => $full(15),
  'exposed_block' => TRUE,
  'css_class' => 'tv-newslist tv-newslist--wide',
  'header' => ['result' => ['id' => 'result', 'table' => 'views', 'field' => 'result', 'plugin_id' => 'result', 'empty' => FALSE, 'content' => '<p class="tv-resultcount">Résultats trouvés : @total</p>']],
  'empty' => $empty('Rien ne correspond à cette recherche. Essayez avec moins de mots ou un autre terme.'),
], [
  'results' => ['embed', 'Résultats', []],
]);

/* ---- Export ------------------------------------------------------------- */

$target = new FileStorage(\Drupal::root() . '/modules/custom/tvshow_core/config/install');
$active = \Drupal::service('config.storage');
$owned = fn(string $name) => str_starts_with($name, 'views.view.tvshow_') || in_array($name, array_map(fn($id) => "core.entity_view_mode.$id", array_keys($modes)));
foreach (array_filter($target->listAll(), $owned) as $name) {
  $target->delete($name);
}
$count = 0;
foreach (array_filter($active->listAll(), $owned) as $name) {
  if ($name === 'views.view.tvshow_search_api') {
    // Created by ContribSetup when Search API is there.
    continue;
  }
  $data = $active->read($name);
  unset($data['uuid'], $data['_core']);
  $target->write($name, $data);
  $count++;
}
print "Exported $count config objects.\n";
