<?php

namespace Drupal\tvshow_core\Controller;

use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;

/**
 * Pages of taxonomy terms: series, seasons, rubrics and tags.
 */
class TermController extends TvControllerBase {

  public function title(TermInterface $taxonomy_term): string {
    return $taxonomy_term->label();
  }

  public function view(TermInterface $taxonomy_term) {
    $term = $taxonomy_term;
    $build = match ($term->bundle()) {
      'serie' => $this->serie($term),
      'saison' => $this->season($term),
      'category' => $this->wikiCategory($term),
      'article_category' => $this->newsCategory($term),
      'job' => $this->job($term),
      'product_type' => $this->productType($term),
      'partenaires' => NULL,
      default => $this->tag($term),
    };
    if ($build === NULL) {
      return PageController::toPartners();
    }
    $build['#cache']['tags'][] = 'taxonomy_term:' . $term->id();
    return $build;
  }

  protected function header(TermInterface $term): array {
    return [
      'title' => $term->label(),
      'description' => $this->presenter->text($term, 'description'),
      'image' => $this->presenter->image($term, 'field_image', 'tv_poster'),
      'dates' => $term->hasField('field_dates') ? $term->get('field_dates')->value : NULL,
      'creators' => $term->hasField('field_creators') ? $term->get('field_creators')->value : NULL,
      'stores' => $this->presenter->storeLinks($term),
      'facts' => $term->hasField('field_facts') ? $this->presenter->facts($term) : [],
      'picture' => $this->presenter->thumb($term, 'field_image', 'tv_content', 900),
      'url' => $term->toUrl()->toString(),
    ];
  }

  protected function serie(TermInterface $term): array {
    $seasons = [];
    $season_terms = $this->repository->seasons($term->id());
    $show_episodes = count($season_terms) <= 3;
    $episode_total = 0;
    foreach ($season_terms as $season) {
      $episode_total += $this->repository->countEpisodes((int) $season->id());
      $seasons[] = [
        'title' => $season->label(),
        'url' => $season->toUrl()->toString(),
        'anchor' => 'saison-' . ($season->get('field_season_number')->value ?: $season->id()),
        'episodes' => $show_episodes ? $this->embed('tvshow_episodes', 'season', $season->id()) : [],
      ];
    }
    $abbreviation = (string) $term->get('field_abreviation')->value;
    $regular = array_filter($season_terms, fn($season) => (int) $season->get('field_season_number')->value < 90);
    $films = array_filter($season_terms, fn($season) => (int) $season->get('field_season_number')->value >= 90);
    $explore = [];
    if ($regular) {
      $first = reset($regular);
      $regular_total = array_sum(array_map(fn($season) => $this->repository->countEpisodes((int) $season->id()), $regular));
      $explore[] = ['title' => 'Le guide des épisodes', 'text' => count($regular) . ' ' . (count($regular) > 1 ? 'saisons' : 'saison') . ', ' . $regular_total . ' ' . ($regular_total > 1 ? 'épisodes' : 'épisode'), 'url' => $first->toUrl()->toString()];
    }
    foreach ($films as $season) {
      $count = $this->repository->countEpisodes((int) $season->id());
      $explore[] = ['title' => $season->label(), 'text' => $count . ' ' . ($count > 1 ? 'films' : 'film'), 'url' => $season->toUrl()->toString()];
    }
    $actors = $this->actorsTerm();
    $actor_count = $actors ? count($this->repository->people(['field_job' => $actors->id(), 'field_series' => $term->id()])) : 0;
    if ($actor_count) {
      $explore[] = ['title' => 'Les acteurs', 'text' => $actor_count . ' ' . ($actor_count > 1 ? 'fiches' : 'fiche'), 'url' => Url::fromRoute('tvshow_core.actors', [], ['query' => ['serie' => $abbreviation]])->toString()];
    }
    $wiki_count = count($this->repository->wikiEntries(NULL, ['field_serie' => $term->id()]));
    if ($wiki_count) {
      $explore[] = ['title' => $this->presenter->wikiLabel(), 'text' => $wiki_count . ' fiches : personnages, peuples, technologies…', 'url' => Url::fromRoute('tvshow_core.wiki')->toString()];
    }
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    foreach ($storage->loadTree('product_type', 0, 1, TRUE) as $root) {
      $types = array_merge([$root->id()], array_keys($storage->loadChildren($root->id(), 'product_type')));
      $products = count($this->repository->products(NULL, ['field_series' => $term->id(), 'field_product_type' => $types]));
      if ($products) {
        $explore[] = ['title' => $root->label(), 'text' => $products . ' ' . ($products > 1 ? 'fiches' : 'fiche'), 'url' => $root->toUrl()->toString() . '?serie=' . $abbreviation];
      }
    }
    $season_cards = [];
    foreach ($season_terms as $season) {
      $season_cards[] = $this->presenter->termCard($season, $this->repository->countEpisodes((int) $season->id()));
    }
    return [
      '#theme' => 'tvshow_serie',
      '#serie' => $this->header($term) + ['episode_total' => $episode_total, 'season_count' => count($regular), 'abbreviation' => $abbreviation],
      '#seasons' => $seasons,
      '#season_list' => $show_episodes ? [] : $this->embed('tvshow_terms', 'seasons', $term->id()),
      '#show_episodes' => $show_episodes,
      '#explore' => $explore,
      '#season_cards' => $season_cards,
      '#cache' => self::CACHE,
    ];
  }

