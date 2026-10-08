<?php

namespace Drupal\tvshow_core\EventSubscriber;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\tvshow_core\Import\ContentPackImporter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends addresses of the previous site to their new home instead of a 404.
 *
 * Exact old paths are stored by the content import (key-value collection
 * "tvshow_core.legacy"), with the patterns of old addresses that carry a
 * variable part ("tvshow_core.legacy_patterns"). Old news posts of a
 * WordPress site lived at the root (/my-post/), so a missing root path is
 * also tried under /actualites/.
 */
class LegacyRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AliasRepositoryInterface $aliasRepository,
    protected KeyValueFactoryInterface $keyValue,
    protected LanguageManagerInterface $languageManager,
  ) {}

  public static function getSubscribedEvents(): array {
    // Before core's fast 404 (200), which answers missing pictures at once.
    return [KernelEvents::EXCEPTION => ['onException', 250]];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof NotFoundHttpException) {
      return;
    }
    $request = $event->getRequest();
    $path = '/' . trim($request->getPathInfo(), '/');
    if ($path === '/' || str_starts_with($path, '/sites/') || str_starts_with($path, '/core/')) {
      return;
    }
    $store = $this->keyValue->get('tvshow_core.legacy');
    $target = $store->get(ContentPackImporter::key(rawurldecode($path)));
    if (!$target) {
      $target = $this->importedFile(rawurldecode(substr($path, 1)));
    }
    if (!$target) {
      $target = $this->fromPatterns(rawurldecode($path), $store);
    }
    if (!$target && substr_count($path, '/') === 1) {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $candidate = '/actualites' . $path;
      if ($this->aliasRepository->lookupByAlias($candidate, $langcode)) {
        $target = $candidate;
      }
    }
    if ($target) {
      $event->setResponse(new TrustedRedirectResponse($request->getBasePath() . $target, 301));
    }
  }

  /**
   * A file of the old site that the import copied (pictures other sites link to).
   */
  protected function importedFile(string $relative): ?string {
    $fid = $this->keyValue->get('tvshow_core.import_files')->get(ContentPackImporter::key($relative));
    $file = $fid ? \Drupal::entityTypeManager()->getStorage('file')->load($fid) : NULL;
    if (!$file) {
      return NULL;
    }
    $url = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
    // Stored addresses are without the base path, which the caller adds.
    return '/' . ltrim(substr($url, strlen(base_path()) - 1), '/');
  }

  /**
   * Old addresses that follow a pattern (news-501-92-any-title.html).
   *
   * Each pattern of the content pack has a regular expression ("from"), a
   * replacement ("to", with $1…) and tells whether the result is an old
   * address to look up ("lookup") or the new address itself. "page_offset"
   * shifts a ?page= number (old listings started at 1, Drupal's at 0).
   */
  protected function fromPatterns(string $path, $store): ?string {
    foreach ($this->keyValue->get('tvshow_core.legacy_patterns')->get('patterns', []) as $pattern) {
      $regex = '~' . str_replace('~', '\\~', $pattern['from']) . '~';
      if (!@preg_match($regex, $path, $matches)) {
        continue;
      }
      $result = preg_replace_callback('~\$(\d)~', function ($m) use ($matches, $pattern) {
        $value = $matches[(int) $m[1]] ?? '';
        return is_numeric($value) && !empty($pattern['page_offset']) ? (string) max(0, (int) $value + (int) $pattern['page_offset']) : $value;
      }, $pattern['to']);
      return !empty($pattern['lookup']) ? $store->get(ContentPackImporter::key($result)) : $result;
    }
    return NULL;
  }

}
