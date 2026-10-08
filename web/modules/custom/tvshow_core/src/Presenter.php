<?php

namespace Drupal\tvshow_core;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Turns entities into the plain arrays the templates print.
 */
class Presenter {

  const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

  const STORES = [
    'amazon' => 'Amazon',
    'fnac' => 'Fnac',
    'apple' => 'Apple TV',
    'itunes' => 'Apple TV',
    'play.google' => 'Google Play',
    'primevideo' => 'Prime Video',
    'netflix' => 'Netflix',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * "17 décembre 2010" from a timestamp or a Y-m-d string.
   */
  public function date(int|string|null $value): ?string {
    if (!$value) {
      return NULL;
    }
    if (is_numeric($value)) {
      // A moment: the day it was in the site's time zone.
      [$day, $month, $year] = explode(' ', \Drupal::service('date.formatter')->format((int) $value, 'custom', 'j n Y'));
    }
    else {
      // A calendar date ("2005-02-11").
      $time = strtotime($value . ' 12:00:00 UTC');
      [$day, $month, $year] = [gmdate('j', $time), gmdate('n', $time), gmdate('Y', $time)];
    }
    return ((int) $day === 1 ? '1er' : $day) . ' ' . self::MONTHS[(int) $month] . ' ' . $year;
  }

  /**
   * "2005-02-11" of a timestamp, in the site's time zone.
   */
  public function isoDate(int $time): string {
    return \Drupal::service('date.formatter')->format($time, 'custom', 'Y-m-d');
  }

  /**
   * One image of an entity through an image style.
   */
  public function image(?ContentEntityInterface $entity, string $field, string $style, int $delta = 0): ?array {
    if (!$entity || !$entity->hasField($field) || !isset($entity->get($field)[$delta])) {
      return NULL;
    }
    $item = $entity->get($field)[$delta];
    $file = $item->entity;
    if (!$file) {
      return NULL;
    }
    $image_style = $this->entityTypeManager->getStorage('image_style')->load($style);
    $uri = $file->getFileUri();
    $width = (int) $item->width;
    $height = (int) $item->height;
    $dimensions = ['width' => $width, 'height' => $height];
    if ($image_style) {
      $image_style->transformDimensions($dimensions, $uri);
    }
    return [
      'src' => $image_style ? $this->fileUrlGenerator->transformRelative($image_style->buildUrl($uri)) : $this->fileUrlGenerator->generateString($uri),
      'alt' => (string) $item->alt,
      'width' => $dimensions['width'] ?: NULL,
      'height' => $dimensions['height'] ?: NULL,
    ];
  }

  /**
   * A small picture: the file itself when it is already small (the old
   * sites' 150 px thumbnails), else the given image style.
   */
  public function thumb(?ContentEntityInterface $entity, string $field, string $style = 'tv_thumb', int $max = 320): ?array {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $width = (int) $entity->get($field)->first()->width;
    if ($width && $width <= $max) {
      $file = $entity->get($field)->entity;
      if ($file) {
        $item = $entity->get($field)->first();
        return [
          'src' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          'alt' => (string) $item->alt,
          'width' => $width,
          'height' => (int) $item->height ?: NULL,
        ];
      }
    }
    return $this->image($entity, $field, $style);
  }

  /**
   * Lines "Label : value" of a facts field, as label/value pairs.
   */
  public function facts(?ContentEntityInterface $entity, string $field = 'field_facts'): array {
    if (!$entity || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return [];
    }
    $facts = [];
    foreach (preg_split('/\R/u', (string) $entity->get($field)->value) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = preg_split('/\s*:\s*/u', $line, 2);
      $facts[] = count($parts) === 2 ? ['label' => $parts[0], 'value' => $parts[1]] : ['label' => '', 'value' => $line];
    }
    return $facts;
  }

  /**
   * "1 h 24 min" from a number of minutes.
   */
  public function duration(?int $minutes): ?string {
    if (!$minutes) {
      return NULL;
    }
    return $minutes >= 60 ? sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60) : $minutes . ' min';
  }

  /**
   * "1.04" for the 4th episode of season 1; NULL for TV films.
   */
  public function episodeCode(NodeInterface $episode): ?string {
    $season = $episode->get('field_season')->entity;
    $number = (int) $episode->get('field_episode')->value;
    $season_number = (int) $season?->get('field_season_number')->value;
    if (!$season_number || $season_number >= 90) {
      return NULL;
    }
    return sprintf('%d.%02d', $season_number, $number);
  }

  /**
   * Short name of a series: "Atlantis" for "Stargate Atlantis".
   */
  public function shortName(?TermInterface $serie): ?string {
    if (!$serie) {
      return NULL;
    }
    $words = explode(' ', $serie->label());
    return count($words) > 1 ? implode(' ', array_slice($words, 1)) : $serie->label();
  }

