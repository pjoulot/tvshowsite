<?php

namespace Drupal\tvshow_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Footer text, tagline and social network links.
 */
class SettingsForm extends ConfigFormBase {

  const NETWORKS = ['facebook' => 'Facebook', 'x' => 'X (Twitter)', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'bluesky' => 'Bluesky', 'steam' => 'Steam'];

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
    $form['description'] = [
      '#type' => 'textarea',
      '#rows' => 2,
      '#title' => $this->t('Description of the site'),
      '#description' => $this->t('One or two sentences (about 150 characters) shown by search engines under the home page. Leave empty for a description built from the site name and tagline.'),
      '#default_value' => $config->get('description'),
    ];
    $form['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#default_value' => $config->get('footer_text'),
      '#rows' => 3,
    ];
    $form['wiki_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name of the wiki'),
      '#description' => $this->t('Shown in titles and breadcrumbs, for example “Wiki” or “Encyclopédie”.'),
      '#default_value' => $config->get('wiki_label') ?: 'Wiki',
      '#maxlength' => 40,
    ];
    $form['ad_html'] = [
      '#type' => 'textarea',
      '#rows' => 4,
      '#title' => $this->t('Advertising code'),
      '#description' => $this->t('HTML or script given by the ad network (for example Google AdSense), printed in the ad slots of the theme. Leave empty to keep the slots empty.'),
      '#default_value' => $config->get('ad_html'),
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
      ->set('description', trim((string) $form_state->getValue('description')))
      ->set('footer_text', $form_state->getValue('footer_text'))
      ->set('social', $social)
      ->set('wiki_label', trim((string) $form_state->getValue('wiki_label')) ?: 'Wiki')
      ->set('ad_html', (string) $form_state->getValue('ad_html'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
