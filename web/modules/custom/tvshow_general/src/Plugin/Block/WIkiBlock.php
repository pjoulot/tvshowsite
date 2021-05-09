<?php

namespace Drupal\tvshow_general\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Wiki Block' Block.
 *
 * @Block(
 *   id = "tvshow_wiki_block",
 *   admin_label = @Translation("Wiki block"),
 *  context_definitions = {
 *    "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *  }
 * )
 */
class WikiBlock extends BlockBase  implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getContextValue('node');

    // We want different view mode based on what category the editorial content
    // belongs to.
    $view_mode = 'wiki';

    if ($node->hasField('field_category') && !empty($node->field_category->entity)) {
      switch ($node->field_category->entity->getName()) {
        case 'Casting':
          $view_mode = 'side_image';
          break;
      }
    }

    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $output = $view_builder->view($node, $view_mode);

    return $output;

    return [
      '#markup' => $this->t('Hello, World!'),
    ];
  }

}
