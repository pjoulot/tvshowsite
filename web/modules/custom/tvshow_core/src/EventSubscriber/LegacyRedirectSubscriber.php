<?php

namespace Drupal\tvshow_core\EventSubscriber;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\path_alias\AliasRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends addresses of the previous site to their new home instead of a 404.
 *
 * Exact old paths are stored by the content import (key-value collection
 * "tvshow_core.legacy"). Old news posts lived at the root (/my-post/), so a
 * missing root path is also tried under /actualites/.
 */
class LegacyRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AliasRepositoryInterface $aliasRepository,
    protected KeyValueFactoryInterface $keyValue,
    protected LanguageManagerInterface $languageManager,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 60]];
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
    $target = $this->keyValue->get('tvshow_core.legacy')->get($path);
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

}
