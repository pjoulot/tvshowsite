<?php

namespace Drupal\tvshow_core\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Front page, news, episode guide, wiki, cast, partners, search and feeds.
 */
class PageController extends TvControllerBase {

  public function front(): array {
    $featured = $this->repository->featuredArticles(2);
    $skip = array_map(fn($node) => $node->id(), $featured);
    $news = array_values(array_filter($this->repository->articles(6 + count($skip)), fn($node) => !in_array($node->id(), $skip)));
    $series = $this->repository->terms('serie');
    return [
      '#theme' => 'tvshow_front',
      '#featured' => $this->presenter->cards($featured),
      '#news' => $this->presenter->cards(array_slice($news, 0, 6)),
      '#wiki' => $this->presenter->cards($this->repository->wikiEntries(6)),
      '#series' => count($series) > 1 ? array_map(fn($term) => $this->presenter->termCard($term), $series) : [],
      '#cache' => self::CACHE,
    ];
  }

  public function news(): array {
    $per_page = 12;
    $total = $this->repository->countArticles();
    $filters = [['label' => 'Toutes', 'url' => Url::fromRoute('tvshow_core.news')->toString(), 'active' => TRUE]];
    foreach ($this->repository->terms('article_category') as $term) {
      $filters[] = ['label' => $term->label(), 'url' => $term->toUrl()->toString(), 'active' => FALSE];
    }
    return $this->listing([
      'title' => 'Actualités',
      'layout' => 'news',
      'filters' => count($filters) > 2 ? $filters : [],
      'cards' => $this->presenter->cards($this->repository->articles($per_page, $this->page() * $per_page)),
      'pager' => $this->pager($total, $per_page, Url::fromRoute('tvshow_core.news')),
      'empty_text' => 'Aucune actualité pour le moment.',
      'feed_url' => Url::fromRoute('tvshow_core.rss')->toString(),
    ]);
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
      'cards' => array_map(fn($term) => $this->presenter->termCard($term), $series),
      'empty_text' => 'Aucune série n’a encore été ajoutée.',
    ]);
  }

  public function wiki(): array {
    $categories = [];
    foreach ($this->repository->terms('category') as $term) {
      $count = $this->repository->countWikiEntries($term->id());
      if ($count) {
        $categories[] = $this->presenter->termCard($term, $count);
      }
    }
    return [
      '#theme' => 'tvshow_wiki',
      '#categories' => $categories,
      '#latest' => $this->presenter->cards($this->repository->wikiEntries(12)),
      '#cache' => self::CACHE,
    ];
  }

  /**
   * Cast: characters with the people who play them, then the rest of the team.
   */
  public function cast(): array {
    $roles = [];
    $listed = [];
    foreach ($this->repository->wikiEntries(NULL, [], 'title') as $entry) {
      $actors = $entry->get('field_actor')->referencedEntities();
      if (!$actors) {
        continue;
      }
      $card = $this->presenter->card($entry);
      $card['image'] = $this->presenter->image($entry, 'field_image', 'tv_poster');
      $card['actors'] = [];
      foreach ($actors as $actor) {
        $card['actors'][] = ['name' => $actor->label(), 'url' => $actor->toUrl()->toString()];
        $listed[$actor->id()] = TRUE;
      }
      $card['weight'] = $entry->getCreatedTime();
      $roles[] = $card;
    }
    usort($roles, fn($a, $b) => $a['weight'] <=> $b['weight'] ?: strcmp($a['title'], $b['title']));
    $crew = array_filter($this->repository->people(), fn($person) => !isset($listed[$person->id()]));
    return [
      '#theme' => 'tvshow_cast',
      '#roles' => $roles,
      '#crew' => $this->presenter->cards(array_values($crew)),
      '#cache' => self::CACHE,
    ];
  }

  public function people(): array {
    return $this->listing([
      'title' => 'Personnalités',
      'layout' => 'people',
      'cards' => $this->presenter->cards($this->repository->people()),
      'empty_text' => 'Aucune personnalité pour le moment.',
    ]);
  }

  public function partners(): array {
    $partners = [];
    foreach ($this->repository->terms('partenaires') as $term) {
      $link = $term->get('field_url')->first();
      $partners[] = [
        'title' => $term->label(),
        'url' => $link ? $link->getUrl()->toString() : NULL,
        'image' => $this->presenter->image($term, 'field_logo', 'tv_logo'),
        'summary' => $this->presenter->summary($term, 'description', 220),
      ];
    }
    return ['#theme' => 'tvshow_partners', '#partners' => $partners, '#cache' => self::CACHE];
  }

  public function search(): array {
    $keys = trim((string) $this->requests->getCurrentRequest()->query->get('q', ''));
    $per_page = 15;
    $total = $keys === '' ? 0 : $this->repository->countSearch($keys);
    return [
      '#theme' => 'tvshow_search',
      '#keys' => $keys,
      '#total' => $total,
      '#cards' => $keys === '' ? [] : $this->presenter->cards($this->repository->search($keys, $per_page, $this->page() * $per_page)),
      '#pager' => $this->pager($total, $per_page, Url::fromRoute('tvshow_core.search', [], ['query' => ['q' => $keys]])),
      '#action' => Url::fromRoute('tvshow_core.search')->toString(),
      '#cache' => self::CACHE,
    ];
  }

  public function notFound(): array {
    return [
      '#theme' => 'tvshow_not_found',
      '#action' => Url::fromRoute('tvshow_core.search')->toString(),
      '#news' => $this->presenter->cards($this->repository->articles(3)),
      '#cache' => self::CACHE,
    ];
  }

  /**
   * RSS 2.0 feed of the latest news.
   */
  public function rss(): CacheableResponse {
    $site = $this->config('system.site');
    $base = $this->requests->getCurrentRequest()->getSchemeAndHttpHost();
    $escape = fn($text) => htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    $xml .= '<title>' . $escape($site->get('name')) . '</title><link>' . $escape($base . '/') . '</link>';
    $xml .= '<description>' . $escape($site->get('slogan') ?: 'Actualités') . '</description><language>fr</language>';
    $xml .= '<atom:link href="' . $escape($base . Url::fromRoute('tvshow_core.rss')->toString()) . '" rel="self" type="application/rss+xml"/>';
    foreach ($this->repository->articles(20) as $node) {
      $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      $xml .= '<item><title>' . $escape($node->label()) . '</title><link>' . $escape($url) . '</link><guid isPermaLink="true">' . $escape($url) . '</guid>';
      $xml .= '<pubDate>' . gmdate(DATE_RSS, $node->getCreatedTime()) . '</pubDate><description>' . $escape($this->presenter->summary($node, 'body', 400)) . '</description></item>';
    }
    $xml .= '</channel></rss>';
    return $this->xml($xml, 'application/rss+xml; charset=utf-8');
  }

  /**
   * XML sitemap of every published page.
   */
  public function sitemap(): CacheableResponse {
    $escape = fn($text) => htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $options = ['absolute' => TRUE];
    foreach (['<front>', 'tvshow_core.news', 'tvshow_core.episodes', 'tvshow_core.wiki', 'tvshow_core.cast', 'tvshow_core.partners'] as $route) {
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