  /**
   * Name of the wiki on this site ("Wiki", "Encyclopédie"…).
   */
  public function wikiLabel(): string {
    return (string) ($this->configFactory->get('tvshow_core.settings')->get('wiki_label') ?: 'Wiki');
  }

  /**
   * Every image of a multi-value field as thumbnail + full-size pairs.
   */
  public function gallery(ContentEntityInterface $entity, string $field): array {
    $items = [];
    if ($entity->hasField($field)) {
      foreach ($entity->get($field) as $delta => $item) {
        $thumb = $this->image($entity, $field, 'tv_gallery', $delta);
        $full = $this->image($entity, $field, 'tv_full', $delta);
        if ($thumb && $full) {
          $items[] = ['thumb' => $thumb, 'full' => $full];
        }
      }
    }
    return $items;
  }

  /**
   * Plain-text teaser of a text field.
   */
  public function summary(ContentEntityInterface $entity, string $field = 'body', int $length = 190): string {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '';
    }
    $item = $entity->get($field)->first();
    $text = trim((string) ($item->summary ?? '')) ?: (string) $item->value;
    // Section titles ("Description", "Biographie") are not part of a summary.
    $text = preg_replace('~<h[1-6]\b[^>]*>.*?</h[1-6]>~is', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br'], [' </p>', ' <br'], $text)), ENT_QUOTES | ENT_HTML5));
    return Unicode::truncate(trim($text), $length, TRUE, TRUE);
  }

  /**
   * Filtered HTML of a formatted text field.
   */
  public function text(ContentEntityInterface $entity, string $field): ?array {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $item = $entity->get($field)->first();
    if (trim(strip_tags((string) $item->value, '<img><div>')) === '') {
      return NULL;
    }
    return ['#type' => 'processed_text', '#text' => $item->value, '#format' => $item->format ?: 'basic_html'];
  }

  public function termLink(?TermInterface $term): ?array {
    return $term ? ['name' => $term->label(), 'url' => $term->toUrl()->toString(), 'id' => $term->id()] : NULL;
  }

  /**
   * Links of every referenced, published entity of a field.
   */
  public function references(ContentEntityInterface $entity, string $field): array {
    $links = [];
    if ($entity->hasField($field)) {
      foreach ($entity->get($field)->referencedEntities() as $target) {
        if ($target->access('view')) {
          $links[] = ['name' => $target->label(), 'url' => $target->toUrl()->toString(), 'id' => $target->id()];
        }
      }
    }
    return $links;
  }

  /**
   * Card data for any node, by bundle.
   */
  public function card(NodeInterface $node): array {
    $card = [
      'type' => $node->bundle(),
      'id' => $node->id(),
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
    ];
    switch ($node->bundle()) {
      case 'article':
        $card += [
          'date' => $this->date($node->getCreatedTime()),
          'date_iso' => $this->isoDate($node->getCreatedTime()),
          'category' => $this->termLink($node->get('field_article_category')->entity),
          'image' => $this->image($node, 'field_image', 'tv_card'),
          'image_wide' => $this->image($node, 'field_image', 'tv_wide'),
          'thumb' => $this->thumb($node, 'field_image'),
          'summary' => $this->summary($node),
          'kicker' => 'Actualité',
        ];
        break;

      case 'editorial':
        $card += [
          'category' => $this->termLink($node->get('field_category')->entity),
          'serie' => $this->termLink($node->get('field_serie')->entity),
          'serie_short' => $this->shortName($node->get('field_serie')->entity),
          'image' => $this->image($node, 'field_image', 'tv_square'),
          'thumb' => $this->thumb($node, 'field_image', 'tv_square'),
          'summary' => $this->summary($node, 'body', 140),
          'kicker' => $this->wikiLabel(),
        ];
        break;

      case 'episode':
        $season = $node->get('field_season')->entity;
        $serie = $season?->get('field_serie')->entity;
        $teaser = trim((string) $node->get('field_meta_description')->value);
        $card += [
          'number' => $node->get('field_episode')->value,
          'code' => $this->episodeCode($node),
          'season' => $this->termLink($season),
          'season_number' => $season?->get('field_season_number')->value,
          'serie' => $this->termLink($serie),
          'original_title' => $node->get('field_original_title')->value,
          'date' => $this->date($node->get('field_date_de_diffusion')->value),
          'image' => $this->image($node, 'field_image', 'tv_card'),
          'thumb' => $this->image($node, 'field_image', 'tv_thumb'),
          'summary' => $this->summary($node, 'field_synopsis', 150),
          'teaser' => $teaser !== '' ? Unicode::truncate($teaser, 220, TRUE, TRUE) : $this->summary($node, 'field_synopsis', 220),
          'kicker' => 'Épisode',
        ];
        break;

      case 'people':
        $card += [
          'image' => $this->image($node, 'field_picture', 'tv_square'),
          'thumb' => $this->thumb($node, 'field_picture', 'tv_square'),
          'jobs' => $this->references($node, 'field_job'),
          'series' => $node->hasField('field_series') ? $this->references($node, 'field_series') : [],
          'summary' => $this->summary($node, 'body', 140),
          'kicker' => 'Personnalité',
        ];
        break;

      case 'product':
        $type = $node->get('field_product_type')->entity;
        $subtitle = NULL;
        foreach ($this->facts($node) as $fact) {
          if (in_array(mb_strtolower($fact['label']), ['distributeur', 'editeur', 'éditeur', 'marque', 'développeur', 'developpeur', 'auteur', 'compositeurs', 'créateur'], TRUE)) {
            $subtitle = $fact['value'];
            break;
          }
        }
        $card += [
          'category' => $this->termLink($type),
          'series' => $this->references($node, 'field_series'),
          'image' => $this->image($node, 'field_image', 'tv_poster'),
          'thumb' => $this->thumb($node, 'field_image', 'tv_content', 420),
          'subtitle' => $subtitle ?? $type?->label(),
          'summary' => $this->summary($node, 'body', 140),
          'kicker' => $type?->label() ?? 'Produit',
        ];
        break;

      default:
        $card += ['image' => $this->image($node, 'field_image', 'tv_card'), 'summary' => $this->summary($node), 'kicker' => 'Page'];
    }
    return $card;
  }

