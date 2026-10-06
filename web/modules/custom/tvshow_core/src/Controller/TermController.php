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
    return [
      '#theme' => 'tvshow_serie',
      '#serie' => $this->header($term) + ['episode_total' => $episode_total],
      '#seasons' => $seasons,
      '#season_list' => $show_episodes ? [] : $this->embed('tvshow_terms', 'seasons', $term->id()),
      '#show_episodes' => $show_episodes,
      '#cache' => self::CACHE,
    ];
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
    $filters = [['label' => 'Tout le wiki', 'url' => Url::fromRoute('tvshow_core.wiki')->toString(), 'active' => FALSE]];
    foreach ($this->repository->terms('category') as $category) {
      if ($this->repository->countWikiEntries($category->id())) {
        $filters[] = ['label' => $category->label(), 'url' => $category->toUrl()->toString(), 'active' => $category->id() === $term->id()];
      }
    }
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Wiki',
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'wiki',
      'filters' => $filters,
      'content' => $this->embed('tvshow_wiki', 'category', $term->id()),
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

  protected function job(TermInterface $term): array {
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Personnalités',
      'layout' => 'people',
      'content' => $this->embed('tvshow_people', 'job', $term->id()),
    ]);
  }

}
