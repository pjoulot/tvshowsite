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
