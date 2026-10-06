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
      $episodes = $this->repository->episodes($season->id());
      $episode_total += count($episodes);
      $seasons[] = $this->presenter->termCard($season, count($episodes)) + [
        'episodes' => $show_episodes ? $this->presenter->cards($episodes) : [],
        'anchor' => 'saison-' . ($season->get('field_season_number')->value ?: $season->id()),
      ];
    }
    return [
      '#theme' => 'tvshow_serie',
      '#serie' => $this->header($term) + ['episode_total' => $episode_total],
      '#seasons' => $seasons,
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
      '#season' => $this->header($term),
      '#serie' => $this->presenter->termLink($serie),
      '#siblings' => $siblings,
      '#episodes' => $this->presenter->cards($this->repository->episodes($term->id())),
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
      'cards' => $this->presenter->cards($this->repository->wikiEntries(NULL, ['field_category' => $term->id()], 'title')),
      'empty_text' => 'Aucune fiche dans cette rubrique pour le moment.',
    ]);
  }

  protected function newsCategory(TermInterface $term): array {
    $per_page = 12;
    $conditions = ['field_article_category' => $term->id()];
    $filters = [['label' => 'Toutes', 'url' => Url::fromRoute('tvshow_core.news')->toString(), 'active' => FALSE]];
    foreach ($this->repository->terms('article_category') as $category) {
      $filters[] = ['label' => $category->label(), 'url' => $category->toUrl()->toString(), 'active' => $category->id() === $term->id()];
    }
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Actualités',
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'news',
      'filters' => $filters,
      'cards' => $this->presenter->cards($this->repository->articles($per_page, $this->page() * $per_page, $conditions)),
      'pager' => $this->pager($this->repository->countArticles($conditions), $per_page, $term->toUrl()),
      'empty_text' => 'Aucune actualité dans cette rubrique pour le moment.',
    ]);
  }

  protected function tag(TermInterface $term): array {
    $per_page = 12;
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Tag',
      'intro' => $this->presenter->text($term, 'description'),
      'layout' => 'news',
      'cards' => $this->presenter->cards($this->repository->taggedContent($term->id(), $per_page, $this->page() * $per_page)),
      'pager' => $this->pager($this->repository->countTaggedContent($term->id()), $per_page, $term->toUrl()),
      'empty_text' => 'Aucun contenu avec ce tag pour le moment.',
    ]);
  }

  protected function job(TermInterface $term): array {
    return $this->listing([
      'title' => $term->label(),
      'kicker' => 'Personnalités',
      'layout' => 'people',
      'cards' => $this->presenter->cards($this->repository->people(['field_job' => $term->id()])),
      'empty_text' => 'Aucune personnalité pour le moment.',
    ]);
  }

}
