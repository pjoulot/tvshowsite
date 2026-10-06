<?php

/**
 * @file
 * Install tasks of the TV Show profile.
 */

/**
 * Implements hook_install_tasks().
 */
function tvshow_install_tasks(&$install_state): array {
  return [
    'tvshow_configure_contrib' => [
      'display_name' => t('Configure contributed modules'),
      'type' => 'normal',
    ],
  ];
}

/**
 * Enables and configures the contributed modules found in the codebase.
 */
function tvshow_configure_contrib(array &$install_state): void {
  foreach (\Drupal::service('tvshow_core.contrib_setup')->run() as $line) {
    \Drupal::logger('tvshow')->notice($line);
    if (PHP_SAPI === 'cli') {
      print '  ' . $line . "\n";
    }
  }
}
