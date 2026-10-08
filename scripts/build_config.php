<?php

/**
 * @file
 * Developer tool: (re)builds the content model and exports it as module config.
 *
 * Run on a scratch site that has tvshow_core enabled:
 *   php scripts/drupal-run.php scripts/build_config.php
 * It creates vocabularies, content types, fields, displays and image styles
 * through the entity API, then writes them to
 * web/modules/custom/tvshow_core/config/install so a fresh install gets them.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;

$vocabularies = [
  'serie' => ['Série', 'Les séries couvertes par le site.'],
  'saison' => ['Saison', 'Les saisons de chaque série.'],
  'category' => ['Catégorie du wiki', 'Les rubriques du wiki (personnages, vaisseaux, technologies…).'],
  'article_category' => ['Catégorie d’actualité', 'Les rubriques des actualités.'],
  'tags' => ['Tags', 'Mots-clés libres.'],
  'job' => ['Métier', 'Les métiers des personnalités (acteur, réalisateur, scénariste…).'],
  'partenaires' => ['Partenaire', 'Les sites partenaires.'],
  'product_type' => ['Type de produit', 'Les rayons des produits dérivés et des jeux (DVD, livres, jeux officiels…), sur deux niveaux.'],
];
foreach ($vocabularies as $vid => [$name, $description]) {
  if (!Vocabulary::load($vid)) {
    Vocabulary::create(['vid' => $vid, 'name' => $name, 'description' => $description])->save();
  }
}

$types = [
  'article' => ['Actualité', 'Une actualité datée, classée dans une rubrique.'],
  'editorial' => ['Fiche du wiki', 'Une fiche encyclopédique : personnage, vaisseau, technologie, planète…'],
  'episode' => ['Épisode', 'Un épisode rattaché à une saison.'],
  'people' => ['Personnalité', 'Un acteur ou un membre de l’équipe.'],
  'page' => ['Page', 'Une page libre (présentation, mentions légales, galerie…).'],
  'product' => ['Produit dérivé', 'Un DVD, un livre, une figurine, un jeu vidéo… classé par type de produit.'],
];
foreach ($types as $id => [$name, $description]) {
  if (!NodeType::load($id)) {
    NodeType::create(['type' => $id, 'name' => $name, 'description' => $description, 'new_revision' => TRUE, 'display_submitted' => FALSE])->save();
  }
}

/**
 * Field definitions: name => [type, label, cardinality, storage settings, [bundle => instance settings]].
 */
