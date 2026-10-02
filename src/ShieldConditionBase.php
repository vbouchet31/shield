<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base class for Shield condition plugins.
 */
abstract class ShieldConditionBase extends PluginBase implements DependentPluginInterface, ShieldConditionInterface {

  use PluginDependencyTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->pluginDefinition['title'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->pluginDefinition['description'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->getTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function affectsCacheability() {
    return $this->pluginDefinition['cacheKeyAware'] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isNegated() {
    return !empty($this->configuration['negate']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['negate' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return ['id' => $this->getPluginId()] + $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['negate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negate the condition'),
      '#default_value' => $this->configuration['negate'],
      '#description' => $this->t('If checked, the condition will be matched if it does NOT apply.'),
      '#weight' => 9998,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['negate'] = $form_state->getValue('negate');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    // No opinion by default: returning the negate flag itself means the
    // entity-level XOR (`isNegated() ? $result : !$result`) always evaluates
    // to FALSE, i.e. "does not exclude the policy".
    return $this->isNegated();
  }

}
