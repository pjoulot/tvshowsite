<?php

namespace Drupal\tvshow_core;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Gives nodes and terms a readable address from fixed patterns.
 *
 * An alias the generator created is kept in sync when the title changes.
 * An alias an editor typed by hand is never touched.
 */
class AliasGenerator {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TransliterationInterface $transliteration,
    protected KeyValueFactoryInterface $keyValue,
  ) {}

  /**
   * URL-safe version of a label.
   */
  public function slug(?string $text): string {
    $text = $this->transliteration->transliterate((string) $text, 'fr', '-');
    $text = preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace(['’', "'"], '', $text)));
    return trim(substr($text, 0, 96), '-') ?: 'sans-titre';
  }

  /**
   * The alias an entity should have, or NULL when it has no pattern.
   */
  public function pattern(ContentEntityInterface $entity): ?string {
    $title = $this->slug($entity->label());
    $key = $entity->getEntityTypeId() . ':' . $entity->bundle();
    switch ($key) {
      case 'node:article':
        return "/actualites/$title";

      case 'node:editorial':
        $category = $entity->get('field_category')->entity;
        return '/wiki/' . ($category ? $this->slug($category->label()) : 'divers') . "/$title";

      case 'node:episode':
        $season = $entity->get('field_season')->entity;
        $serie = $season?->get('field_serie')->entity;
        if (!$season || !$serie) {
          return "/episodes/$title";
        }
        return '/' . $this->serieSlug($serie) . '/' . $this->slug($season->label()) . "/$title";

      case 'node:people':
        return "/personnalites/$title";

      case 'node:page':
        return "/$title";

      case 'taxonomy_term:serie':
        return '/' . $this->serieSlug($entity);

      case 'taxonomy_term:saison':
        $serie = $entity->get('field_serie')->entity;
        return $serie ? '/' . $this->serieSlug($serie) . "/$title" : NULL;

      case 'taxonomy_term:category':
        return "/wiki/$title";

      case 'taxonomy_term:article_category':
        return "/actualites/rubrique/$title";

      case 'taxonomy_term:tags':
        return "/tags/$title";

      case 'taxonomy_term:job':
        return "/personnalites/metier/$title";
    }
    return NULL;
  }

  protected function serieSlug(ContentEntityInterface $serie): string {
    return $this->slug($serie->get('field_abreviation')->value ?: $serie->label());
  }

  /**
   * Creates or refreshes the alias of an entity after it was saved.
   */
  public function sync(ContentEntityInterface $entity): void {
    $wanted = $this->pattern($entity);
    if (!$wanted) {
      return;
    }
    $path = '/' . $entity->toUrl()->getInternalPath();
    $langcode = $entity->language()->getId();
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $existing = $storage->loadByProperties(['path' => $path]);
    $existing = $existing ? reset($existing) : NULL;
    $store = $this->keyValue->get('tvshow_core.alias');
    $managed = $store->get($path);
    if ($existing && $existing->getAlias() !== $managed) {
      // Typed by an editor or set by an import: leave it alone.
      return;
    }
    if ($existing && $existing->getAlias() === $wanted) {
      return;
    }
    $alias = $this->unique($wanted, $path);
    if ($existing) {
      $existing->setAlias($alias)->save();
    }
    else {
      $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $langcode])->save();
    }
    $store->set($path, $alias);
  }

  /**
   * Removes the bookkeeping for a deleted entity.
   */
  public function forget(ContentEntityInterface $entity): void {
    $this->keyValue->get('tvshow_core.alias')->delete('/' . $entity->toUrl()->getInternalPath());
  }

  protected function unique(string $alias, string $path): string {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $candidate = $alias;
    for ($i = 2; $i < 200; $i++) {
      $taken = array_filter($storage->loadByProperties(['alias' => $candidate]), fn($a) => $a->getPath() !== $path);
      if (!$taken) {
        return $candidate;
      }
      $candidate = "$alias-$i";
    }
    return $alias . '-' . substr(md5($path), 0, 6);
  }

}