$ref = fn(string $target, array $bundles, bool $auto = FALSE) => [
  'handler' => 'default:' . $target,
  'handler_settings' => ['target_bundles' => array_combine($bundles, $bundles), 'auto_create' => $auto] + ($auto ? ['auto_create_bundle' => $bundles[0]] : []),
];
$image = fn(string $dir) => ['file_directory' => $dir, 'file_extensions' => 'png gif jpg jpeg webp', 'alt_field' => TRUE, 'alt_field_required' => FALSE, 'title_field' => FALSE, 'max_filesize' => '', 'max_resolution' => '', 'min_resolution' => ''];
$fields = [
  'node' => [
    'body' => ['text_with_summary', 'Texte', 1, [], ['article' => [], 'editorial' => [], 'episode' => ['label' => 'Résumé détaillé'], 'people' => ['label' => 'Biographie'], 'page' => [], 'product' => ['label' => 'Description']]],
    'field_image' => ['image', 'Image principale', 1, [], ['article' => $image('actualites'), 'editorial' => $image('wiki'), 'episode' => $image('episodes'), 'page' => $image('pages'), 'product' => $image('produits') + ['label' => 'Visuel']]],
    'field_gallery' => ['image', 'Galerie', -1, [], ['article' => $image('galeries'), 'editorial' => $image('galeries'), 'page' => $image('galeries'), 'episode' => $image('galeries') + ['label' => 'Photos des coulisses'], 'product' => $image('galeries')]],
    'field_meta_description' => ['string_long', 'Description pour les moteurs de recherche', 1, [], ['article' => [], 'editorial' => [], 'episode' => ['label' => 'Résumé court (listes et moteurs de recherche)'], 'people' => [], 'page' => [], 'product' => []]],
    'field_facts' => ['string_long', 'Fiche technique', 1, [], ['editorial' => ['label' => 'Fiche (une ligne « Libellé : valeur » par information)'], 'episode' => ['label' => 'Autres informations (une ligne « Libellé : valeur » par information)'], 'people' => ['label' => 'Fiche (une ligne « Libellé : valeur » par information)'], 'product' => ['label' => 'Caractéristiques (une ligne « Libellé : valeur » par information)']]],
    'field_article_category' => ['entity_reference', 'Rubrique', 1, ['target_type' => 'taxonomy_term'], ['article' => $ref('taxonomy_term', ['article_category'])]],
    'field_tags' => ['entity_reference', 'Tags', -1, ['target_type' => 'taxonomy_term'], ['article' => $ref('taxonomy_term', ['tags'], TRUE), 'editorial' => $ref('taxonomy_term', ['tags'], TRUE)]],
    'field_source' => ['link', 'Source', 1, [], ['article' => ['link_type' => 16, 'title' => 1]]],
    'field_byline' => ['string', 'Auteur affiché', 1, [], ['article' => []]],
    'field_category' => ['entity_reference', 'Rubrique du wiki', 1, ['target_type' => 'taxonomy_term'], ['editorial' => $ref('taxonomy_term', ['category'])]],
    'field_serie' => ['entity_reference', 'Série', 1, ['target_type' => 'taxonomy_term'], ['editorial' => $ref('taxonomy_term', ['serie'])]],
    'field_actor' => ['entity_reference', 'Interprète', -1, ['target_type' => 'node'], ['editorial' => $ref('node', ['people'])]],
    'field_appearance' => ['entity_reference', 'Première apparition', -1, ['target_type' => 'node'], ['editorial' => $ref('node', ['episode'])]],
    'field_series' => ['entity_reference', 'Séries', -1, ['target_type' => 'taxonomy_term'], ['people' => $ref('taxonomy_term', ['serie']), 'product' => $ref('taxonomy_term', ['serie'])]],
    'field_product_type' => ['entity_reference', 'Type de produit', 1, ['target_type' => 'taxonomy_term'], ['product' => $ref('taxonomy_term', ['product_type']) + ['required' => TRUE]]],
    'field_season' => ['entity_reference', 'Saison', 1, ['target_type' => 'taxonomy_term'], ['episode' => $ref('taxonomy_term', ['saison'])]],
    'field_episode' => ['integer', 'Numéro de l’épisode', 1, [], ['episode' => ['min' => 0]]],
    'field_original_title' => ['string', 'Titre original', 1, [], ['episode' => []]],
    'field_date_de_diffusion' => ['datetime', 'Date de première diffusion', 1, ['datetime_type' => 'date'], ['episode' => []]],
    'field_synopsis' => ['text_long', 'Synopsis', 1, [], ['episode' => []]],
    'field_director' => ['entity_reference', 'Réalisation', -1, ['target_type' => 'node'], ['episode' => $ref('node', ['people'])]],
    'field_writers' => ['entity_reference', 'Scénario', -1, ['target_type' => 'node'], ['episode' => $ref('node', ['people'])]],
    'field_guest_stars' => ['string_long', 'Casting secondaire', 1, [], ['episode' => []]],
    'field_duration' => ['integer', 'Durée', 1, [], ['episode' => ['suffix' => ' min', 'min' => 0]]],
    'field_audience' => ['string', 'Audience', 1, [], ['episode' => []]],
    'field_promotional_pictures' => ['image', 'Photos promotionnelles', -1, [], ['episode' => $image('episodes/promo')]],
    'field_trailers' => ['link', 'Bandes-annonces', -1, [], ['episode' => ['link_type' => 16, 'title' => 1]]],
    'field_affiliates_links' => ['link', 'Liens d’achat', -1, [], ['episode' => ['link_type' => 16, 'title' => 1], 'product' => ['link_type' => 16, 'title' => 1]]],
    'field_linked_content' => ['entity_reference', 'Contenus liés', -1, ['target_type' => 'node'], ['episode' => $ref('node', ['article', 'editorial'])]],
    'field_picture' => ['image', 'Photo', 1, [], ['people' => $image('personnalites')]],
    'field_job' => ['entity_reference', 'Métier', -1, ['target_type' => 'taxonomy_term'], ['people' => $ref('taxonomy_term', ['job'], TRUE)]],
  ],
  'taxonomy_term' => [
    'field_image' => ['image', 'Image', 1, [], ['serie' => $image('series'), 'saison' => $image('saisons'), 'category' => $image('rubriques'), 'article_category' => $image('rubriques'), 'tags' => $image('rubriques'), 'product_type' => $image('rubriques')]],
    'field_facts' => ['string_long', 'Fiche technique (une ligne « Libellé : valeur » par information)', 1, [], ['serie' => []]],
    'field_abreviation' => ['string', 'Abréviation (utilisée dans les adresses)', 1, [], ['serie' => ['required' => TRUE]]],
    'field_dates' => ['string', 'Dates de diffusion', 1, [], ['serie' => [], 'saison' => []]],
    'field_creators' => ['string', 'Créateurs', 1, [], ['serie' => []]],
    'field_serie' => ['entity_reference', 'Série', 1, ['target_type' => 'taxonomy_term'], ['saison' => $ref('taxonomy_term', ['serie']) + ['required' => TRUE]]],
    'field_season_number' => ['integer', 'Numéro de la saison', 1, [], ['saison' => ['min' => 0]]],
    'field_affiliates_links' => ['link', 'Liens d’achat', -1, [], ['saison' => ['link_type' => 16, 'title' => 1]]],
    'field_logo' => ['image', 'Logo', 1, [], ['partenaires' => $image('partenaires')]],
    'field_url' => ['link', 'Adresse du site', 1, [], ['partenaires' => ['link_type' => 16, 'title' => 0, 'required' => TRUE]]],
  ],
];
$display = \Drupal::service('entity_display.repository');
foreach ($fields as $entity_type => $definitions) {
  foreach ($definitions as $name => [$type, $label, $cardinality, $storage_settings, $bundles]) {
    if (!FieldStorageConfig::loadByName($entity_type, $name)) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => $entity_type, 'type' => $type, 'cardinality' => $cardinality, 'settings' => $storage_settings])->save();
    }
    $weight = 0;
    foreach ($bundles as $bundle => $settings) {
      $bundle_label = $settings['label'] ?? $label;
      $required = $settings['required'] ?? FALSE;
      unset($settings['label'], $settings['required']);
      if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => $entity_type, 'bundle' => $bundle, 'label' => $bundle_label, 'required' => $required, 'settings' => $settings])->save();
      }
    }
  }
}
// Form displays: every field with its default widget, in definition order.
foreach ($fields as $entity_type => $definitions) {
  $by_bundle = [];
  foreach ($definitions as $name => $definition) {
    foreach (array_keys($definition[4]) as $bundle) {
      $by_bundle[$bundle][] = [$name, $definition[0]];
    }
  }
  foreach ($by_bundle as $bundle => $names) {
    $form = $display->getFormDisplay($entity_type, $bundle);
    $view = $display->getViewDisplay($entity_type, $bundle);
    $weight = 10;
    foreach ($names as [$name, $type]) {
      $options = ['weight' => $weight++];
      if ($type === 'entity_reference' && in_array($name, ['field_tags', 'field_job', 'field_appearance'])) {
        $options['type'] = 'entity_reference_autocomplete_tags';
      }
      elseif ($type === 'entity_reference' && str_contains($name, 'categor') || in_array($name, ['field_serie', 'field_season', 'field_product_type'])) {
        $options['type'] = 'options_select';
      }
      elseif ($name === 'field_series') {
        $options['type'] = 'options_buttons';
      }
      $form->setComponent($name, $options);
      // Pages are rendered by tvshow_core's own templates, not by formatters.
      $view->removeComponent($name);
    }
    $form->save();
    $view->save();
  }
}

