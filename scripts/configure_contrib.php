<?php

/**
 * @file
 * (Re)runs the contributed-module setup and prints what happened.
 *
 * drush php:script scripts/configure_contrib.php
 */

foreach (\Drupal::service('tvshow_core.contrib_setup')->run() as $line) {
  print $line . "\n";
}
drupal_flush_all_caches();
