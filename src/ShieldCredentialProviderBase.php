<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for Shield credential provider plugins.
 */
abstract class ShieldCredentialProviderBase extends PluginBase implements DependentPluginInterface, ShieldCredentialProviderInterface {

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
  public function getSummary() {
    return $this->getTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [];
  }

}