// Image styles.
$styles = [
  'tv_card' => ['Carte (640×400)', 640, 400],
  'tv_wide' => ['Large (1040×585)', 1040, 585],
  'tv_thumb' => ['Vignette (200×150)', 200, 150],
  'tv_square' => ['Carré (400×400)', 400, 400],
  'tv_poster' => ['Affiche (480×640)', 480, 640],
  'tv_gallery' => ['Vignette de galerie (360×240)', 360, 240],
  'tv_logo' => ['Logo (320 px)', 320, NULL],
  'tv_full' => ['Pleine taille (1600 px)', 1600, NULL],
  'tv_content' => ['Dans le texte (900 px)', 900, NULL],
];
foreach ($styles as $id => [$label, $width, $height]) {
  if (!ImageStyle::load($id)) {
    $style = ImageStyle::create(['name' => $id, 'label' => $label]);
    $style->addImageEffect($height
      ? ['id' => 'image_scale_and_crop', 'weight' => 0, 'data' => ['width' => $width, 'height' => $height, 'anchor' => 'center-top']]
      : ['id' => 'image_scale', 'weight' => 0, 'data' => ['width' => $width, 'height' => NULL, 'upscale' => FALSE]]);
    $style->addImageEffect(['id' => 'image_convert', 'weight' => 1, 'data' => ['extension' => 'webp']]);
    $style->save();
  }
}

