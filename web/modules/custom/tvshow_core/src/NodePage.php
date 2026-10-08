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
    $body = $node->get('body')->first();
    $wide = (int) ($node->get('field_image')->width ?? 0) >= 300;
    return $data + [
      'summary' => $body && trim((string) $body->summary) !== '' ? trim((string) $body->summary) : NULL,
      // The thumbnail opens the text, unless the text has its own pictures.
      'thumb' => !$wide && !str_contains((string) $body?->value, '<img') ? $this->presenter->thumb($node, 'field_image') : NULL,
      'date' => $this->presenter->date($node->getCreatedTime()),
      'date_iso' => $this->presenter->isoDate($node->getCreatedTime()),
      'category' => $this->presenter->termLink($category),
      'byline' => $node->get('field_byline')->value,
      // A thumbnail-sized picture is fine on cards, not stretched across the page.
      'image' => (int) ($node->get('field_image')->width ?? 0) >= 300 ? $this->presenter->image($node, 'field_image', 'tv_wide') : NULL,
      'tags' => $this->presenter->references($node, 'field_tags'),
      'source' => $source ? ['url' => $source->getUrl()->toString(), 'title' => $source->title ?: parse_url($source->getUrl()->toString(), PHP_URL_HOST)] : NULL,
      'related' => $this->presenter->cards(array_slice(array_values($related), 0, 4)),
    ];
  }

  protected function buildEditorial(NodeInterface $node, array $data): array {
    $category = $node->get('field_category')->entity;
    $related = [];
    if ($category) {
      $related = array_filter($this->repository->wikiEntries(7, ['field_category' => $category->id()], 'title'), fn($other) => $other->id() !== $node->id());
    }
    $appearance = [];
    foreach ($node->get('field_appearance')->referencedEntities() as $episode) {
      if ($episode->access('view')) {
        $code = $this->presenter->episodeCode($episode);
        $appearance[] = ['name' => ($code ? $code . ' ' : '') . $episode->label(), 'url' => $episode->toUrl()->toString()];
      }
    }
    return $data + [
      'category' => $this->presenter->termLink($category),
      'serie' => $this->presenter->termLink($node->get('field_serie')->entity),
      'serie_short' => $this->presenter->shortName($node->get('field_serie')->entity),
      'wiki_label' => $this->presenter->wikiLabel(),
      'facts_list' => $this->presenter->facts($node),
      'appearance' => $appearance,
      'picture' => $this->presenter->thumb($node, 'field_image', 'tv_poster', 480),
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
    $facts = array_filter([
      'Titre original' => $node->get('field_original_title')->value,
      'Première diffusion' => $this->presenter->date($node->get('field_date_de_diffusion')->value),
      'Durée' => $this->presenter->duration((int) $node->get('field_duration')->value),
      'Audience' => $node->get('field_audience')->value,
    ]);
    $season_list = [];
    if ($season) {
      foreach ($this->repository->episodes((int) $season->id()) as $sibling) {
        $season_list[] = [
          'code' => $this->presenter->episodeCode($sibling),
          'number' => $sibling->get('field_episode')->value,
          'title' => $sibling->label(),
          'url' => $sibling->toUrl()->toString(),
          'active' => $sibling->id() === $node->id(),
        ];
      }
    }
    $season_number = (int) $season?->get('field_season_number')->value;
    return $data + [
      'code' => $this->presenter->episodeCode($node),
      'is_film' => $season_number >= 90,
      'season_list' => $season_list,
      'extra_facts' => $node->hasField('field_facts') ? $this->presenter->facts($node) : [],
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
      'facts_list' => $this->presenter->facts($node),
      'series' => $node->hasField('field_series') ? $this->presenter->references($node, 'field_series') : [],
      'wiki_label' => $this->presenter->wikiLabel(),
      'picture' => $this->presenter->thumb($node, 'field_picture', 'tv_poster', 480),
      'image' => $this->presenter->image($node, 'field_picture', 'tv_poster'),
      'jobs' => $this->presenter->references($node, 'field_job'),
      'roles' => $this->presenter->cards($this->repository->rolesOf((int) $node->id())),
      'episodes' => $this->presenter->cards($this->repository->creditedEpisodes((int) $node->id())),
    ];
  }

  protected function buildProduct(NodeInterface $node, array $data): array {
    $type = $node->get('field_product_type')->entity;
    $parents = $type ? array_reverse(\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadAllParents($type->id())) : [];
    $series = $node->get('field_series')->referencedEntities();
    // A box set of one season leads to that season's episodes.
    $season = NULL;
    if (count($series) === 1 && preg_match('/\bsaisons?\s+(\d+)\b/iu', $node->label(), $m) && !preg_match('/saisons?\s+\d+\s+(and|et|&)\s+\d+/iu', $node->label())) {
      foreach ($this->repository->seasons((int) $series[0]->id()) as $candidate) {
        if ((int) $candidate->get('field_season_number')->value === (int) $m[1]) {
          $season = ['name' => $candidate->label(), 'url' => $candidate->toUrl()->toString()];
        }
      }
    }
    $related = [];
    if ($type) {
      $related = array_filter($this->repository->products(5, ['field_product_type' => $type->id()]), fn($other) => $other->id() !== $node->id());
    }
    return $data + [
      'type' => $this->presenter->termLink($type),
      'trail' => array_map(fn($term) => $this->presenter->termLink($term), $parents),
      'series' => $this->presenter->references($node, 'field_series'),
      'picture' => $this->presenter->thumb($node, 'field_image', 'tv_poster', 480),
      'image' => $this->presenter->image($node, 'field_image', 'tv_poster'),
      'facts_list' => $this->presenter->facts($node),
      'stores' => $this->presenter->storeLinks($node),
      'season' => $season,
      'related' => $this->presenter->cards(array_slice(array_values($related), 0, 4)),
    ];
  }

  protected function buildPage(NodeInterface $node, array $data): array {
    return $data + ['image' => $this->presenter->image($node, 'field_image', 'tv_wide')];
  }

}
