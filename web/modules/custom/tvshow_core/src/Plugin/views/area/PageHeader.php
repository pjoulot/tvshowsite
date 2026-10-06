<?php

namespace Drupal\tvshow_core\Plugin\views\area;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Page chrome of a listing page: breadcrumb, title, rubric links, feed link.
 */
#[ViewsArea('tvshow_page_header')]
class PageHeader extends AreaPluginBase {

  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['kicker'] = ['default' => ''];
    $options['feed_link'] = ['default' => FALSE];
    $options['vocabulary'] = ['default' => ''];
    $options['all_label'] = ['default' => 'Toutes'];
    $options['layout'] = ['default' => 'news'];
    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $vocabularies = [];
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple() as $vocabulary) {
      $vocabularies[$vocabulary->id()] = $vocabulary->label();
    }
    $form['kicker'] = ['#type' => 'textfield', '#title' => $this->t('Small label above the title'), '#default_value' => $this->options['kicker']];
    $form['feed_link'] = ['#type' => 'checkbox', '#title' => $this->t('Link to the feed attached to this display'), '#default_value' => $this->options['feed_link']];
    $form['vocabulary'] = ['#type' => 'select', '#title' => $this->t('Rubric links'), '#description' => $this->t('Shows a link to each term of this vocabulary.'), '#options' => $vocabularies, '#empty_option' => $this->t('- None -'), '#default_value' => $this->options['vocabulary']];
    $form['all_label'] = ['#type' => 'textfield', '#title' => $this->t('Label of the "all" link'), '#default_value' => $this->options['all_label']];
    $form['layout'] = ['#type' => 'textfield', '#title' => $this->t('Layout name'), '#description' => $this->t('Added to the page as the CSS class tv-listing--NAME.'), '#default_value' => $this->options['layout']];
  }

  public function render($empty = FALSE) {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }
    $filters = [];
    if ($this->options['vocabulary']) {
      $terms = \Drupal::service('tvshow_core.repository')->terms($this->options['vocabulary']);
      if (count($terms) > 1) {
        $filters[] = ['label' => $this->options['all_label'] ?: 'Toutes', 'url' => $this->view->getUrl()->toString(), 'active' => TRUE];
        foreach ($terms as $term) {
          $filters[] = ['label' => $term->label(), 'url' => $term->toUrl()->toString(), 'active' => FALSE];
        }
      }
    }
    $feed_url = NULL;
    if ($this->options['feed_link']) {
      foreach ($this->view->displayHandlers as $display) {
        if ($display->getPluginId() === 'feed' && $display->isEnabled() && $display->getPath()) {
          $feed_url = Url::fromRoute('view.' . $this->view->id() . '.' . $display->display['id'])->toString();
          break;
        }
      }
    }
    return [
      '#theme' => 'tvshow_listing_header',
      '#title' => $this->view->getTitle(),
      '#kicker' => $this->options['kicker'] ?: NULL,
      '#filters' => $filters,
      '#feed_url' => $feed_url,
      '#cache' => ['tags' => ['taxonomy_term_list']],
    ];
  }

}
