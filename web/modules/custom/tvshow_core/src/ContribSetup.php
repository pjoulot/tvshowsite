<?php

namespace Drupal\tvshow_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enables and configures the contributed modules the sites rely on.
 *
 * Runs at the end of the profile install and can be run again at any time
 * (scripts/configure_contrib.php). Each module is set up on its own: a
 * failure is reported and the next one still runs. When a module is absent,
 * tvshow_core's small built-in equivalent stays in charge.
 *
 * | Module          | Takes over from                         |
 * | pathauto        | AliasGenerator (URL patterns)           |
 * | redirect        | LegacyRedirectSubscriber                |
 * | metatag         | meta tags added in tvshow_core_node_view |
 * | simple_sitemap  | PageController::sitemap                 |
 * | search_api      | ContentRepository::search (entity query) |
 * | webform         | core contact form                       |
 * | rabbit_hole     | redirect of partner term pages          |
 */
class ContribSetup {

  const MODULES = [
    'token', 'pathauto', 'redirect', 'metatag', 'metatag_open_graph', 'simple_sitemap',
    'search_api', 'search_api_db', 'webform', 'webform_ui', 'rabbit_hole', 'rh_taxonomy',
    'admin_toolbar', 'admin_toolbar_tools',
  ];

  const SEARCH_INDEX = 'tvshow_content';

  /**
   * One line per step: what was done, or why it was skipped or failed.
   */
  public array $report = [];

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected ModuleInstallerInterface $moduleInstaller,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  public function run(): array {
    $this->report = [];
    $this->moduleList->reset();
    $available = array_keys($this->moduleList->getList());
    $wanted = array_values(array_intersect(self::MODULES, $available));
    $missing = array_diff(['pathauto', 'redirect', 'metatag', 'simple_sitemap', 'search_api', 'webform', 'rabbit_hole', 'admin_toolbar'], $wanted);
    if ($missing) {
      $this->report[] = 'Not in the codebase (built-in equivalents stay active): ' . implode(', ', $missing);
    }
    foreach ($wanted as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        $this->step("enable $module", fn() => $this->moduleInstaller->install([$module]));
      }
    }
    // Services and entity types of the modules just installed.
    $this->moduleHandler = \Drupal::moduleHandler();
    $this->entityTypeManager = \Drupal::entityTypeManager();
    $this->configFactory = \Drupal::configFactory();

