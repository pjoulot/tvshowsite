<?php

namespace Drupal\tvshow_core\Import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\tvshow_core\AliasGenerator;
use Drupal\tvshow_core\Presenter;

/**
 * Loads a site content pack (JSON) into Drupal.
 *
 * A pack describes one site: series, seasons, episodes, characters, pages and
 * news. Pictures are read from a source folder (the old site's archive).
 * Running the import again updates what it created instead of duplicating it.
 */
class ContentPackImporter {

  /**
   * Counters reported at the end.
   */
  public array $stats = [];

  protected array $pack = [];

  protected string $source = '';

  protected $map;

  protected $files;

  protected array $legacy = [];

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
    $this->stats = [];

    $this->site();
    $serie = $this->serie();
    $seasons = $this->seasons($serie);
    $log('Series and seasons ready.');
    $people = $this->people();
    $log('People: ' . count($people));
    $this->characters($serie, $people);
    $log('Characters ready.');
    $this->episodes($seasons, $people);
    $log('Episodes ready.');
    $this->pages();
    $this->articles($log);
    $this->legacyRedirects();
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
    if (!empty($site['slogan'])) {
      $this->configFactory->getEditable('tvshow_core.settings')->set('tagline', $site['slogan'])->save();
    }
  }

  /**
   * Copies one picture from the source folder and returns its file entity id.
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
    $path = $this->source . '/' . $relative;
    if (!is_file($path)) {
      $this->count('missing pictures');
      return NULL;
    }
    $clean = preg_replace('~^wp-content/~', '', $relative);
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
   * Replaces archive: references in body HTML with public file URLs.
   */
  protected function html(?string $html): string {
    if (!$html) {
      return '';
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $html = preg_replace_callback('/\b(src|href)="archive:([^"]+)"/', function ($m) use ($storage) {
      $fid = $this->file($m[2]);
      $file = $fid ? $storage->load($fid) : NULL;
      return $file ? $m[1] . '="' . $this->fileUrlGenerator->generateString($file->getFileUri()) . '"' : $m[1] . '="#"';
    }, $html);
    // Drop images and links whose picture could not be found.
    $html = preg_replace('~<img[^>]*src="#"[^>]*/?>~', '', $html);
    $html = preg_replace('~<a href="#">(.*?)</a>~s', '$1', $html);
    return $html;
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

  protected function term(string $vocabulary, string $name, array $values = []) {
    $key = $vocabulary . ':' . mb_strtolower($name);
    $term = $this->entity('taxonomy_term', $key, ['vid' => $vocabulary]);
    $term->setName($name);
    foreach ($values as $field => $value) {
      $term->set($field, $value);
    }
    $term->save();
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
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $path = '/' . $entity->toUrl()->getInternalPath();
    $existing = $storage->loadByProperties(['path' => $path]);
    if ($existing) {
      $existing = reset($existing);
      if ($existing->getAlias() !== $alias) {
        $existing->setAlias($alias)->save();
      }
    }
    else {
      $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $entity->language()->getId()])->save();
    }
  }

  protected function serie() {
    $data = $this->pack['series'];
    $image = $this->file($data['image'] ?? NULL);
    return $this->term('serie', $data['name'], [
      'field_abreviation' => $data['abbreviation'],
      'field_dates' => $data['dates'] ?? '',
      'field_creators' => $data['creators'] ?? '',
      'field_image' => $image ? ['target_id' => $image, 'alt' => $data['name']] : [],
    ]);
  }

  protected function seasons($serie): array {
    $seasons = [];
    foreach ($this->pack['seasons'] as $data) {
      $dates = $this->presenter->date($data['first_aired'] ?? NULL);
      if ($dates && !empty($data['last_aired'])) {
        $dates .= ' – ' . $this->presenter->date($data['last_aired']);
      }
      $seasons[$data['number']] = $this->term('saison', 'Saison ' . $data['number'], [
        'field_serie' => $serie->id(),
        'field_season_number' => $data['number'],
        'field_dates' => $dates ?: '',
        'weight' => $data['number'],
      ]);
    }
    return $seasons;
  }

  /**
   * Actors, directors and writers named in the pack.
   */
  protected function people(): array {
    $jobs = [];
    $bodies = [];
    foreach ($this->pack['characters'] as $character) {
      if (!empty($character['actor'])) {
        $jobs[$character['actor']]['Interprète'] = TRUE;
        $bodies[$character['actor']] = $character['actor_body'] ?? '';
      }
    }
    foreach ($this->pack['episodes'] as $episode) {
      foreach ($episode['directors'] ?? [] as $name) {
        $jobs[$name]['Réalisateur'] = TRUE;
      }
      foreach ($episode['writers'] ?? [] as $name) {
        $jobs[$name]['Scénariste'] = TRUE;
      }
    }
    $people = [];
    foreach ($jobs as $name => $names) {
      $terms = array_map(fn($job) => $this->term('job', $job)->id(), array_keys($names));
      $node = $this->entity('node', 'people:' . mb_strtolower($name), ['type' => 'people', 'uid' => 1]);
      $node->setTitle($name);
      $node->set('field_job', $terms);
      if (!empty($bodies[$name])) {
        $node->set('body', ['value' => $this->html($bodies[$name]), 'format' => 'full_html']);
      }
      $node->save();
      $this->remember('node', 'people:' . mb_strtolower($name), $node);
      $people[$name] = $node->id();
    }
    return $people;
  }

  protected function characters($serie, array $people): void {
    $category = $this->term('category', 'Personnages', ['weight' => 0]);
    $created = strtotime('2009-08-20 12:00:00 UTC');
    foreach ($this->pack['characters'] as $index => $data) {
      $key = 'character:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'editorial', 'uid' => 1]);
      $node->setTitle($data['character']);
      $node->set('body', ['value' => $this->html($data['character_body'] ?? ''), 'format' => 'full_html']);
      $node->set('field_category', $category->id());
      $node->set('field_serie', $serie->id());
      $node->set('field_actor', isset($people[$data['actor']]) ? [$people[$data['actor']]] : []);
      $image = $this->file($data['image'] ?? NULL);
      $node->set('field_image', $image ? ['target_id' => $image, 'alt' => $data['character']] : []);
      // Keeps the old site's cast order on the cast page.
      $node->setCreatedTime($created + $index * 60);
      $node->save();
      $this->remember('node', $key, $node);
      foreach ($data['legacy'] ?? [] as $old) {
        $this->legacy[$old] = $node;
      }
    }
  }

  protected function episodes(array $seasons, array $people): void {
    foreach ($this->pack['episodes'] as $data) {
      $season = $seasons[$data['season']] ?? NULL;
      if (!$season) {
        continue;
      }
      $key = sprintf('episode:%d:%d', $data['season'], $data['number']);
      $node = $this->entity('node', $key, ['type' => 'episode', 'uid' => 1]);
      $title = $data['title_fr'] ?: $data['title_original'];
      $node->setTitle($title);
      $node->set('field_season', $season->id());
      $node->set('field_episode', $data['number']);
      $node->set('field_original_title', $data['title_original']);
      $node->set('field_date_de_diffusion', $data['air_date'] ?? NULL);
      $node->set('field_director', array_values(array_filter(array_map(fn($name) => $people[$name] ?? NULL, $data['directors'] ?? []))));
      $node->set('field_writers', array_values(array_filter(array_map(fn($name) => $people[$name] ?? NULL, $data['writers'] ?? []))));
      $node->set('field_guest_stars', $data['guest_cast'] ?? NULL);
      $node->set('field_synopsis', !empty($data['synopsis']) ? ['value' => $this->html($data['synopsis']), 'format' => 'full_html'] : NULL);
      $image = $this->file($data['image'] ?? NULL);
      $node->set('field_image', $image ? ['target_id' => $image, 'alt' => $title] : []);
      $node->set('field_promotional_pictures', $this->images($data['promo'] ?? [], $title));
      $node->set('field_gallery', $this->images($data['backstage'] ?? [], $title));
      if (!empty($data['air_date'])) {
        $node->setCreatedTime(strtotime($data['air_date'] . ' 12:00:00 UTC'));
      }
      $node->save();
      $this->remember('node', $key, $node);
      foreach ($data['legacy'] ?? [] as $old) {
        $this->legacy[$old] = $node;
      }
    }
  }

  protected function pages(): void {
    foreach ($this->pack['pages'] as $data) {
      $key = 'page:' . $data['slug'];
      $node = $this->entity('node', $key, ['type' => 'page', 'uid' => 1]);
      $node->setTitle($data['title']);
      $node->set('body', ['value' => $this->html($data['body'] ?? ''), 'format' => 'full_html']);
      $image = $this->file($data['image'] ?? NULL);
      $node->set('field_image', $image ? ['target_id' => $image, 'alt' => $data['title']] : []);
      $node->set('field_gallery', $this->images($data['gallery'] ?? [], $data['title']));
      $this->presetAlias($node, '/' . $data['slug']);
      $node->save();
      $this->remember('node', $key, $node);
      $this->setAlias($node, '/' . $data['slug']);
      foreach ($data['legacy'] ?? [] as $old) {
        $this->legacy[$old] = $node;
      }
    }
  }

  protected function articles(callable $log): void {
    $tags = [];
    $articles = $this->pack['articles'];
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
      $node->set('body', ['value' => $this->html($data['body']), 'format' => 'full_html']);
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
      $image = $this->file($data['image'] ?? NULL);
      $node->set('field_image', $image ? ['target_id' => $image, 'alt' => ''] : []);
      $node->set('field_gallery', $this->images($data['gallery'] ?? []));
      $node->setCreatedTime(strtotime($data['date'] . ' 12:00:00 Europe/Paris'));
      $node->setPromoted(isset($promoted[$data['slug']]));
      $this->presetAlias($node, '/actualites/' . $data['slug']);
      $node->save();
      $this->remember('node', $key, $node);
      $this->setAlias($node, '/actualites/' . $data['slug']);
      // Old WordPress posts lived at the root: /my-post/.
      $this->legacy['/' . $data['slug']] = $node;
      if (($index + 1) % 100 === 0) {
        $log('Articles: ' . ($index + 1) . ' / ' . count($articles));
      }
    }
  }

  /**
   * Remembers where the old site's section pages now live.
   */
  protected function legacyRedirects(): void {
    $store = $this->keyValue->get('tvshow_core.legacy');
    $with_redirect_module = \Drupal::moduleHandler()->moduleExists('redirect');
    foreach ($this->legacy as $old => $entity) {
      $store->set(self::key(rtrim($old, '/')), $entity->toUrl()->toString());
      if ($with_redirect_module) {
        $this->redirect($old, '/' . $entity->toUrl()->getInternalPath());
      }
    }
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
    foreach ($fixed as $old => $new) {
      $store->set(self::key($old), $new);
      if ($with_redirect_module) {
        $this->redirect($old, $new);
      }
    }
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
    $this->stats['old addresses redirected'] = count($this->legacy) + count($fixed);
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
      $redirect = $storage->create(['status_code' => 301, 'language' => 'und']);
      $redirect->setSource($source);
      $redirect->setRedirect(ltrim($target, '/'));
      $redirect->save();
      $this->count('redirect entities');
    }
    catch (\Throwable $e) {
      $this->count('redirects that failed');
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