// Editor role.
if (!Role::load('editor')) {
  $role = Role::create(['id' => 'editor', 'label' => 'Rédacteur', 'weight' => 3]);
  $permissions = ['access administration pages', 'access content overview', 'access toolbar', 'view the administration theme', 'access contextual links', 'create url aliases', 'view own unpublished content', 'access files overview', 'use text format full_html', 'use text format basic_html', 'administer taxonomy', 'administer menu'];
  foreach (array_keys($types) as $type) {
    array_push($permissions, "create $type content", "edit any $type content", "delete own $type content", "view $type revisions");
  }
  $available = array_keys(\Drupal::service('user.permissions')->getPermissions());
  foreach (array_intersect($permissions, $available) as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();
}

// Export everything this script owns.
$target = new FileStorage(\Drupal::root() . '/modules/custom/tvshow_core/config/install');
$active = \Drupal::service('config.storage');
$prefixes = ['taxonomy.vocabulary.', 'node.type.', 'field.storage.node.', 'field.storage.taxonomy_term.', 'field.field.node.', 'field.field.taxonomy_term.', 'core.entity_form_display.node.', 'core.entity_form_display.taxonomy_term.', 'core.entity_view_display.node.', 'core.entity_view_display.taxonomy_term.', 'image.style.tv_', 'user.role.editor', 'core.base_field_override.node.'];
foreach ($target->listAll() as $name) {
  foreach ($prefixes as $prefix) {
    if (str_starts_with($name, $prefix)) {
      $target->delete($name);
    }
  }
}
$count = 0;
foreach ($active->listAll() as $name) {
  foreach ($prefixes as $prefix) {
    if (str_starts_with($name, $prefix)) {
      $data = $active->read($name);
      unset($data['uuid'], $data['_core']);
      $target->write($name, $data);
      $count++;
      break;
    }
  }
}
print "Exported $count config objects.\n";