  /**
   * The job term of actors: "Acteur", or "Interprète" on older packs.
   */
  public function actorsTerm() {
    foreach (['Acteur', 'Interprète'] as $name) {
      foreach ($this->repository->terms('job') as $term) {
        if ($term->label() === $name) {
          return $term;
        }
      }
    }
    return NULL;
  }

  protected function season(TermInterface $term): array {
    $serie = $term->get('field_serie')->entity;
    $siblings = [];
    if ($serie) {
      foreach ($this->repository->seasons($serie->id()) as $season) {
        $siblings[] = ['label' => $season->label(), 'url' => $season->toUrl()->toString(), 'active' => $season->id() === $term->id()];
      }
    }
    return [
      '#theme' => 'tvshow_season',
      '#season' => $this->header($term) + ['episode_total' => $this->repository->countEpisodes((int) $term->id())],
      '#serie' => $this->presenter->termLink($serie),
      '#siblings' => $siblings,
      '#episodes' => $this->embed('tvshow_episodes', 'season', $term->id()),
      '#cache' => self::CACHE,
    ];
  }

  protected function wikiCategory(TermInterface $term): array {
    $label = $this->presenter->wikiLabel();
    $all = $label === 'Wiki' ? 'Tout le wiki' : (preg_match('/^[aeiouyéèêàâîôûAEIOUYÉ]/u', $label) ? 'Toute l’' . mb_strtolower($label) : 'Tout : ' . mb_strtolower($label));
    $filters = [['label' => $all, 'url' => Url::fromRoute('tvshow_core.wiki')->toString(), 'active' => FALSE]];
    foreach ($this->repository->terms('category') as $category) {
      if ($this->repository->countWikiEntries($category->id())) {
        $filters[] = ['label' => $category->label(), 'url' => $category->toUrl()->toString(), 'active' => $category->id() === $term->id()];
      }
    }
    if (($actors = $this->actorsTerm()) && $this->repository->people(['field_job' => $actors->id()])) {
      $filters[] = ['label' => 'Acteurs', 'url' => Url::fromRoute('tvshow_core.actors')->toString(), 'active' => FALSE];
    }
    $base = $term->toUrl()->toString();
    [$serie_filters, $serie] = $this->serieFilter($base, $this->repository->seriesWith('editorial', 'field_serie', ['field_category' => $term->id()]));
    $conditions = ['field_category' => $term->id()] + ($serie ? ['field_serie' => $serie->id()] : []);
    $initials = $this->repository->initials('editorial', $conditions);
    $letter = mb_strtolower((string) $this->requests->getCurrentRequest()->query->get('lettre', ''));
    $letter = preg_match('/^[a-z0-9]$/', $letter) ? $letter : NULL;
    $letters = [];
    if (count($initials) > 1 || $letter) {
      $query = $serie ? ['serie' => $serie->get('field_abreviation')->value] : [];
      $letters[] = ['label' => 'Tout', 'url' => $base . ($query ? '?' . http_build_query($query) : ''), 'active' => !$letter, 'count' => array_sum($initials)];
      foreach (range('a', 'z') as $candidate) {
        $letters[] = [
          'label' => strtoupper($candidate),
          'url' => isset($initials[$candidate]) ? $base . '?' . http_build_query($query + ['lettre' => $candidate]) : NULL,
          'active' => $letter === $candidate,
          'count' => $initials[$candidate] ?? 0,
        ];
      }
    }
    $count = $letter ? ($initials[$letter] ?? 0) : array_sum($initials);
    return $this->listing([
      'title' => $term->label(),
      'kicker' => $label,
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'wiki',
      'filters' => $filters,
      'serie_filters' => $serie_filters,
      'letters' => $letters,
      'letter' => $letter ? strtoupper($letter) : NULL,
      'count' => $count,
      'breadcrumb' => [['name' => $label, 'url' => Url::fromRoute('tvshow_core.wiki')->toString()]],
      'content' => $this->embed('tvshow_wiki', 'category', $term->id(), $serie ? $serie->id() : 'all', $letter ?? 'all'),
    ]);
  }

