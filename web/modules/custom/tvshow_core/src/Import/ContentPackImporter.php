<?php

namespace Drupal\tvshow_core\Import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Url;
use Drupal\tvshow_core\AliasGenerator;
use Drupal\tvshow_core\Presenter;

/**
 * Loads a site content pack (JSON) into Drupal.
 *
 * A pack describes one site: series, seasons, episodes, people, wiki entries,
 * products, pages, news, partners, menus and the addresses of the old site.
 * Pictures are read from a source folder (the old site's files).
 * Running the import again updates what it created instead of duplicating it.
 *
 * Two pack shapes are read:
 * - one show ("series" is an object, wiki entries are "characters"), as
 *   written for stargateuniverse.fr;
 * - several shows ("series" is a list; seasons, episodes, wiki entries and
 *   products name their series by abbreviation), as written for
 *   stargate-pegasus.com.
 * The keys are described in README.md ("Content packs").
 */
class ContentPackImporter {

  /**
   * Counters reported at the end.
   */
  public array $stats = [];

  protected array $pack = [];

  protected string $source = '';

  /**
   * Folder of the pack file; its images/ folder holds hand-picked pictures.
   */
  protected string $packDir = '';

  protected $map;

  protected $files;

  /**
   * Old address => entity, filled while importing.
   */
  protected array $legacy = [];

  /**
   * Series terms by abbreviation.
   */
  protected array $series = [];

  /**
   * Season terms by "abbreviation:number".
   */
  protected array $seasons = [];

  /**
   * Whether the pack covers several shows.
   */
  protected bool $multi = FALSE;

