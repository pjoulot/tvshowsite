<?php

namespace Drupal\tvshow_core;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only queries behind every listing on the site.
 *
 * Listings are plain entity queries (not Views) so each site gets the same
 * pages without any per-site configuration.
 */
class ContentRepository {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Builds a published-node query for one bundle.
   */
  protected function nodeQuery(string $bundle, array $conditions = []) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('type', $bundle);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return $query;
  }

  /**
   * Loads nodes, keeping the order of the ids.
   */
  protected function loadNodes(array $ids): array {
    if (!$ids) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    return array_values(array_filter(array_map(fn($id) => $nodes[$id] ?? NULL, array_values($ids))));
  }

  public function articles(int $limit, int $offset = 0, array $conditions = []): array {
    return $this->loadNodes($this->nodeQuery('article', $conditions)->sort('created', 'DESC')->sort('nid', 'DESC')->range($offset, $limit)->execute());
  }

  public function countArticles(array $conditions = []): int {
    return (int) $this->nodeQuery('article', $conditions)->count()->execute();
  }

  /**
   * Articles promoted to the front page, newest first.
   */
  public function featuredArticles(int $limit): array {
    $nodes = $this->articles($limit, 0, ['promote' => 1]);
    if (count($nodes) < $limit) {
      $have = array_map(fn($n) => $n->id(), $nodes);
      $query = $this->nodeQuery('article')->exists('field_image')->sort('created', 'DESC')->range(0, $limit);
      if ($have) {
        $query->condition('nid', $have, 'NOT IN');
      }
      $nodes = array_slice(array_merge($nodes, $this->loadNodes($query->execute())), 0, $limit);
    }
    return $nodes;
  }

  public function wikiEntries(?int $limit = NULL, array $conditions = [], string $sort = 'changed'): array {
    $query = $this->nodeQuery('editorial', $conditions);
    $sort === 'title' ? $query->sort('title') : $query->sort('changed', 'DESC');
    if ($limit) {
      $query->range(0, $limit);
    }
    return $this->loadNodes($query->execute());
  }

  public function countWikiEntries(int $category_id): int {
    return (int) $this->nodeQuery('editorial', ['field_category' => $category_id])->count()->execute();
  }

  public function people(array $conditions = []): array {
    return $this->loadNodes($this->nodeQuery('people', $conditions)->sort('title')->execute());
  }

  public function episodes(int $season_id): array {
    return $this->loadNodes($this->nodeQuery('episode', ['field_season' => $season_id])->sort('field_episode')->execute());
  }

  /**
   * The episode before and after a given one, within its season.
   */
  public function neighbours($episode): array {
    $season = $episode->get('field_season')->target_id;
    $number = (int) $episode->get('field_episode')->value;
    if (!$season) {
      return [NULL, NULL];
    }
    $previous = $this->loadNodes($this->nodeQuery('episode', ['field_season' => $season])->condition('field_episode', $number, '<')->sort('field_episode', 'DESC')->range(0, 1)->execute());
    $next = $this->loadNodes($this->nodeQuery('episode', ['field_season' => $season])->condition('field_episode', $number, '>')->sort('field_episode')->range(0, 1)->execute());
    return [$previous[0] ?? NULL, $next[0] ?? NULL];
  }

  /**
   * Episodes crediting a person as director or writer.
   */
  public function creditedEpisodes(int $person_id): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('type', 'episode');
    $or = $query->orConditionGroup()->condition('field_director', $person_id)->condition('field_writers', $person_id);
    return $this->loadNodes($query->condition($or)->sort('field_date_de_diffusion')->execute());
  }

  /**
   * Wiki entries (characters) played by a person.
   */
  public function rolesOf(int $person_id): array {
    return $this->wikiEntries(NULL, ['field_actor' => $person_id], 'title');
  }

  public function taggedContent(int $tag_id, int $limit, int $offset = 0): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('field_tags', $tag_id);
    return $this->loadNodes($query->sort('created', 'DESC')->range($offset, $limit)->execute());
  }

  public function countTaggedContent(int $tag_id): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('field_tags', $tag_id)->count()->execute();
  }

  /**
   * Published terms of a vocabulary, in admin order.
   */
  public function terms(string $vocabulary, array $conditions = [], ?string $sort = NULL): array {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('vid', $vocabulary);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    $sort ? $query->sort($sort) : $query->sort('weight')->sort('name');
    $ids = $query->execute();
    return $ids ? array_values($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids)) : [];
  }

  public function seasons(int $serie_id): array {
    return $this->terms('saison', ['field_serie' => $serie_id], 'field_season_number');
  }

  /**
   * Full-text-ish search on titles and body text.
   */
  public function search(string $keys, int $limit, int $offset = 0): array {
    $found = $this->searchApi($keys, $limit, $offset);
    if ($found !== NULL) {
      return $this->loadNodes($found['ids']);
    }
    $query = $this->searchQuery($keys);
    return $query ? $this->loadNodes($query->sort('created', 'DESC')->range($offset, $limit)->execute()) : [];
  }

  public function countSearch(string $keys): int {
    $found = $this->searchApi($keys, 1, 0);
    if ($found !== NULL) {
      return $found['count'];
    }
    $query = $this->searchQuery($keys);
    return $query ? (int) $query->count()->execute() : 0;
  }

  /**
   * Searches through the Search API index when it exists and is filled.
   *
   * @return array|null
   *   Node ids in relevance order and the total, or NULL to fall back on the
   *   plain database search.
   */
  protected function searchApi(string $keys, int $limit, int $offset): ?array {
    if (trim($keys) === '' || !\Drupal::moduleHandler()->moduleExists('search_api')) {
      return NULL;
    }
    try {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load(ContribSetup::SEARCH_INDEX);
      if (!$index || !$index->status() || !$index->getTrackerInstance()->getIndexedItemsCount()) {
        return NULL;
      }
      $query = $index->query();
      $query->keys($keys);
      $query->range($offset, $limit);
      $query->sort('search_api_relevance', 'DESC');
      $query->sort('created', 'DESC');
      $results = $query->execute();
      $ids = [];
      foreach ($results->getResultItems() as $item) {
        if (preg_match('~^entity:node/(\d+):~', $item->getId(), $match)) {
          $ids[] = (int) $match[1];
        }
      }
      // loadMultiple() does not keep the order it is given.
      return ['ids' => $ids, 'count' => (int) $results->getResultCount()];
    }
    catch (\Throwable $e) {
      \Drupal::logger('tvshow_core')->warning('Search API query failed, using the database search: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  protected function searchQuery(string $keys) {
    $words = array_filter(preg_split('/\s+/', trim($keys)), fn($w) => mb_strlen($w) >= 2);
    if (!$words) {
      return NULL;
    }
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1);
    foreach (array_slice($words, 0, 6) as $word) {
      $or = $query->orConditionGroup()->condition('title', $word, 'CONTAINS')->condition('body.value', $word, 'CONTAINS');
      $query->condition($or);
    }
    return $query;
  }

  /**
   * Every published node, for the sitemap.
   */
  public function allNodeIds(): array {
    return $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->sort('changed', 'DESC')->execute();
  }

}
