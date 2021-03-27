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
}
