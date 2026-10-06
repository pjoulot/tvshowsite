<?php

/**
 * @file
 * Imports a content pack: php scripts/drupal-run.php scripts/import_content.php <pack.json> <pictures dir>.
 *
 * With Drush: drush php:script scripts/import_content.php -- <pack.json> <pictures dir>
 */

$arguments = $args ?? $extra ?? [];
[$pack, $source] = $arguments + [NULL, NULL];
if (!$pack || !is_file($pack) || !$source || !is_dir($source)) {
  fwrite(STDERR, "Usage: import_content.php <pack.json> <pictures dir>\n");
  return;
}
$started = microtime(TRUE);
$stats = \Drupal::service('tvshow_core.importer')->import(realpath($pack), realpath($source), function (string $message) {
  print $message . "\n";
});
foreach ($stats as $what => $count) {
  print str_pad($what, 28) . $count . "\n";
}
// Menu entry for the series page, once.
$page = \Drupal::keyValue('tvshow_core.import')->get('node:page:la-serie');
$links = \Drupal::entityTypeManager()->getStorage('menu_link_content');
if ($page && !$links->loadByProperties(['menu_name' => 'main', 'title' => 'La série'])) {
  $links->create(['title' => 'La série', 'link' => ['uri' => 'entity:node/' . $page], 'menu_name' => 'main', 'weight' => 0])->save();
}
drupal_flush_all_caches();
printf("Done in %.1f s.\n", microtime(TRUE) - $started);
