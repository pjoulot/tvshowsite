<?php

namespace Drupal\affiliate_link_formatter\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;

/**
 * Plugin implementation of the 'affiliate_link_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "affiliate_link_formatter",
 *   label = @Translation("Affiliate link formatter"),
 *   field_types = {
 *     "link"
 *   }
 * )
 */
class AffiliateLinkFormatter extends LinkFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'rel' => '',
      'target' => '',
      'fa' => TRUE,
      'classes' => '',
      'prefix' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['rel'] = [
      '#type' => 'checkbox',
      '#title' => t('Add rel="nofollow" to links'),
      '#return_value' => 'nofollow',
      '#default_value' => $this->getSetting('rel'),
    ];
    $elements['target'] = [
      '#type' => 'checkbox',
      '#title' => t('Open link in new window'),
      '#return_value' => '_blank',
      '#default_value' => $this->getSetting('target'),
    ];
    $elements['fa'] = [
      '#type' => 'checkbox',
      '#title' => t('Use fontawesome'),
      '#default_value' => $this->getSetting('fa'),
    ];
    $elements['classes'] = [
      '#type' => 'textfield',
      '#title' => t('Link classes'),
      '#default_value' => $this->getSetting('classes'),
    ];
    $elements['prefix'] = [
      '#type' => 'textfield',
      '#title' => t('Prefix'),
      '#default_value' => $this->getSetting('prefix'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $settings = $this->getSettings();

    if (!empty($settings['rel'])) {
      $summary[] = t('Add rel="@rel"', ['@rel' => $settings['rel']]);
    }
    if (!empty($settings['target'])) {
      $summary[] = t('Open link in new window');
    }
    if (!empty($settings['fa'])) {
      $summary[] = t('Use fontawesome');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];
    $entity = $items->getEntity();
    $settings = $this->getSettings();

    foreach ($items as $delta => $item) {
      // By default use the full URL as the link text.
      $url = $this->buildUrl($item);
      $link_title = $url->toString();

      $network = $this->extractNetworkFromUrl($link_title);

      if (empty($settings['url_only']) && !empty($item->title)) {
        // Unsanitized token replacement here because the entire link title
        // gets auto-escaped during link generation in
        // \Drupal\Core\Utility\LinkGenerator::generate().
        $link_title = \Drupal::token()->replace($item->title, [$entity->getEntityTypeId() => $entity], ['clear' => TRUE]);
      }

      $formatted_link_title = $link_title;
      if ($settings['fa']) {
        $icon = '<span class="inner"></span>';
        $icon .= '<i class="fab fa-' . $network . '"></i>';

        if (!empty($settings['prefix'])) {
          $icon = '<div class="affiliate-content">' . $icon;
          $icon .= '<div class="affiliate-text"><span class="affiliate-prefix">' . $settings['prefix'] . '</span><br>';
          $icon .= '<span class="affiliate-title">' . $formatted_link_title . '</span></div>';
          $icon .= '</div>';
        }

        $formatted_link_title = Markup::create($icon);
      }
      else {
        if (!empty($settings['prefix'])) {
          $formatted_link_title = $settings['prefix'] . $formatted_link_title;
        }
      }

      $element[$delta] = [
        '#type' => 'link',
        '#title' => $formatted_link_title,
        '#options' => $url->getOptions(),
      ];

      $element[$delta]['#options']['attributes']['class'][] = $network;
      $element[$delta]['#options']['attributes']['title'] = $link_title;
      $element[$delta]['#url'] = $url;

      if (!empty($settings['classes'])) {
        $element[$delta]['#options']['attributes']['class'][] = $settings['classes'];
      }

      if (!empty($item->_attributes)) {
        $element[$delta]['#options'] += ['attributes' => []];
        $element[$delta]['#options']['attributes'] += $item->_attributes;
        // Unset field item attributes since they have been included in the
        // formatter output and should not be rendered in the field template.
        unset($item->_attributes);
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractNetworkFromUrl($url) {
    $parts = parse_url($url);
    if ($parts) {
      switch ($parts['host']) {
        case 'play.google.com':
          $network = 'google-play';
          break;
        default:
          $network = preg_replace('/(.*\.|^)([^\.]+)\.com$/', '\2', $parts['host']);
          break;
      }
    }
    return (!empty($network)) ? $network : '';
  }

}