  /**
   * Entities whose text still holds legacy: links, as [type, id].
   */
  protected array $relink = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected KeyValueFactoryInterface $keyValue,
    protected ConfigFactoryInterface $configFactory,
    protected AliasGenerator $aliasGenerator,
    protected Presenter $presenter,
  ) {
    $this->map = $keyValue->get('tvshow_core.import');
    $this->files = $keyValue->get('tvshow_core.import_files');
  }

  public function import(string $pack_file, string $source_dir, ?callable $log = NULL): array {
    $log ??= fn(string $message) => NULL;
    $this->pack = json_decode(file_get_contents($pack_file), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->source = rtrim($source_dir, '/');
    $this->packDir = dirname($pack_file);
    $this->stats = [];
    $this->legacy = [];
    $this->relink = [];
    $this->multi = array_is_list($this->pack['series'] ?? []);

    $this->site();
    $this->seriesTerms();
    $this->seasonTerms();
    $log('Series and seasons ready.');
    $people = $this->people();
    $log('People: ' . count($people));
    if (!empty($this->pack['characters'])) {
      $this->characters(reset($this->series), $people);
      $log('Characters ready.');
    }
    $this->episodes($people);
    $log('Episodes ready.');
    $this->wiki($people);
    $this->products();
    $this->pages();
    $this->articles($log);
    $this->partners();
    $this->linkEpisodes();
    $this->menus();
    $this->aliases();
    $this->legacyRedirects();
    $this->relinkAll();
    $log('Links between pages rewritten.');
    $this->indexContent();
    return $this->stats;
  }

  /**
   * Storage key for the key-value tables.
   *
   * Those keys are ASCII-only and at most 128 characters on MySQL/MariaDB,
   * while pack keys hold accented names and long file paths.
   */
  public static function key(string $key): string {
    return hash('sha256', $key);
  }

  protected function count(string $what): void {
    $this->stats[$what] = ($this->stats[$what] ?? 0) + 1;
  }

  protected function site(): void {
    $site = $this->pack['site'] ?? [];
    if (!empty($site['name'])) {
      $this->configFactory->getEditable('system.site')->set('name', $site['name'])->set('slogan', $site['slogan'] ?? '')->save();
    }
    \Drupal::service('extension.list.theme')->reset();
    if (!empty($site['theme']) && \Drupal::service('extension.list.theme')->exists($site['theme'])) {
      \Drupal::service('theme_installer')->install([$site['theme']]);
      $this->configFactory->getEditable('system.theme')->set('default', $site['theme'])->save();
    }
    $settings = $this->configFactory->getEditable('tvshow_core.settings');
    $map = ['slogan' => 'tagline', 'footer_text' => 'footer_text', 'description' => 'description', 'wiki_path' => 'wiki_path', 'wiki_label' => 'wiki_label'];
    foreach ($map as $key => $setting) {
      if (isset($site[$key]) && $site[$key] !== '') {
        $settings->set($setting, $site[$key]);
      }
    }
    foreach ($site['social'] ?? [] as $network => $url) {
      $settings->set('social.' . $network, $url);
    }
    $settings->save();
    if (!empty($site['wiki_path']) && \Drupal::moduleHandler()->moduleExists('pathauto')) {
      // Pathauto patterns were written at install time, with the default prefix.
      \Drupal::service('tvshow_core.contrib_setup')->refreshWikiPatterns();
    }
  }

  /**
   * Pictures of a folder next to the pack, split into promotional and
   * behind-the-scenes ("bts" subfolder), in file-name order.
   */
  protected function localSets(string $folder): array {
    $sets = ['promo' => [], 'backstage' => []];
    foreach (['promo' => '', 'backstage' => '/bts'] as $set => $sub) {
      $files = glob("$this->packDir/$folder$sub/*.{jpg,jpeg,png,JPG,JPEG,PNG}", GLOB_BRACE) ?: [];
      natcasesort($files);
      foreach ($files as $file) {
        $sets[$set][] = 'pack:' . substr($file, strlen($this->packDir) + 1);
      }
    }
    return $sets;
  }

  /**
   * A picture dropped next to the pack as images/<slug>.jpg|png|webp.
   *
   * It wins over whatever the archive has for that content.
   */
  protected function override(string $slug): ?string {
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
      if (is_file("$this->packDir/images/$slug.$extension")) {
        return "pack:images/$slug.$extension";
      }
    }
    return NULL;
  }

  /**
   * Copies one file from the source folder and returns its file entity id.
   */
  protected function file(?string $relative): ?int {
    if (!$relative) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $known = $this->files->get(self::key($relative));
    if ($known && $storage->load($known)) {
      return (int) $known;
    }
    $path = str_starts_with($relative, 'pack:') ? $this->packDir . '/' . substr($relative, 5) : $this->source . '/' . $relative;
    if (!is_file($path)) {
      $this->count('missing pictures');
      return NULL;
    }
    $clean = preg_replace('~^(wp-content/|pack:|Templates/)~', '', $relative);
    $clean = preg_replace('/[^A-Za-z0-9._\/-]+/', '-', $clean);
    $destination = 'public://import/' . $clean;
    $directory = dirname($destination);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $this->fileSystem->copy($path, $destination, FileExists::Replace);
    $file = $storage->create(['uri' => $uri, 'status' => 1, 'uid' => 1]);
    $file->save();
    $this->files->set(self::key($relative), $file->id());
    $this->count('pictures');
    return (int) $file->id();
  }

  protected function images(array $relatives, string $alt = ''): array {
    $items = [];
    foreach ($relatives as $relative) {
      if ($fid = $this->file($relative)) {
        $items[] = ['target_id' => $fid, 'alt' => $alt];
      }
    }
    return $items;
  }

  /**
   * One picture as an image field value.
   */
  protected function picture(?string $relative, string $alt): array {
    $fid = $this->file($relative);
    return $fid ? ['target_id' => $fid, 'alt' => mb_substr($alt, 0, 512)] : [];
  }

  /**
   * Replaces archive: references in body HTML with public file URLs.
   *
   * legacy: links (addresses of the old site) are rewritten at the end of
   * the import, once every page they may point to exists.
   */
  protected function html(?string $html): string {
    if (!$html) {
      return '';
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $html = preg_replace_callback('/\b(src|href)="archive:([^"]+)"/', function ($m) use ($storage) {
      $fid = $this->file(html_entity_decode($m[2]));
      $file = $fid ? $storage->load($fid) : NULL;
      return $file ? $m[1] . '="' . $this->fileUrlGenerator->generateString($file->getFileUri()) . '"' : $m[1] . '="#"';
    }, $html);
    // Drop images and links whose picture could not be found.
    $html = preg_replace('~<img[^>]*src="#"[^>]*/?>~', '', $html);
    // Inline pictures carry their size, so the page does not jump as they load.
    $html = preg_replace_callback('~<img\b[^>]*>~', function ($m) {
      $tag = $m[0];
      if (preg_match('~\ssrc="([^"]+)"~', $tag, $src) && !preg_match('~\swidth=~', $tag)) {
        $path = DRUPAL_ROOT . rawurldecode(parse_url($src[1], PHP_URL_PATH) ?: '');
        $size = is_file($path) ? @getimagesize($path) : FALSE;
        if ($size) {
          $tag = preg_replace('~\s*/?>$~', sprintf(' width="%d" height="%d" loading="lazy" decoding="async">', $size[0], $size[1]), $tag);
        }
      }
      return $tag;
    }, $html);
    $html = preg_replace('~<a href="#">(.*?)</a>~s', '$1', $html);
    return $html;
  }

  /**
   * A formatted text value, remembering the entity for the link rewrite.
   */
  protected function text(?string $html, string $format = 'full_html'): ?array {
    $html = $this->html($html);
    return $html === '' ? NULL : ['value' => $html, 'format' => $format];
  }

  /**
   * Saves an entity and queues it for the link rewrite when needed.
   */
  protected function store(ContentEntityInterface $entity): void {
    $entity->save();
    foreach (['body', 'field_synopsis', 'description'] as $field) {
      if ($entity->hasField($field) && str_contains((string) $entity->get($field)->value, 'legacy:')) {
        $this->relink[] = [$entity->getEntityTypeId(), $entity->id()];
        break;
      }
    }
  }

  /**
   * Loads the entity created earlier for a pack key, or creates it.
   */
  protected function entity(string $type, string $key, array $create) {
    $storage = $this->entityTypeManager->getStorage($type);
    $id = $this->map->get(self::key("$type:$key"));
    $entity = $id ? $storage->load($id) : NULL;
    if (!$entity) {
      $entity = $storage->create($create);
      $this->count('created ' . ($create['type'] ?? $create['vid'] ?? $type));
    }
    return $entity;
  }

  protected function remember(string $type, string $key, $entity): void {
    $this->map->set(self::key("$type:$key"), $entity->id());
  }

  /**
   * The entity created for a pack key, if any.
   */
  protected function known(string $type, string $key) {
    $id = $this->map->get(self::key("$type:$key"));
    return $id ? $this->entityTypeManager->getStorage($type)->load($id) : NULL;
  }

  protected function term(string $vocabulary, string $name, array $values = [], ?string $key = NULL) {
    $key = $vocabulary . ':' . ($key ?? mb_strtolower($name));
    $term = $this->entity('taxonomy_term', $key, ['vid' => $vocabulary]);
    $term->setName($name);
    foreach ($values as $field => $value) {
      $term->set($field, $value);
    }
    $this->store($term);
    $this->remember('taxonomy_term', $key, $term);
    return $term;
  }

  /**
   * Gives a node a fixed alias that Pathauto must not regenerate.
   */
  protected function presetAlias($node, string $alias): void {
    $path = ['alias' => $alias];
    if (\Drupal::moduleHandler()->moduleExists('pathauto')) {
      // 0 = PathautoState::SKIP.
      $path['pathauto'] = 0;
    }
    $node->set('path', $path);
  }

  protected function setAlias($entity, string $alias): void {
    $this->setPathAlias('/' . $entity->toUrl()->getInternalPath(), $alias, $entity->language()->getId());
  }

  protected function setPathAlias(string $path, string $alias, ?string $langcode = NULL): void {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $langcode ??= \Drupal::languageManager()->getDefaultLanguage()->getId();
    $existing = $storage->loadByProperties(['path' => $path]);
    if ($existing) {
      $existing = reset($existing);
      if ($existing->getAlias() !== $alias) {
        $existing->setAlias($alias)->save();
      }
    }
    else {
      $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $langcode])->save();
    }
  }

  protected function addLegacy(array $data, $entity): void {
    foreach ($data['legacy'] ?? [] as $old) {
      $this->legacy[$old] = $entity;
    }
  }

  /* ---- Series and seasons ------------------------------------------------ */

  protected function seriesTerms(): void {
    $list = $this->multi ? $this->pack['series'] : [$this->pack['series']];
    foreach ($list as $index => $data) {
      $values = [
        'field_abreviation' => $data['abbreviation'],
        'field_dates' => $data['dates'] ?? '',
        'field_creators' => $data['creators'] ?? '',
        'field_image' => $this->picture($data['image'] ?? NULL, $data['name']),
        'weight' => $data['weight'] ?? $index,
      ];
      if (isset($data['description'])) {
        $values['description'] = $this->text($data['description']);
      }
      if (isset($data['facts'])) {
        $values['field_facts'] = $data['facts'];
      }
      $term = $this->term('serie', $data['name'], $values);
      $this->series[$data['abbreviation']] = $term;
      $this->addLegacy($data, $term);
    }
  }

  protected function serieOf(?string $abbreviation) {
    if ($abbreviation === NULL) {
      return reset($this->series) ?: NULL;
    }
    return $this->series[$abbreviation] ?? NULL;
  }

  protected function seasonTerms(): void {
    foreach ($this->pack['seasons'] ?? [] as $data) {
      $serie = $this->serieOf($data['serie'] ?? NULL);
      if (!$serie) {
        continue;
      }
      $abbreviation = $serie->get('field_abreviation')->value;
      $dates = $this->presenter->date($data['first_aired'] ?? NULL);
      if ($dates && !empty($data['last_aired']) && $data['last_aired'] !== $data['first_aired']) {
        $dates .= ' – ' . $this->presenter->date($data['last_aired']);
      }
      $name = $data['title'] ?? 'Saison ' . $data['number'];
      // One-show packs keep the keys of their first imports.
      $key = $this->multi ? $abbreviation . ':' . $data['number'] : NULL;
      $term = $this->term('saison', $name, [
        'field_serie' => $serie->id(),
        'field_season_number' => $data['number'],
        'field_dates' => $dates ?: '',
        'weight' => $data['number'],
      ], $key);
      $this->seasons[$abbreviation . ':' . $data['number']] = $term;
      $this->addLegacy($data, $term);
    }
  }

  protected function episodeKey(array $data): string {
    if (!$this->multi) {
      return sprintf('episode:%d:%d', $data['season'], $data['number']);
    }
    return sprintf('episode:%s:%d:%d', $data['serie'], $data['season'], $data['number']);
  }

  /* ---- People ------------------------------------------------------------ */

  /**
   * People of the pack: its "people" list, the actors of its characters and
   * every director and writer of its episodes.
   */
  protected function people(): array {
    $people = [];
    $add = function (string $name, array $values = []) use (&$people) {
      $name = trim($name);
      if ($name === '') {
        return;
      }
      $key = mb_strtolower($name);
      $people[$key] ??= ['name' => $name, 'jobs' => [], 'series' => []];
      foreach (['jobs', 'series'] as $list) {
        foreach ($values[$list] ?? [] as $item) {
          $people[$key][$list][$item] = TRUE;
        }
        unset($values[$list]);
      }
      $people[$key] += array_filter($values, fn($value) => $value !== NULL && $value !== '');
    };
    foreach ($this->pack['people'] ?? [] as $person) {
      $add($person['name'], $person);
    }
    foreach ($this->pack['characters'] ?? [] as $character) {
      if (!empty($character['actor'])) {
        $add($character['actor'], ['jobs' => ['Interprète'], 'body' => $character['actor_body'] ?? NULL, 'image' => $character['actor_image'] ?? NULL]);
      }
    }
    foreach ($this->pack['episodes'] ?? [] as $episode) {
      $serie = $this->multi ? [$episode['serie']] : [];
      foreach ($episode['directors'] ?? [] as $name) {
        $add($name, ['jobs' => ['Réalisateur'], 'series' => $serie]);
      }
      foreach ($episode['writers'] ?? [] as $name) {
        $add($name, ['jobs' => ['Scénariste'], 'series' => $serie]);
      }
    }
    $ids = [];
    foreach ($people as $key => $data) {
      $name = $data['name'];
      $terms = array_map(fn($job) => $this->term('job', $job)->id(), array_keys($data['jobs']));
      $node = $this->entity('node', 'people:' . $key, ['type' => 'people', 'uid' => 1]);
      $node->setTitle($name);
      $node->set('field_job', $terms);
      if (!empty($data['body'])) {
        $node->set('body', $this->text($data['body']));
      }
      if (isset($data['facts'])) {
        $node->set('field_facts', $data['facts']);
      }
      if (!empty($data['image']) && ($picture = $this->file($data['image']))) {
        $node->set('field_picture', ['target_id' => $picture, 'alt' => $name]);
      }
      if ($node->hasField('field_series')) {
        $node->set('field_series', array_values(array_filter(array_map(fn($abbr) => $this->serieOf($abbr)?->id(), array_keys($data['series'])))));
      }
      $this->store($node);
      $this->remember('node', 'people:' . $key, $node);
      $this->addLegacy($data, $node);
      $ids[$name] = $node->id();
    }
    return $ids;
  }

  protected function personId(array $people, string $name): ?int {
    if (isset($people[$name])) {
      return (int) $people[$name];
    }
    foreach ($people as $known => $id) {
      if (mb_strtolower($known) === mb_strtolower($name)) {
        return (int) $id;
      }
    }
    return NULL;
  }

  /* ---- Wiki ---------------------------------------------------------------- */

  /**
   * Characters of a one-show pack, each with the actor who plays it.
   */
  protected function characters($serie, array $people): void {
    $category = $this->term('category', 'Personnages', ['weight' => 0]);
    $created = strtotime('2009-08-20 12:00:00 UTC');
    foreach ($this->pack['characters'] as $index => $data) {
      $key = 'character:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'editorial', 'uid' => 1]);
      $node->setTitle($data['character']);
      $node->set('body', $this->text($data['character_body'] ?? ''));
      $node->set('field_category', $category->id());
      $node->set('field_serie', $serie->id());
      $actor = $this->personId($people, $data['actor'] ?? '');
      $node->set('field_actor', $actor ? [$actor] : []);
      $portrait = $this->override($data['slug']) ?? ($data['image'] ?? NULL);
      $node->set('field_image', $this->picture($portrait, $data['character']));
      $node->set('field_gallery', $this->images(array_values(array_diff($data['gallery'] ?? [], [$portrait ?? ''])), $data['character']));
      // Keeps the old site's cast order on the cast page.
      $node->setCreatedTime($created + $index * 60);
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->addLegacy($data, $node);
    }
  }

  /**
   * Wiki rubrics and entries of a multi-show pack.
   */
  protected function wiki(array $people): void {
    $categories = [];
    foreach ($this->pack['wiki_categories'] ?? [] as $index => $data) {
      $categories[$data['name']] = $this->term('category', $data['name'], [
        'description' => ['value' => $data['description'] ?? '', 'format' => 'basic_html'],
        'weight' => $index,
      ]);
    }
    $tags = [];
    $episodes = $this->entityTypeManager->getStorage('node');
    foreach ($this->pack['wiki'] ?? [] as $index => $data) {
      $category = $categories[$data['category']] ??= $this->term('category', $data['category']);
      $key = 'wiki:' . mb_strtolower($data['category']) . ':' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'editorial', 'uid' => 1]);
      $node->setTitle($data['title']);
      $node->set('body', $this->text($data['body'] ?? ''));
      $node->set('field_category', $category->id());
      $node->set('field_serie', $this->serieOf($data['serie'] ?? NULL)?->id());
      $node->set('field_image', $this->picture($this->override($data['slug']) ?? ($data['image'] ?? NULL), $data['title']));
      $node->set('field_gallery', $this->images($data['gallery'] ?? [], $data['title']));
      $node->set('field_facts', $data['facts'] ?? NULL);
      $appearance = [];
      foreach ($data['appearance'] ?? [] as $episode) {
        if ($id = $this->map->get(self::key('node:' . $this->episodeKey($episode)))) {
          $appearance[] = $id;
        }
      }
      $node->set('field_appearance', $appearance);
      $node->set('field_actor', array_values(array_filter(array_map(fn($name) => $this->personId($people, $name), $data['actors'] ?? []))));
      $ids = [];
      foreach ($data['tags'] ?? [] as $tag) {
        $tags[$tag] ??= $this->term('tags', $tag)->id();
        $ids[] = $tags[$tag];
      }
      $node->set('field_tags', $ids);
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->addLegacy($data, $node);
    }
  }

  /* ---- Episodes ------------------------------------------------------------ */

  protected function episodes(array $people): void {
    foreach ($this->pack['episodes'] ?? [] as $data) {
      $serie = $this->serieOf($data['serie'] ?? NULL);
      $season = $serie ? ($this->seasons[$serie->get('field_abreviation')->value . ':' . $data['season']] ?? NULL) : NULL;
      if (!$season) {
        continue;
      }
      $key = $this->episodeKey($data);
      $node = $this->entity('node', $key, ['type' => 'episode', 'uid' => 1]);
      $title = $data['title_fr'] ?: $data['title_original'];
      $node->setTitle(mb_substr($title, 0, 255));
      $node->set('field_season', $season->id());
      $node->set('field_episode', $data['number']);
      $node->set('field_original_title', $data['title_original'] ?? NULL);
      $node->set('field_date_de_diffusion', $data['air_date'] ?? NULL);
      $node->set('field_director', array_values(array_filter(array_map(fn($name) => $this->personId($people, $name), $data['directors'] ?? []))));
      $node->set('field_writers', array_values(array_filter(array_map(fn($name) => $this->personId($people, $name), $data['writers'] ?? []))));
      $node->set('field_guest_stars', $data['guest_cast'] ?? NULL);
      if (array_key_exists('audience', $data)) {
        $node->set('field_audience', $data['audience']);
      }
      else {
        $viewers = $data['us_viewers_millions'] ?? NULL;
        $node->set('field_audience', $viewers ? str_replace('.', ',', (string) round($viewers, 2)) . ' million' . ($viewers >= 2 ? 's' : '') . ' de téléspectateurs (États-Unis)' : NULL);
      }
      if (array_key_exists('duration', $data)) {
        $node->set('field_duration', $data['duration']);
      }
      if (array_key_exists('facts', $data) && $node->hasField('field_facts')) {
        $node->set('field_facts', $data['facts'] ?: NULL);
      }
      if (!empty($data['teaser'])) {
        $node->set('field_meta_description', $data['teaser']);
      }
      if (array_key_exists('body', $data)) {
        $node->set('body', $this->text($data['body']));
      }
      // A synopsis written on the site is kept when the pack has none.
      if (!empty($data['synopsis']) || $node->get('field_synopsis')->isEmpty()) {
        $node->set('field_synopsis', !empty($data['synopsis']) ? $this->text($data['synopsis']) : NULL);
      }
      // images/episode-1-16.jpg next to the pack fills or replaces the picture.
      $node->set('field_image', $this->picture($this->override(sprintf('episode-%d-%02d', $data['season'], $data['number'])) ?? ($data['image'] ?? NULL), $title));
      // Full sets fetched by scripts/fetch_gateworld_promos.sh, when they are
      // there, replace the smaller sets of the archive.
      $sets = $this->multi ? ['promo' => [], 'backstage' => []] : $this->localSets(sprintf('gateworld/%d-%02d', $data['season'], $data['number']));
      $node->set('field_promotional_pictures', $this->images($sets['promo'] ?: ($data['promo'] ?? []), $title));
      $node->set('field_gallery', $this->images($sets['backstage'] ?: ($data['backstage'] ?? []), $title));
      if (!empty($data['air_date'])) {
        $node->setCreatedTime(strtotime($data['air_date'] . ' 12:00:00 UTC'));
      }
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->addLegacy($data, $node);
    }
  }

  /* ---- Products ------------------------------------------------------------ */

  protected function products(): void {
    if (empty($this->pack['products']) && empty($this->pack['product_types'])) {
      return;
    }
    $types = [];
    foreach ($this->pack['product_types'] ?? [] as $index => $data) {
      $parent = $this->term('product_type', $data['name'], [
        'description' => ['value' => $data['description'] ?? '', 'format' => 'basic_html'],
        'weight' => $index,
        'parent' => [0],
      ]);
      $types[$data['name']] = $parent;
      foreach ($data['children'] ?? [] as $child_index => $child) {
        $types[$child] = $this->term('product_type', $child, ['parent' => [$parent->id()], 'weight' => $child_index]);
      }
    }
    foreach ($this->pack['products'] ?? [] as $data) {
      $type = $types[$data['type']] ??= $this->term('product_type', $data['type']);
      $key = 'product:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'product', 'uid' => 1]);
      $node->setTitle(mb_substr($data['title'], 0, 255));
      $node->set('body', $this->text($data['body'] ?? ''));
      $node->set('field_product_type', $type->id());
      $node->set('field_series', array_values(array_filter(array_map(fn($abbr) => $this->serieOf($abbr)?->id(), $data['series'] ?? []))));
      $node->set('field_image', $this->picture($this->override($data['slug']) ?? ($data['image'] ?? NULL), $data['title']));
      $node->set('field_gallery', $this->images($data['gallery'] ?? [], $data['title']));
      $node->set('field_facts', $data['facts'] ?? NULL);
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->addLegacy($data, $node);
    }
  }

  /* ---- Pages, news, partners ----------------------------------------------- */

  protected function pages(): void {
    foreach ($this->pack['pages'] ?? [] as $data) {
      $key = 'page:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'page', 'uid' => 1]);
      $node->setTitle($data['title']);
      $node->set('body', $this->text($data['body'] ?? ''));
      $node->set('field_image', $this->picture($data['image'] ?? NULL, $data['title']));
      $node->set('field_gallery', $this->images($data['gallery'] ?? [], $data['title']));
      $this->presetAlias($node, '/' . $data['slug']);
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->setAlias($node, '/' . $data['slug']);
      $this->addLegacy($data, $node);
      if (!empty($data['footer']) && empty($this->pack['menus']['footer'])) {
        $this->footerLink($data['title'], '/' . $data['slug']);
      }
    }
  }

  /**
   * Adds a page to the footer menu, once.
   */
  protected function footerLink(string $title, string $path): void {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    if ($storage->loadByProperties(['menu_name' => 'footer', 'link__uri' => 'internal:' . $path])) {
      return;
    }
    $storage->create(['title' => $title, 'link' => ['uri' => 'internal:' . $path], 'menu_name' => 'footer', 'weight' => 20])->save();
    $this->count('footer links');
  }

  protected function articles(callable $log): void {
    foreach ($this->pack['article_categories'] ?? [] as $index => $data) {
      $this->term('article_category', $data['name'], [
        'description' => ['value' => $data['description'] ?? '', 'format' => 'basic_html'],
        'weight' => $index,
      ]);
    }
    $tags = [];
    $articles = $this->pack['articles'] ?? [];
    // The two most recent illustrated articles open the front page.
    $promoted = [];
    foreach (array_reverse($articles) as $data) {
      if (!empty($data['image']) && count($promoted) < 2) {
        $promoted[$data['slug']] = TRUE;
      }
    }
    foreach ($articles as $index => $data) {
      $key = 'article:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'article', 'uid' => 1]);
      $node->setTitle(mb_substr($data['title'], 0, 255));
      $body = $this->text($data['body']) ?? ['value' => '', 'format' => 'full_html'];
      if (!empty($data['summary'])) {
        $body['summary'] = $data['summary'];
      }
      $node->set('body', $body);
      $node->set('field_article_category', $this->term('article_category', $data['category'] ?: 'Actualités')->id());
      $ids = [];
      foreach ($data['tags'] ?? [] as $tag) {
        $tag_key = mb_strtolower(trim($tag));
        if ($tag_key !== '') {
          $tags[$tag_key] ??= $this->term('tags', trim($tag))->id();
          $ids[$tags[$tag_key]] = $tags[$tag_key];
        }
      }
      $node->set('field_tags', array_values($ids));
      $node->set('field_byline', $data['author'] ?? NULL);
      $node->set('field_image', $this->picture($data['image'] ?? NULL, ''));
      $node->set('field_gallery', $this->images($data['gallery'] ?? []));
      $node->setCreatedTime(is_int($data['date']) ? $data['date'] : strtotime($data['date'] . ' 12:00:00 Europe/Paris'));
      $node->setPromoted(isset($promoted[$data['slug']]));
      $this->presetAlias($node, '/actualites/' . $data['slug']);
      $this->store($node);
      $this->remember('node', $key, $node);
      $this->setAlias($node, '/actualites/' . $data['slug']);
      if (isset($data['legacy'])) {
        $this->addLegacy($data, $node);
      }
      else {
        // Old WordPress posts lived at the root: /my-post/.
        $this->legacy['/' . $data['slug']] = $node;
      }
      if (($index + 1) % 100 === 0) {
        $log('Articles: ' . ($index + 1) . ' / ' . count($articles));
      }
    }
  }

  protected function partners(): void {
    foreach ($this->pack['partners'] ?? [] as $index => $data) {
      $this->term('partenaires', $data['name'], [
        'field_url' => ['uri' => $data['url']],
        'description' => ['value' => $data['description'] ?? '', 'format' => 'basic_html'],
        'field_logo' => $this->picture($data['logo'] ?? NULL, $data['name']),
        'weight' => $index,
      ]);
    }
  }

  /**
   * Attaches to each episode the news posts written about it.
   */
  protected function linkEpisodes(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($this->pack['episodes'] ?? [] as $data) {
      if (empty($data['posts'])) {
        continue;
      }
      $episode_id = $this->map->get(self::key('node:' . $this->episodeKey($data)));
      $episode = $episode_id ? $storage->load($episode_id) : NULL;
      if (!$episode) {
        continue;
      }
      $ids = [];
      foreach ($data['posts'] as $slug) {
        if ($id = $this->map->get(self::key('node:article:' . $slug))) {
          $ids[] = $id;
        }
      }
      // Newest first, like every other news list.
      $episode->set('field_linked_content', array_reverse($ids));
      $episode->save();
    }
  }

  /* ---- References, menus, addresses ----------------------------------------- */

  /**
   * The entity a pack reference names: "serie:sga", "season:sga:2",
   * "page:faq", "category:Vaisseaux", "product_type:DVD",
   * "news_category:Acteurs", "job:Acteur" or "node:<pack key>".
   */
  protected function referenced(string $ref) {
    [$kind, $rest] = explode(':', $ref, 2) + [1 => ''];
    return match ($kind) {
      'serie' => $this->series[$rest] ?? NULL,
      'season' => $this->seasons[$rest] ?? NULL,
      'page' => $this->known('node', 'page:' . $rest),
      'category' => $this->known('taxonomy_term', 'category:' . mb_strtolower($rest)),
      'product_type' => $this->known('taxonomy_term', 'product_type:' . mb_strtolower($rest)),
      'news_category' => $this->known('taxonomy_term', 'article_category:' . mb_strtolower($rest)),
      'job' => $this->known('taxonomy_term', 'job:' . mb_strtolower($rest)),
      'node' => $this->known('node', $rest),
      default => NULL,
    };
  }

  /**
   * A pack reference as a link: [uri for menu links, options].
   *
   * References may end with "?query".
   */
  protected function linkTarget(string $ref): ?array {
    $query = [];
    if (str_contains($ref, '?') && !str_starts_with($ref, 'internal:')) {
      [$ref, $string] = explode('?', $ref, 2);
      parse_str($string, $query);
    }
    if (str_starts_with($ref, 'internal:') || str_starts_with($ref, 'route:')) {
      return [$ref, $query ? ['query' => $query] : []];
    }
    $entity = $this->referenced($ref);
    if (!$entity) {
      $this->count('unresolved references');
      return NULL;
    }
    return ['entity:' . $entity->getEntityTypeId() . '/' . $entity->id(), $query ? ['query' => $query] : []];
  }

  /**
   * A pack reference as an address on the site.
   */
  protected function linkUrl(string $ref): ?string {
    $target = $this->linkTarget($ref);
    if (!$target) {
      return NULL;
    }
    [$uri, $options] = $target;
    if (str_starts_with($uri, 'internal:') && str_contains($uri, '?')) {
      [$uri, $string] = explode('?', $uri, 2);
      parse_str($string, $query);
      $options['query'] = $query;
    }
    try {
      return Url::fromUri($uri, $options)->toString();
    }
    catch (\Throwable $e) {
      $this->count('unresolved references');
      return NULL;
    }
  }

  /**
   * Main and footer menus as the pack describes them.
   *
   * A menu given in the pack replaces the whole menu.
   */
  protected function menus(): void {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    foreach ($this->pack['menus'] ?? [] as $menu => $items) {
      $storage->delete($storage->loadByProperties(['menu_name' => $menu]));
      $create = function (array $items, ?string $parent) use (&$create, $storage, $menu) {
        foreach ($items as $weight => $item) {
          $target = isset($item['ref']) ? $this->linkTarget($item['ref']) : ['route:<nolink>', []];
          if (!$target) {
            continue;
          }
          [$uri, $options] = $target;
          if (!empty($item['query'])) {
            parse_str($item['query'], $query);
            $options['query'] = ($options['query'] ?? []) + $query;
          }
          if (str_starts_with($uri, 'internal:') && str_contains($uri, '?')) {
            [$uri, $string] = explode('?', $uri, 2);
            parse_str($string, $query);
            $options['query'] = ($options['query'] ?? []) + $query;
          }
          $link = $storage->create([
            'title' => $item['title'],
            'description' => $item['description'] ?? '',
            'link' => ['uri' => $uri, 'options' => $options],
            'menu_name' => $menu,
            'weight' => $weight,
            'expanded' => !empty($item['children']),
            'parent' => $parent,
          ]);
          $link->save();
          $this->count("$menu menu links");
          if (!empty($item['children'])) {
            $create($item['children'], 'menu_link_content:' . $link->uuid());
          }
        }
      };
      $create($items, NULL);
    }
  }

  /**
   * Addresses of listing pages: "route:tvshow_core.wiki" => "/encyclopedie".
   */
  protected function aliases(): void {
    foreach ($this->pack['aliases'] ?? [] as $data) {
      $target = $this->linkTarget($data['ref']);
      if (!$target) {
        continue;
      }
      $url = Url::fromUri($target[0]);
      $path = '/' . ($url->isRouted() ? $url->getInternalPath() : ltrim($url->toString(), '/'));
      $this->setPathAlias($path, $data['alias']);
    }
  }

  /**
   * Remembers where the old site's pages now live.
   */
  protected function legacyRedirects(): void {
    $store = $this->keyValue->get('tvshow_core.legacy');
    $with_redirect_module = \Drupal::moduleHandler()->moduleExists('redirect');
    $count = 0;
    foreach ($this->legacy as $old => $entity) {
      $store->set(self::key(rtrim($old, '/')), $entity->toUrl()->toString());
      if ($with_redirect_module) {
        $this->redirect($old, '/' . $entity->toUrl()->getInternalPath());
      }
      $count++;
    }
    $fixed = [];
    if (isset($this->pack['redirects'])) {
      foreach ($this->pack['redirects'] as $old => $ref) {
        if ($url = $this->linkUrl($ref)) {
          $fixed[$old] = $url;
        }
      }
    }
    else {
      $fixed = [
        '/equipe' => '/casting',
        '/galerie' => '/episodes',
        '/galerie/saison-1' => '/episodes',
        '/galerie/making-off' => '/episodes',
        '/videos' => '/actualites/rubrique/videos',
        '/videos/webisodes' => '/actualites/rubrique/videos',
        '/videos/trailers-saison-1' => '/actualites/rubrique/videos',
        '/category/non-classe' => '/actualites',
        '/feed' => '/actualites/rss.xml',
        '/feed/rss' => '/actualites/rss.xml',
      ];
    }
    $base = base_path();
    foreach ($fixed as $old => $new) {
      // Stored without the base path, like entity addresses.
      $new = str_starts_with($new, $base) ? '/' . substr($new, strlen($base)) : $new;
      $store->set(self::key(rtrim($old, '/')), $new);
      if ($with_redirect_module) {
        $this->redirect($old, $new);
      }
      $count++;
    }
    $this->keyValue->get('tvshow_core.legacy_patterns')->set('patterns', $this->pack['legacy_patterns'] ?? []);
    foreach ($this->pack['tags'] ?? [] as $tag) {
      $id = $this->map->get(self::key('taxonomy_term:tags:' . mb_strtolower(trim($tag))));
      $term = $id ? $this->entityTypeManager->getStorage('taxonomy_term')->load($id) : NULL;
      if ($term) {
        $store->set(self::key('/tag/' . $this->aliasGenerator->slug($tag)), $term->toUrl()->toString());
        if ($with_redirect_module) {
          $this->redirect('/tag/' . $this->aliasGenerator->slug($tag), '/' . $term->toUrl()->getInternalPath());
        }
      }
    }
    $this->stats['old addresses redirected'] = $count;
  }

  /**
   * Creates a 301 with the Redirect module, once per old address.
   */
  protected function redirect(string $old, string $target): void {
    try {
      $storage = $this->entityTypeManager->getStorage('redirect');
      $source = trim($old, '/');
      if ($source === '' || $storage->loadByProperties(['redirect_source__path' => $source])) {
        return;
      }
      $query = [];
      if (str_contains($target, '?')) {
        [$target, $string] = explode('?', $target, 2);
        parse_str($string, $query);
      }
      $redirect = $storage->create(['status_code' => 301, 'language' => 'und']);
      $redirect->setSource($source);
      $redirect->setRedirect(ltrim($target, '/'), $query);
      $redirect->save();
      $this->count('redirect entities');
    }
    catch (\Throwable $e) {
      $this->count('redirects that failed');
    }
  }

  /**
   * Turns legacy:/old-page.html links into the new addresses.
   */
  protected function relinkAll(): void {
    if (!$this->relink) {
      return;
    }
    $store = $this->keyValue->get('tvshow_core.legacy');
    $patterns = $this->pack['legacy_patterns'] ?? [];
    $resolve = function (string $old) use ($store, $patterns): ?string {
      [$path, $fragment] = explode('#', $old, 2) + [1 => NULL];
      $target = $store->get(self::key(rtrim($path, '/')));
      if (!$target) {
        foreach ($patterns as $pattern) {
          $regex = '~' . $pattern['from'] . '~';
          if (preg_match($regex, $path)) {
            $candidate = preg_replace($regex, str_replace('$', '\\', $pattern['to']), $path);
            $target = !empty($pattern['lookup']) ? $store->get(self::key($candidate)) : $candidate;
            break;
          }
        }
      }
      if (!$target) {
        return NULL;
      }
      return rtrim(base_path(), '/') . $target . ($fragment !== NULL && $fragment !== '' ? '#' . $fragment : '');
    };
    $missing = [];
    foreach (array_unique($this->relink, SORT_REGULAR) as [$type, $id]) {
      $entity = $this->entityTypeManager->getStorage($type)->load($id);
      if (!$entity) {
        continue;
      }
      foreach (['body', 'field_synopsis', 'description'] as $field) {
        if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
          continue;
        }
        $item = $entity->get($field)->first()->getValue();
        $item['value'] = preg_replace_callback('~href="legacy:([^"]+)"~', function ($m) use ($resolve, &$missing) {
          $url = $resolve($m[1]);
          if ($url === NULL) {
            $missing[$m[1]] = TRUE;
            // Unknown page of the old site: the link goes, its text stays.
            return 'href="#legacy"';
          }
          return 'href="' . htmlspecialchars($url, ENT_QUOTES) . '"';
        }, $item['value']);
        $item['value'] = preg_replace('~<a href="#legacy">(.*?)</a>~s', '$1', $item['value']);
        $entity->get($field)->setValue([$item]);
      }
      $entity->save();
    }
    $this->stats['links to old pages rewritten'] = count($this->relink);
    if ($missing) {
      $this->stats['links to unknown old pages'] = count($missing) . ' (' . implode(', ', array_slice(array_keys($missing), 0, 8)) . (count($missing) > 8 ? '…' : '') . ')';
    }
  }

  /**
   * Fills the Search API index, when there is one.
   */
  protected function indexContent(): void {
    if (!\Drupal::moduleHandler()->moduleExists('search_api')) {
      return;
    }
    try {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load(\Drupal\tvshow_core\ContribSetup::SEARCH_INDEX);
      if ($index && $index->status()) {
        $this->stats['items indexed for search'] = $index->indexItems();
      }
    }
    catch (\Throwable $e) {
      $this->stats['search indexing'] = 'failed: ' . $e->getMessage();
    }
  }

}
