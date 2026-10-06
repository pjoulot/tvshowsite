<?php

namespace Drupal\tvshow_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Footer text, tagline and social network links.
 */
class SettingsForm extends ConfigFormBase {

  const NETWORKS = ['facebook' => 'Facebook', 'x' => 'X (Twitter)', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'bluesky' => 'Bluesky'];

  public function getFormId(): string {
    return 'tvshow_core_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['tvshow_core.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('tvshow_core.settings');
    $form['tagline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tagline'),
      '#description' => $this->t('Short line shown under the site name in the header.'),
      '#default_value' => $config->get('tagline'),
      '#maxlength' => 120,
    ];
    $form['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#default_value' => $config->get('footer_text'),
      '#rows' => 3,
    ];
    $form['social'] = ['#type' => 'details', '#title' => $this->t('Social networks'), '#open' => TRUE, '#tree' => TRUE];
    foreach (self::NETWORKS as $key => $label) {
      $form['social'][$key] = ['#type' => 'url', '#title' => $label, '#default_value' => $config->get('social.' . $key)];
    }
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $social = array_map(fn($value) => $value ?: NULL, $form_state->getValue('social'));
    $this->config('tvshow_core.settings')
      ->set('tagline', $form_state->getValue('tagline'))
      ->set('footer_text', $form_state->getValue('footer_text'))
      ->set('social', $social)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
