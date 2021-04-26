<?php

use Drupal\Core\Form\FormStateInterface;

function tvshow_form_system_theme_settings_alter(&$form, FormStateInterface $form_state, $form_id = NULL) {
  // Work-around for a core bug affecting admin themes. See issue #943212.
  if (isset($form_id)) {
    return;
  }

  $form['header'] = [
    '#type'          => 'details',
    '#title'         => t('Header'),
    '#description'   => t("Configuration concerning the header of the website."),
    '#open' => TRUE,
  ];

  $form['header']['header_display'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Display the header'),
    '#default_value' => theme_get_setting('header_display'),
  ];

  $form['header']['container'] = [
    '#type' => 'container',
    '#states' => [
      'visible' => [
        ':input[name="header_display"]' => ['checked' => TRUE],
      ],
    ],
  ];

  $form['header']['container']['header_full_width'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Full width'),
    '#default_value' => theme_get_setting('header_full_width'),
  ];

  $form['header']['container']['header_show_slogan'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Show slogan'),
    '#default_value' => theme_get_setting('header_show_slogan'),
  ];

  $form['header']['container']['header_background'] = [
    '#type' => 'managed_file',
    '#title' => t('Header background'),
    '#required' => FALSE,
    '#upload_location' => 'public://tvshow',
    '#default_value' => theme_get_setting('header_background'),
    '#upload_validators' => [
      'file_validate_extensions' => ['gif png jpg jpeg'],
    ],
  ];

  $form['header']['container']['header_top_image'] = [
    '#type' => 'managed_file',
    '#title' => t('Top image'),
    '#required' => FALSE,
    '#upload_location' => 'public://tvshow',
    '#default_value' => theme_get_setting('header_top_image'),
    '#upload_validators' => [
      'file_validate_extensions' => ['gif png jpg jpeg'],
    ],
    '#description' => t('This image will be shown on top of the background. Useful when it is animated.'),
  ];

  $form['header']['container']['header_background_animated'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Animate background'),
    '#default_value' => theme_get_setting('header_background_animated'),
  ];

  $form['menu'] = [
    '#type'          => 'details',
    '#title'         => t('Menu'),
    '#description'   => t("Configuration concerning the menu of the website."),
    '#open' => TRUE,
  ];

  $form['menu']['menu_hamburger'] = [
    '#type'          => 'select',
    '#options'          => [
      'spin' => t('Spin'),
      'slider' => t('Slider'),
      'squeeze' => t('Squeeze'),
      'elastic' => t('Elastic'),
      'collapse' => t('Collapse'),
      'vortex' => t('Vortex'),
    ],
    '#title'         => t('Type of hamburger menu'),
    '#default_value' => theme_get_setting('menu_hamburger'),
  ];

  $form['menu']['menu_full_width'] = [
    '#type'          => 'checkbox',
    '#title'         => t('Full width menu'),
    '#default_value' => theme_get_setting('menu_full_width'),
  ];

  $form['social_media'] = [
    '#type'          => 'details',
    '#title'         => t('Social media'),
    '#description'   => t("Configuration of the URLs of the social media."),
    '#open' => TRUE,
  ];

  $form['social_media']['twitter_url'] = [
    '#type'          => 'textfield',
    '#title'         => t('Twitter'),
    '#default_value' => theme_get_setting('twitter_url'),
  ];
  $form['social_media']['facebook_url'] = [
    '#type'          => 'textfield',
    '#title'         => t('Facebook'),
    '#default_value' => theme_get_setting('facebook_url'),
  ];
  $form['social_media']['instagram_url'] = [
    '#type'          => 'textfield',
    '#title'         => t('Instagram'),
    '#default_value' => theme_get_setting('instagram_url'),
  ];
  $form['social_media']['steam_url'] = [
    '#type'          => 'textfield',
    '#title'         => t('Steam'),
    '#default_value' => theme_get_setting('steam_url'),
  ];

  $form['#submit'][] = 'tvshow_form_system_theme_settings_submit';

}

/**
 * Theme Settings Submit Callback.
 */
function tvshow_form_system_theme_settings_submit($form, FormStateInterface &$form_state) {
  $permanent_files = ['header_background', 'header_top_image'];
  foreach ($permanent_files as $file_key) {
    if ($file_id = $form_state->getValue([$file_key, '0'])) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      $file->setPermanent();
      $file->save();
    }
  }
}
