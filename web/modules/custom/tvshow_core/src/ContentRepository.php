<?php

namespace Drupal\tvshow_core;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only helper queries.
 *
 * Listings themselves are Views (tvshow_* views); this class only answers the
 * small questions pages and the importer ask: counts, neighbours, credits.
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
      is_array($value) ? $query->condition($field, $value ?: [0], 'IN') : $query->condition($field, $value);
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

  public function products(?int $limit = NULL, array $conditions = []): array {
    $query = $this->nodeQuery('product', $conditions)->sort('title');
    if ($limit) {
      $query->range(0, $limit);
    }
    return $this->loadNodes($query->execute());
  }

  /**
   * Published nodes of a bundle that reference each series, by series id.
   *
   * Used to show only the series filters that lead somewhere.
   */
  public function seriesWith(string $bundle, string $field, array $conditions = []): array {
    $counts = [];
    foreach ($this->terms('serie') as $serie) {
      $count = (int) $this->nodeQuery($bundle, $conditions + [$field => $serie->id()])->count()->execute();
      if ($count) {
        $counts[$serie->id()] = $count;
      }
    }
    return $counts;
  }

  /**
   * First letters of the titles of matching nodes, with their counts.
   */
  public function initials(string $bundle, array $conditions = []): array {
    $letters = [];
    foreach (array_chunk($this->nodeQuery($bundle, $conditions)->execute(), 200) as $ids) {
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
        $letter = mb_strtolower(mb_substr(\Drupal::service('transliteration')->transliterate($node->label(), 'fr'), 0, 1));
        $letters[$letter] = ($letters[$letter] ?? 0) + 1;
      }
    }
    ksort($letters);
    return $letters;
  }

  public function countEpisodes(int $season_id): int {
    return (int) $this->nodeQuery('episode', ['field_season' => $season_id])->count()->execute();
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
   * Every published node, for the sitemap.
   */
  public function allNodeIds(): array {
    return $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->sort('changed', 'DESC')->execute();
  }

}
