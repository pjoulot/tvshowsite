<?php

namespace Drupal\tvshow_core\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Hands taxonomy term pages to the module's own controller.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Runs after Views, whose taxonomy_term view also claims the term page.
   */
  public static function getSubscribedEvents(): array {
    return [RoutingEvents::ALTER => ['onAlterRoutes', -300]];
  }

  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('entity.taxonomy_term.canonical')) {
      $route->setDefaults(array_diff_key($route->getDefaults(), ['view_id' => 1, 'display_id' => 1, '_view_display_show_admin_links' => 1, '_view_display_plugin_id' => 1, '_view_display_plugin_class' => 1]));
      $route->setDefault('_controller', '\Drupal\tvshow_core\Controller\TermController::view');
      $route->setDefault('_title_callback', '\Drupal\tvshow_core\Controller\TermController::title');
    }
  }

}
