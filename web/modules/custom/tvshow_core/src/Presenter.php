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
    $time = is_numeric($value) ? (int) $value : strtotime($value . ' 12:00:00 UTC');
    $day = (int) gmdate('j', $time);
    return ($day === 1 ? '1er' : $day) . ' ' . self::MONTHS[(int) gmdate('n', $time)] . ' ' . gmdate('Y', $time);
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
          'date_iso' => gmdate('Y-m-d', $node->getCreatedTime()),
          'category' => $this->termLink($node->get('field_article_category')->entity),
          'image' => $this->image($node, 'field_image', 'tv_card'),
          'image_wide' => $this->image($node, 'field_image', 'tv_wide'),
          'summary' => $this->summary($node),
          'kicker' => 'Actualité',
        ];
        break;

      case 'editorial':
        $card += [
          'category' => $this->termLink($node->get('field_category')->entity),
          'image' => $this->image($node, 'field_image', 'tv_square'),
          'summary' => $this->summary($node, 'body', 140),
          'kicker' => 'Wiki',
        ];
        break;

      case 'episode':
        $season = $node->get('field_season')->entity;
        $card += [
          'number' => $node->get('field_episode')->value,
          'season' => $this->termLink($season),
          'season_number' => $season?->get('field_season_number')->value,
          'original_title' => $node->get('field_original_title')->value,
          'date' => $this->date($node->get('field_date_de_diffusion')->value),
          'image' => $this->image($node, 'field_image', 'tv_card'),
          'summary' => $this->summary($node, 'field_synopsis', 150),
          'kicker' => 'Épisode',
        ];
        break;

      case 'people':
        $card += [
          'image' => $this->image($node, 'field_picture', 'tv_square'),
          'jobs' => $this->references($node, 'field_job'),
          'summary' => $this->summary($node, 'body', 140),
          'kicker' => 'Personnalité',
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
    return [
      'type' => $term->bundle(),
      'id' => $term->id(),
      'title' => $term->label(),
      'url' => $term->toUrl()->toString(),
      'image' => $this->image($term, 'field_image', $poster ? 'tv_poster' : 'tv_card'),
      'summary' => $this->summary($term, 'description', 160),
      'dates' => $term->hasField('field_dates') ? $term->get('field_dates')->value : NULL,
      'count' => $count,
    ];
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