  /**
   * A character of the cast: portrait and the people who play it.
   */
  public function roleCard(NodeInterface $node): array {
    $card = $this->card($node);
    $card['image'] = $this->image($node, 'field_image', 'tv_poster');
    $card['actors'] = $node->hasField('field_actor') ? $this->references($node, 'field_actor') : [];
    return $card;
  }

  public function cards(array $nodes): array {
    return array_map(fn(NodeInterface $node) => $this->card($node), $nodes);
  }

  /**
   * Card data for a term (series, season, wiki or news category).
   */
  public function termCard(TermInterface $term, ?int $count = NULL): array {
    $poster = in_array($term->bundle(), ['serie', 'saison']);
    if ($term->bundle() === 'partenaires') {
      $link = $term->get('field_url')->first();
      return [
        'type' => 'partenaires',
        'id' => $term->id(),
        'title' => $term->label(),
        'url' => $link ? $link->getUrl()->toString() : NULL,
        'image' => $this->image($term, 'field_logo', 'tv_logo'),
        'summary' => $this->summary($term, 'description', 220),
      ];
    }
    $card = [
      'type' => $term->bundle(),
      'id' => $term->id(),
      'title' => $term->label(),
      'url' => $term->toUrl()->toString(),
      'image' => $this->image($term, 'field_image', $poster ? 'tv_poster' : 'tv_card'),
      'image_wide' => $this->image($term, 'field_image', 'tv_card'),
      'summary' => $this->summary($term, 'description', 160),
      'dates' => $term->hasField('field_dates') ? $term->get('field_dates')->value : NULL,
      'count' => $count,
    ];
    if ($term->bundle() === 'saison' && !$card['image']) {
      // A season without its own picture shows its first episode.
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('status', 1)->condition('type', 'episode')
        ->condition('field_season', $term->id())->exists('field_image')->sort('field_episode')->range(0, 1)->execute();
      $first = $ids ? $this->entityTypeManager->getStorage('node')->load(reset($ids)) : NULL;
      $card['image_wide'] = $this->image($first, 'field_image', 'tv_card');
    }
    if ($term->bundle() === 'saison') {
      $card['is_films'] = (int) $term->get('field_season_number')->value >= 90;
    }
    if ($term->bundle() === 'serie') {
      $card['abbreviation'] = $term->get('field_abreviation')->value;
    }
    return $card;
  }

  /**
   * Buy / watch links with a readable store name.
   */
  public function storeLinks(ContentEntityInterface $entity, string $field = 'field_affiliates_links'): array {
    $links = [];
    if ($entity->hasField($field)) {
      foreach ($entity->get($field) as $item) {
        $url = $item->getUrl()->toString();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $label = trim((string) $item->title);
        if ($label === '') {
          $label = preg_replace('/^www\./', '', $host);
          foreach (self::STORES as $needle => $name) {
            if (str_contains($host, $needle)) {
              $label = $name;
              break;
            }
          }
        }
        $links[] = ['url' => $url, 'label' => $label];
      }
    }
    return $links;
  }

  /**
   * Videos of a link field: YouTube links become click-to-load embeds.
   */
  public function videos(ContentEntityInterface $entity, string $field): array {
    $videos = [];
    if ($entity->hasField($field)) {
      foreach ($entity->get($field) as $item) {
        $url = $item->getUrl()->toString();
        $id = preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|v/))([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : NULL;
        $videos[] = ['url' => $url, 'youtube' => $id, 'title' => trim((string) $item->title) ?: 'Bande-annonce'];
      }
    }
    return $videos;
  }

  /**
   * Site-wide settings the page chrome needs.
   */
  public function settings(): array {
    return $this->configFactory->get('tvshow_core.settings')->get() ?: [];
  }

}