    foreach (['pathauto', 'metatag', 'simple_sitemap', 'search_api', 'webform', 'rabbit_hole'] as $module) {
      if ($this->moduleHandler->moduleExists($module)) {
        $this->step("configure $module", [$this, 'configure' . str_replace('_', '', ucwords($module, '_'))]);
      }
    }
    return $this->report;
  }

  protected function step(string $label, callable $callback): void {
    try {
      $result = $callback();
      $this->report[] = "OK    $label" . (is_string($result) ? " — $result" : '');
    }
    catch (\Throwable $e) {
      $message = "FAILED $label: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
      $this->report[] = $message;
      $this->logger->error($message);
    }
  }

  /**
   * URL patterns, the same addresses AliasGenerator produces.
   */
  protected function configurePathauto(): string {
    $wiki = AliasGenerator::wikiPath();
    $patterns = [
      // id => [label, entity type, bundle, pattern, weight].
      'tv_article' => ['Actualité', 'node', 'article', '/actualites/[node:title]', 0],
      'tv_editorial' => ['Fiche du wiki', 'node', 'editorial', "/$wiki/[node:field_category:entity:name]/[node:title]", 0],
      'tv_product' => ['Produit dérivé', 'node', 'product', '/[node:field_product_type:entity:parents:join-path]/[node:field_product_type:entity:name]/[node:title]', 0],
      'tv_episode' => ['Épisode', 'node', 'episode', '/[node:field_season:entity:field_serie:entity:field_abreviation]/[node:field_season:entity:name]/[node:title]', 0],
      'tv_people' => ['Personnalité', 'node', 'people', '/personnalites/[node:title]', 0],
      'tv_page' => ['Page', 'node', 'page', '/[node:title]', 0],
      'tv_serie' => ['Série', 'taxonomy_term', 'serie', '/[term:field_abreviation]', 0],
      'tv_saison' => ['Saison', 'taxonomy_term', 'saison', '/[term:field_serie:entity:field_abreviation]/[term:name]', 0],
      'tv_category' => ['Rubrique du wiki', 'taxonomy_term', 'category', "/$wiki/[term:name]", 0],
      'tv_product_type' => ['Type de produit', 'taxonomy_term', 'product_type', '/[term:parents:join-path]/[term:name]', 0],
      'tv_article_category' => ['Rubrique d’actualité', 'taxonomy_term', 'article_category', '/actualites/rubrique/[term:name]', 0],
      'tv_tags' => ['Tag', 'taxonomy_term', 'tags', '/tags/[term:name]', 0],
      'tv_job' => ['Métier', 'taxonomy_term', 'job', '/personnalites/metier/[term:name]', 0],
    ];
    $storage = $this->entityTypeManager->getStorage('pathauto_pattern');
    $created = 0;
    foreach ($patterns as $id => [$label, $entity_type, $bundle, $pattern, $weight]) {
      if ($storage->load($id)) {
        continue;
      }
      $entity = $storage->create([
        'id' => $id,
        'label' => $label,
        'type' => 'canonical_entities:' . $entity_type,
        'pattern' => $pattern,
        'weight' => $weight,
      ]);
      $entity->addSelectionCondition([
        'id' => 'entity_bundle:' . $entity_type,
        'bundles' => [$bundle => $bundle],
        'negate' => FALSE,
        'context_mapping' => [$entity_type => $entity_type],
      ]);
      $entity->save();
      $created++;
    }
    // Keep accents out of addresses and old aliases reachable.
    $settings = $this->configFactory->getEditable('pathauto.settings');
    // Every word of a title is kept ("once-upon-a-time"), like AliasGenerator.
    $settings->set('transliterate', TRUE)->set('reduce_ascii', TRUE)->set('ignore_words', '')->set('enabled_entity_types', ['node', 'taxonomy_term']);
    if ($this->moduleHandler->moduleExists('redirect')) {
      // 2 = create a new alias and redirect the old one (needs Redirect).
      $settings->set('update_action', 2);
    }
    $settings->save();
    return "$created patterns created";
  }

  /**
   * Puts the wiki prefix of tvshow_core.settings into the Pathauto patterns.
   *
   * The import of a content pack sets that prefix after the install wrote
   * the patterns.
   */
  public function refreshWikiPatterns(): void {
    $wiki = AliasGenerator::wikiPath();
    $storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
    foreach (['tv_editorial' => "/$wiki/[node:field_category:entity:name]/[node:title]", 'tv_category' => "/$wiki/[term:name]"] as $id => $pattern) {
      if ($entity = $storage->load($id)) {
        $entity->setPattern($pattern)->save();
      }
    }
  }

  /**
   * Description and Open Graph tags for content and term pages.
   */
  protected function configureMetatag(): string {
    $storage = $this->entityTypeManager->getStorage('metatag_defaults');
    $og = $this->moduleHandler->moduleExists('metatag_open_graph');
    $sets = [
      'node' => [
        'title' => '[node:title] | [site:name]',
        'description' => '[node:field_meta_description]',
        'canonical_url' => '[node:url]',
      ] + ($og ? [
        'og_title' => '[node:title]',
        'og_description' => '[node:summary]',
        'og_url' => '[node:url]',
        'og_site_name' => '[site:name]',
        'og_type' => 'article',
        'og_image' => '[node:field_image:entity:url]',
      ] : []),
      'taxonomy_term' => [
        'title' => '[term:name] | [site:name]',
        'description' => '[term:description]',
        'canonical_url' => '[term:url]',
      ] + ($og ? ['og_title' => '[term:name]', 'og_url' => '[term:url]', 'og_site_name' => '[site:name]'] : []),
    ];
    $done = 0;
    foreach ($sets as $id => $tags) {
      $defaults = $storage->load($id) ?: $storage->create(['id' => $id, 'label' => $id === 'node' ? 'Content' : 'Taxonomy term']);
      $defaults->set('tags', $tags + ($defaults->get('tags') ?: []));
      $defaults->save();
      $done++;
    }
    return "$done default sets";
  }

  /**
   * Content and the main term pages go in the XML sitemap.
   */
  protected function configureSimpleSitemap(): string {
    $manager = \Drupal::service('simple_sitemap.entity_manager');
    $manager->enableEntityType('node');
    $manager->enableEntityType('taxonomy_term');
    foreach (['article', 'editorial', 'episode', 'people', 'page', 'product'] as $bundle) {
      $manager->setBundleSettings('node', $bundle, ['index' => TRUE, 'priority' => $bundle === 'article' ? '0.7' : '0.5']);
    }
    foreach (['serie', 'saison', 'category', 'article_category', 'product_type'] as $bundle) {
      $manager->setBundleSettings('taxonomy_term', $bundle, ['index' => TRUE]);
    }
    return 'node and term bundles indexed';
  }

  /**
   * A database search server and one index of all content.
   */
  protected function configureSearchApi(): string {
    if (!$this->moduleHandler->moduleExists('search_api_db')) {
      return 'search_api_db is missing: no server created';
    }
    $servers = $this->entityTypeManager->getStorage('search_api_server');
    if (!$servers->load('tvshow_database')) {
      $servers->create([
        'id' => 'tvshow_database',
        'name' => 'Base de données',
        'backend' => 'search_api_db',
        'backend_config' => ['database' => 'default:default', 'min_chars' => 2, 'matching' => 'words'],
      ])->save();
    }
    $indexes = $this->entityTypeManager->getStorage('search_api_index');
    if ($indexes->load(self::SEARCH_INDEX)) {
      return 'index already exists; ' . $this->createSearchView();
    }
    $index = $indexes->create([
      'id' => self::SEARCH_INDEX,
      'name' => 'Contenus du site',
      'server' => 'tvshow_database',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'options' => ['index_directly' => TRUE, 'cron_limit' => 100],
    ]);
    $fields_helper = \Drupal::service('search_api.fields_helper');
    $fields = [
      'title' => ['Titre', 'title', 'text', 8.0],
      'body' => ['Texte', 'body', 'text', 1.0],
      'field_synopsis' => ['Synopsis', 'field_synopsis', 'text', 1.0],
      'field_original_title' => ['Titre original', 'field_original_title', 'text', 5.0],
      'type' => ['Type de contenu', 'type', 'string', NULL],
      'created' => ['Date', 'created', 'date', NULL],
    ];
    foreach ($fields as $id => [$label, $path, $type, $boost]) {
      $info = ['label' => $label, 'datasource_id' => 'entity:node', 'property_path' => $path, 'type' => $type];
      if ($boost !== NULL) {
        $info['boost'] = $boost;
      }
      $index->addField($fields_helper->createField($index, $id, $info));
    }
    $plugin_helper = \Drupal::service('search_api.plugin_helper');
    foreach (['entity_status', 'html_filter', 'ignorecase', 'transliteration', 'tokenizer'] as $processor) {
      $index->addProcessor($plugin_helper->createProcessorPlugin($index, $processor));
    }
    $index->save();
    return 'server and index created; ' . $this->createSearchView();
  }

  /**
   * The /recherche results as a view on the Search API index.
   *
   * Until it exists and the index is filled, the page uses the tvshow_search
   * view, which searches the database directly.
   */
  protected function createSearchView(): string {
    $storage = $this->entityTypeManager->getStorage('view');
    if ($storage->load('tvshow_search_api')) {
      return 'search view already exists';
    }
    $table = 'search_api_index_' . self::SEARCH_INDEX;
    $card_modes = ['article' => 'line', 'editorial' => 'line', 'episode' => 'line', 'people' => 'line', 'page' => 'line', 'product' => 'line'];
    $options = [
      'title' => 'Recherche',
      'access' => ['type' => 'none', 'options' => []],
      'cache' => ['type' => 'none', 'options' => []],
      'query' => ['type' => 'search_api_query', 'options' => ['bypass_access' => FALSE, 'skip_access' => FALSE]],
      'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Rechercher', 'reset_button' => FALSE]],
      'exposed_block' => TRUE,
      'pager' => ['type' => 'full', 'options' => ['items_per_page' => 15, 'offset' => 0, 'id' => 0, 'quantity' => 5]],
      'style' => ['type' => 'default', 'options' => ['row_class' => '', 'default_row_class' => FALSE, 'uses_fields' => FALSE]],
      'row' => ['type' => 'search_api', 'options' => ['view_modes' => ['entity:node' => $card_modes]]],
      'filters' => [
        'search_api_fulltext' => [
          'id' => 'search_api_fulltext', 'table' => $table, 'field' => 'search_api_fulltext', 'plugin_id' => 'search_api_fulltext',
          'operator' => 'and', 'value' => '', 'group' => 1, 'exposed' => TRUE,
          'expose' => ['operator_id' => 'search_api_fulltext_op', 'label' => 'Rechercher', 'identifier' => 's', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE],
          'parse_mode' => 'terms', 'min_length' => 2, 'fields' => [],
        ],
      ],
      'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
      'sorts' => [
        'search_api_relevance' => ['id' => 'search_api_relevance', 'table' => $table, 'field' => 'search_api_relevance', 'plugin_id' => 'search_api', 'order' => 'DESC'],
        'created' => ['id' => 'created', 'table' => $table, 'field' => 'created', 'plugin_id' => 'search_api', 'order' => 'DESC'],
      ],
      'header' => ['result' => ['id' => 'result', 'table' => 'views', 'field' => 'result', 'plugin_id' => 'result', 'empty' => FALSE, 'content' => '<p class="tv-resultcount">Résultats trouvés : @total</p>']],
      'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => '<p class="tv-empty">Rien ne correspond à cette recherche. Essayez avec moins de mots ou un autre terme.</p>']],
      'css_class' => 'tv-newslist tv-newslist--wide',
      'use_ajax' => FALSE,
      'display_extenders' => [],
    ];
    $storage->create([
      'id' => 'tvshow_search_api',
      'label' => 'Recherche (Search API)',
      'description' => 'Résultats de /recherche, sur l’index Search API « Contenus du site ».',
      'module' => 'views',
      'tag' => 'tvshow',
      'base_table' => $table,
      'base_field' => 'search_api_id',
      'langcode' => 'fr',
      'status' => TRUE,
      'display' => [
        'default' => ['id' => 'default', 'display_title' => 'Défaut', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $options],
        'results' => ['id' => 'results', 'display_title' => 'Résultats', 'display_plugin' => 'embed', 'position' => 1, 'display_options' => ['display_extenders' => []]],
      ],
    ])->save();
    return 'search view created';
  }

  /**
   * French contact form at /contact, e-mailed to the site address.
   */
  protected function configureWebform(): string {
    $storage = $this->entityTypeManager->getStorage('webform');
    if ($storage->load('tvshow_contact')) {
      return 'contact form already exists';
    }
    $elements = <<<YAML
name:
  '#type': textfield
  '#title': 'Votre nom'
  '#required': true
email:
  '#type': email
  '#title': 'Votre adresse e-mail'
  '#required': true
subject:
  '#type': textfield
  '#title': Sujet
  '#required': true
message:
  '#type': textarea
  '#title': Message
  '#required': true
actions:
  '#type': webform_actions
  '#title': 'Bouton d’envoi'
  '#submit__label': 'Envoyer le message'
YAML;
    $webform = $storage->create([
      'id' => 'tvshow_contact',
      'title' => 'Contact',
      'description' => 'Formulaire de contact du site.',
      'elements' => $elements,
      'settings' => [
        'page_submit_path' => '/contact',
        'page_confirm_path' => '/contact/merci',
        'confirmation_type' => 'page',
        'confirmation_title' => 'Message envoyé',
        'confirmation_message' => 'Merci, votre message a bien été envoyé.',
      ],
      'handlers' => [
        'email' => [
          'id' => 'email',
          'label' => 'E-mail au site',
          'handler_id' => 'email',
          'status' => TRUE,
          'weight' => 0,
          'settings' => [
            'to_mail' => '_default',
            'from_mail' => '_default',
            'from_name' => '[webform_submission:values:name:raw]',
            'reply_to' => '[webform_submission:values:email:raw]',
            'subject' => '[webform_submission:values:subject:raw]',
            'body' => '[webform_submission:values:message:value]',
          ],
        ],
      ],
    ]);
    $webform->save();
    // The core contact form is no longer needed.
    if ($this->moduleHandler->moduleExists('contact')) {
      $this->moduleInstaller->uninstall(['contact']);
    }
    return 'contact form created at /contact';
  }

  /**
   * Partner terms have no page of their own.
   */
  protected function configureRabbitHole(): string {
    $manager = \Drupal::service('rabbit_hole.behavior_settings_manager');
    $manager->saveBehaviorSettings([
      'action' => 'page_redirect',
      'allow_override' => 0,
      'redirect' => '/partenaires',
      'redirect_code' => 301,
      'redirect_fallback_action' => 'page_not_found',
    ], 'taxonomy_vocabulary', 'partenaires');
    return 'partner terms redirect to /partenaires';
  }

}
