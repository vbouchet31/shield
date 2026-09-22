<?php

namespace Drupal\shield_http_method\Plugin\ShieldCondition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCondition;
use Drupal\shield\ShieldConditionBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Matches the request against a list of HTTP methods.
 */
#[ShieldCondition(
  id: 'http_method',
  title: new TranslatableMarkup('HTTP method'),
  description: new TranslatableMarkup('Match the request against a list of HTTP methods.'),
)]
class HttpMethod extends ShieldConditionBase {

  /**
   * The selectable HTTP methods.
   */
  const METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH', 'CONNECT', 'TRACE'];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['methods' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('HTTP methods'),
      '#description' => $this->t('Select the HTTP methods this condition matches.'),
      '#options' => array_combine(self::METHODS, self::METHODS),
      '#default_value' => $this->configuration['methods'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['methods'] = array_values(array_filter($form_state->getValue('methods')));
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('Methods: @methods', ['@methods' => implode(', ', $this->configuration['methods'])]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    return in_array($request->getMethod(), $this->configuration['methods'], TRUE);
  }

}
