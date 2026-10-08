<?php

namespace Drupal\tvshow_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tvshow_core\ContentRepository;
use Drupal\tvshow_core\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared plumbing for the site's page controllers.
 *
 * Controllers only assemble pages: every list on them is a Views display
 * (the tvshow_* views), embedded with embed().
 */
abstract class TvControllerBase extends ControllerBase {

  const CACHE = ['tags' => ['node_list', 'taxonomy_term_list', 'config:tvshow_core.settings'], 'contexts' => ['url.query_args', 'user.permissions']];

  public function __construct(
    protected ContentRepository $repository,
    protected Presenter $presenter,
    protected RequestStack $requests,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('tvshow_core.repository'), $container->get('tvshow_core.presenter'), $container->get('request_stack'));
  }

  /**
   * Render array of one Views display.
   */
  protected function embed(string $view, string $display, ...$arguments): array {
    return ['#type' => 'view', '#name' => $view, '#display_id' => $display, '#arguments' => $arguments, '#embed' => TRUE];
  }

  /**
   * Series filter of a listing (?serie=sga).
   *
   * Returns [links, selected series term or NULL]. $counts limits the links
   * to the series that have something to show (series id => count); with
   * fewer than two of them, there is nothing to filter and no link is given.
   */
  protected function serieFilter(string $base_url, ?array $counts = NULL, array $query = []): array {
    $wanted = (string) $this->requests->getCurrentRequest()->query->get('serie', '');
    $selected = NULL;
    $links = [];
    $series = $this->repository->terms('serie');
    if ($counts !== NULL) {
      $series = array_values(array_filter($series, fn($term) => isset($counts[$term->id()])));
    }
    foreach ($series as $term) {
      $abbreviation = (string) $term->get('field_abreviation')->value;
      $active = $wanted !== '' && strcasecmp($wanted, $abbreviation) === 0;
      if ($active) {
        $selected = $term;
      }
      $links[] = [
        'label' => $this->presenter->shortName($term),
        'title' => $term->label(),
        'url' => $base_url . '?' . http_build_query($query + ['serie' => $abbreviation]),
        'active' => $active,
      ];
    }
    if (count($links) < 2 && !$selected) {
      return [[], NULL];
    }
    array_unshift($links, [
      'label' => 'Toutes les séries',
      'title' => 'Toutes les séries',
      'url' => $base_url . ($query ? '?' . http_build_query($query) : ''),
      'active' => !$selected,
    ]);
    return [$links, $selected];
  }

  /**
   * The advertising code of the ad slots.
   */
  protected function adCode(): string {
    return (string) $this->config('tvshow_core.settings')->get('ad_html');
  }

  /**
   * Render array of the generic listing page: page chrome around a view.
   */
  protected function listing(array $variables): array {
    $build = ['#theme' => 'tvshow_listing', '#cache' => self::CACHE];
    foreach ($variables as $name => $value) {
      $build['#' . $name] = $value;
    }
    return $build;
  }

}
