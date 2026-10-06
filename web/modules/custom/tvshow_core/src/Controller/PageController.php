<?php

namespace Drupal\tvshow_core\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Url;
use Drupal\tvshow_core\ContribSetup;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Composite pages: front page, episode guide, wiki, cast, partners, search.
 */
class PageController extends TvControllerBase {

  public function front(): array {
    // The two featured articles are not repeated in the list under them.
    $featured = array_map(fn($row) => (int) $row->nid, views_get_view_result('tvshow_news', 'featured'));
    $multi_show = count($this->repository->terms('serie')) > 1;
    return [
      '#theme' => 'tvshow_front',
      '#featured' => $featured ? $this->embed('tvshow_news', 'featured') : [],
      '#news' => $this->embed('tvshow_news', 'latest', $featured ? implode('+', $featured) : 'all'),
      '#wiki' => $this->embed('tvshow_wiki', 'front'),
      '#series' => $multi_show ? $this->embed('tvshow_terms', 'series') : NULL,
      '#cache' => self::CACHE,
    ];
  }

  /**
   * Episode guide: the series itself on a single-show site, else the list.
   */
  public function episodes() {
    $series = $this->repository->terms('serie');
    if (count($series) === 1) {
      return \Drupal::classResolver(TermController::class)->view(reset($series));
    }
    return $this->listing([
      'title' => 'Guide des épisodes',
      'layout' => 'posters',
      'content' => $this->embed('tvshow_terms', 'series'),
    ]);
  }

  public function wiki(): array {
    return [
      '#theme' => 'tvshow_wiki',
      '#categories' => $this->embed('tvshow_terms', 'wiki_categories'),
      '#latest' => $this->embed('tvshow_wiki', 'latest'),
      '#cache' => self::CACHE,
    ];
  }

  /**
   * Cast: characters with the people who play them, then the rest of the team.
   */
  public function cast(): array {
    return [
      '#theme' => 'tvshow_cast',
      '#roles' => $this->embed('tvshow_wiki', 'cast'),
      '#crew' => $this->embed('tvshow_people', 'crew'),
      '#has_crew' => (bool) views_get_view_result('tvshow_people', 'crew'),
      '#cache' => self::CACHE,
    ];
  }

  public function partners(): array {
    return ['#theme' => 'tvshow_partners', '#partners' => $this->embed('tvshow_terms', 'partners'), '#cache' => self::CACHE];
  }

  /**
   * Search page: the Search API view when its index is ready, else the
   * database view. Both read the keywords from ?s= (Views reserves ?q=).
   */
  public function search(): array {
    $keys = trim((string) $this->requests->getCurrentRequest()->query->get('s', ''));
    return [
      '#theme' => 'tvshow_search',
      '#keys' => $keys,
      '#results' => $keys === '' ? [] : $this->embed(...$this->searchView()),
      '#action' => Url::fromRoute('tvshow_core.search')->toString(),
      '#cache' => self::CACHE,
    ];
  }

  protected function searchView(): array {
    if ($this->moduleHandler()->moduleExists('search_api') && $this->entityTypeManager()->getStorage('view')->load('tvshow_search_api')) {
      try {
        $index = $this->entityTypeManager()->getStorage('search_api_index')->load(ContribSetup::SEARCH_INDEX);
        if ($index && $index->status() && $index->getTrackerInstance()->getIndexedItemsCount()) {
          return ['tvshow_search_api', 'results'];
        }
      }
      catch (\Throwable $e) {
        $this->getLogger('tvshow_core')->warning('Search API is not usable, using the database search: @message', ['@message' => $e->getMessage()]);
      }
    }
    return ['tvshow_search', 'results'];
  }

  public function notFound(): array {
    return [
      '#theme' => 'tvshow_not_found',
      '#action' => Url::fromRoute('tvshow_core.search')->toString(),
      '#news' => $this->embed('tvshow_news', 'short'),
      '#cache' => self::CACHE,
    ];
  }

  /**
   * XML sitemap of every published page.
   */
  public function sitemap(): CacheableResponse {
    $escape = fn($text) => htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $options = ['absolute' => TRUE];
    foreach (['<front>', 'view.tvshow_news.page', 'tvshow_core.episodes', 'tvshow_core.wiki', 'tvshow_core.cast', 'tvshow_core.partners'] as $route) {
      $xml .= '<url><loc>' . $escape(Url::fromRoute($route, [], $options)->toString()) . '</loc></url>';
    }
    foreach (['serie', 'saison', 'category', 'article_category'] as $vocabulary) {
      foreach ($this->repository->terms($vocabulary) as $term) {
        $xml .= '<url><loc>' . $escape($term->toUrl('canonical', $options)->toString()) . '</loc></url>';
      }
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    foreach (array_chunk($this->repository->allNodeIds(), 100) as $ids) {
      foreach ($storage->loadMultiple($ids) as $node) {
        $xml .= '<url><loc>' . $escape($node->toUrl('canonical', $options)->toString()) . '</loc><lastmod>' . gmdate('Y-m-d', $node->getChangedTime()) . '</lastmod></url>';
      }
      $storage->resetCache($ids);
    }
    $xml .= '</urlset>';
    return $this->xml($xml, 'application/xml; charset=utf-8');
  }

  protected function xml(string $xml, string $type): CacheableResponse {
    $response = new CacheableResponse($xml, 200, ['Content-Type' => $type]);
    $response->getCacheableMetadata()->addCacheTags(['node_list', 'taxonomy_term_list'])->addCacheContexts(['url.site']);
    return $response;
  }

  /**
   * Partner terms have no page of their own.
   */
  public static function toPartners(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('tvshow_core.partners')->toString(), 301);
  }

}
