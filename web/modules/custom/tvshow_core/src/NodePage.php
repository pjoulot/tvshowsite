<?php

namespace Drupal\tvshow_core;

use Drupal\node\NodeInterface;

/**
 * Builds the data of each content type's full page.
 */
class NodePage {

  public function __construct(protected ContentRepository $repository, protected Presenter $presenter) {}

  public function build(NodeInterface $node): array {
    $data = [
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'body' => $this->presenter->text($node, 'body'),
      'gallery' => $this->presenter->gallery($node, 'field_gallery'),
    ];
    $method = 'build' . ucfirst($node->bundle());
    return method_exists($this, $method) ? $this->$method($node, $data) : $data;
  }

  protected function buildArticle(NodeInterface $node, array $data): array {
    $category = $node->get('field_article_category')->entity;
    $related = [];
    if ($category) {
      $related = array_filter($this->repository->articles(4, 0, ['field_article_category' => $category->id()]), fn($other) => $other->id() !== $node->id());
    }
    $source = $node->get('field_source')->first();
    return $data + [
      'date' => $this->presenter->date($node->getCreatedTime()),
      'date_iso' => gmdate('Y-m-d', $node->getCreatedTime()),
      'category' => $this->presenter->termLink($category),
      'byline' => $node->get('field_byline')->value,
      'image' => $this->presenter->image($node, 'field_image', 'tv_wide'),
      'tags' => $this->presenter->references($node, 'field_tags'),
      'source' => $source ? ['url' => $source->getUrl()->toString(), 'title' => $source->title ?: parse_url($source->getUrl()->toString(), PHP_URL_HOST)] : NULL,
      'related' => $this->presenter->cards(array_slice(array_values($related), 0, 3)),
    ];
  }

  protected function buildEditorial(NodeInterface $node, array $data): array {
    $category = $node->get('field_category')->entity;
    $related = [];
    if ($category) {
      $related = array_filter($this->repository->wikiEntries(7, ['field_category' => $category->id()], 'title'), fn($other) => $other->id() !== $node->id());
    }
    return $data + [
      'category' => $this->presenter->termLink($category),
      'serie' => $this->presenter->termLink($node->get('field_serie')->entity),
      'image' => $this->presenter->image($node, 'field_image', 'tv_poster'),
      'actors' => $this->presenter->references($node, 'field_actor'),
      'tags' => $this->presenter->references($node, 'field_tags'),
      'related' => $this->presenter->cards(array_slice(array_values($related), 0, 6)),
    ];
  }

  protected function buildEpisode(NodeInterface $node, array $data): array {
    $season = $node->get('field_season')->entity;
    $serie = $season?->get('field_serie')->entity;
    [$previous, $next] = $this->repository->neighbours($node);
    $duration = $node->get('field_duration')->value;
    $facts = array_filter([
      'Titre original' => $node->get('field_original_title')->value,
      'Première diffusion' => $this->presenter->date($node->get('field_date_de_diffusion')->value),
      'Durée' => $duration ? $duration . ' min' : NULL,
      'Audience' => $node->get('field_audience')->value,
    ]);
    return $data + [
      'number' => $node->get('field_episode')->value,
      'season' => $this->presenter->termLink($season),
      'season_number' => $season?->get('field_season_number')->value,
      'serie' => $this->presenter->termLink($serie),
      'image' => $this->presenter->image($node, 'field_image', 'tv_wide'),
      'facts' => $facts,
      'directors' => $this->presenter->references($node, 'field_director'),
      'writers' => $this->presenter->references($node, 'field_writers'),
      'guest_stars' => $node->get('field_guest_stars')->value,
      'synopsis' => $this->presenter->text($node, 'field_synopsis'),
      'promo' => $this->presenter->gallery($node, 'field_promotional_pictures'),
      'trailers' => $this->presenter->videos($node, 'field_trailers'),
      'stores' => $this->presenter->storeLinks($node),
      'linked' => $this->presenter->cards($node->get('field_linked_content')->referencedEntities()),
      'previous' => $previous ? $this->presenter->card($previous) : NULL,
      'next' => $next ? $this->presenter->card($next) : NULL,
    ];
  }

  protected function buildPeople(NodeInterface $node, array $data): array {
    return $data + [
      'image' => $this->presenter->image($node, 'field_picture', 'tv_poster'),
      'jobs' => $this->presenter->references($node, 'field_job'),
      'roles' => $this->presenter->cards($this->repository->rolesOf((int) $node->id())),
      'episodes' => $this->presenter->cards($this->repository->creditedEpisodes((int) $node->id())),
    ];
  }

  protected function buildPage(NodeInterface $node, array $data): array {
    return $data + ['image' => $this->presenter->image($node, 'field_image', 'tv_wide')];
  }

}
