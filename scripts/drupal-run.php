<?php

/**
 * @file
 * Runs a PHP script inside a bootstrapped Drupal: php scripts/drupal-run.php file.php [args].
 *
 * A stand-in for `drush php:script` so the project has no hard Drush dependency.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
  exit(1);
}
$root = dirname(__DIR__) . '/web';
$autoloader = require dirname(__DIR__) . '/vendor/autoload.php';
$script = realpath($argv[1] ?? '');
if (!$script) {
  fwrite(STDERR, "Usage: php scripts/drupal-run.php script.php [args]\n");
  exit(1);
}
// Relative paths are resolved from where the command was typed.
$args = array_map(fn($arg) => file_exists($arg) ? realpath($arg) : $arg, array_slice($argv, 2));
chdir($root);
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = getenv('DRUPAL_HOST') ?: 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_SOFTWARE'] = NULL;
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);
// Act as the administrator so entity access never blocks a script.
$admin = \Drupal::entityTypeManager()->getStorage('user')->load(1);
if ($admin) {
  \Drupal::currentUser()->setAccount($admin);
}
require $script;