  /**
   * A product type: its products and those of its sub-types.
   */
  protected function productType(TermInterface $term): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $parents = array_reverse($storage->loadAllParents($term->id()));
    $root = $parents[0] ?? $term;
    $filters = [['label' => 'Tout', 'url' => $root->toUrl()->toString(), 'active' => $root->id() === $term->id()]];
    foreach ($storage->loadChildren($root->id(), 'product_type') as $child) {
      $filters[] = ['label' => $child->label(), 'url' => $child->toUrl()->toString(), 'active' => $child->id() === $term->id()];
    }
    $types = array_merge([$term->id()], array_keys($storage->loadChildren($term->id(), 'product_type')));
    [$serie_filters, $serie] = $this->serieFilter($term->toUrl()->toString(), $this->repository->seriesWith('product', 'field_series', ['field_product_type' => $types]));
    $crumbs = [];
    foreach (array_slice($parents, 0, -1) as $parent) {
      $crumbs[] = ['name' => $parent->label(), 'url' => $parent->toUrl()->toString()];
    }
    return $this->listing([
      'title' => $term->label(),
      'kicker' => $root->id() === $term->id() ? NULL : $root->label(),
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'products',
      'filters' => count($filters) > 1 ? $filters : [],
      'serie_filters' => $serie_filters,
      'breadcrumb' => $crumbs,
      'content' => $this->embed('tvshow_products', 'type', $term->id(), $serie ? $serie->id() : 'all'),
    ]);
  }

  protected function newsCategory(TermInterface $term): array {
    $filters = [['label' => 'Toutes', 'url' => Url::fromRoute('view.tvshow_news.page')->toString(), 'active' => FALSE]];
    foreach ($this->repository->terms('article_category') as $category) {
      $filters[] = ['label' => $category->label(), 'url' => $category->toUrl()->toString(), 'active' => $category->id() === $term->id()];
    }
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Actualités',
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'news',
      'filters' => $filters,
      'content' => $this->embed('tvshow_news', 'category', $term->id()),
    ]);
  }

  protected function tag(TermInterface $term): array {
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Tag',
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'news',
      'content' => $this->embed('tvshow_tagged', 'tag', $term->id()),
    ]);
  }

  protected function job(TermInterface $term, ?string $title = NULL, ?string $base_url = NULL): array {
    $base_url ??= $term->toUrl()->toString();
    [$serie_filters, $serie] = $this->serieFilter($base_url, $this->repository->seriesWith('people', 'field_series', ['field_job' => $term->id()]));
    return $this->listing([
      'title' => ($title ?? $term->label()) . ($serie ? ' — ' . $serie->label() : ''),
      'kicker' => $title ? $this->presenter->wikiLabel() : 'Personnalités',
      'layout' => 'people',
      'serie_filters' => $serie_filters,
      'breadcrumb' => $title ? [['name' => $this->presenter->wikiLabel(), 'url' => Url::fromRoute('tvshow_core.wiki')->toString()]] : [],
      'content' => $this->embed('tvshow_people', 'job', $term->id(), $serie ? $serie->id() : 'all'),
    ]);
  }

  /**
   * /acteurs: the people with the actor job.
   */
  public function actors(): array {
    $term = $this->actorsTerm();
    if (!$term) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $build = $this->job($term, 'Acteurs', Url::fromRoute('tvshow_core.actors')->toString());
    $build['#cache']['tags'][] = 'taxonomy_term:' . $term->id();
    return $build;
  }

}
