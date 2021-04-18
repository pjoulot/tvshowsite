<?php

namespace Drupal\tvshow_general\TwigExtension;

use Drupal\taxonomy\Entity\Term;

/**
 * Class DefaultService.
 *
 * @package Drupal\demo_module
 */
class TwigExtension extends \Twig\Extension\AbstractExtension {

  /**
   * {@inheritdoc}
   * This function must return the name of the extension. It must be unique.
   */
  public function getName() {
    return 'tvshow_general';
  }

  /**
   * In this function we can declare the extension function.
   */
  public function getFunctions() {
    return array(
        new \Twig\TwigFunction('taxonomyFieldFromId', array($this, 'taxonomyFieldFromId'), array('is_safe' => array('html'))),
    );
  }
  /*
   * This function is used to return alt of an image
   * Set image title as alt.
   */
  public function taxonomyFieldFromId($id, $field_name) {
    $field = '';
    $term = Term::load($id);

    if (!empty($term)) {
      $field = $term->{$field_name}->value;
    }

    return $field;
  }

}
