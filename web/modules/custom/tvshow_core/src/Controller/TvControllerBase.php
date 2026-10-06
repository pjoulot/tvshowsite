<?php

namespace Drupal\tvshow_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\tvshow_core\ContentRepository;
use Drupal\tvshow_core\Presenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shared plumbing for the site's page controllers.
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
   * Zero-based page number from ?page=.
   */
  protected function page(): int {
    return max(0, (int) $this->requests->getCurrentRequest()->query->get('page', 0));
  }

  /**
   * Pager data: previous/next links and a short window of page links.
   */
  protected function pager(int $total, int $per_page, Url $url): ?array {
    $pages = (int) ceil($total / $per_page);
    if ($pages < 2) {
      return NULL;
    }
    $current = min($this->page(), $pages - 1);
    $query = $url->getOption('query') ?: [];
    $link = function (int $page) use ($url, $query) {
      $u = clone $url;
      return $u->setOption('query', $page ? $query + ['page' => $page] : $query)->toString();
    };
    $items = [];
    $previous_shown = -1;
    for ($page = 0; $page < $pages; $page++) {
      if ($page === 0 || $page === $pages - 1 || abs($page - $current) <= 2) {
        if ($page - $previous_shown > 1) {
          $items[] = ['gap' => TRUE];
        }
        $items[] = ['label' => $page + 1, 'url' => $link($page), 'current' => $page === $current];
        $previous_shown = $page;
      }
    }
    return [
      'items' => $items,
      'previous' => $current > 0 ? $link($current - 1) : NULL,
      'next' => $current < $pages - 1 ? $link($current + 1) : NULL,
      'current' => $current + 1,
      'pages' => $pages,
    ];
  }

  /**
   * Render array of the generic listing page.
   */
  protected function listing(array $variables): array {
    $build = ['#theme' => 'tvshow_listing', '#cache' => self::CACHE];
    foreach ($variables as $name => $value) {
      $build['#' . $name] = $value;
    }
    return $build;
  }

}
