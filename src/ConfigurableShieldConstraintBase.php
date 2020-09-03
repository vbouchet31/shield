<?php

namespace Drupal\shield;

use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a base class for configurable shield constraints.
 *
 * @see \Drupal\shield\Annotation\ShieldConstraint
 * @see \Drupal\shield\ConfigurableShieldConstraintInterface
 * @see \Drupal\shield\ShieldConstraintInterface
 * @see \Drupal\shield\ShieldConstraintBase
 * @see \Drupal\shield\ShieldConstraintManager
 * @see plugin_api
 */
abstract class ConfigurableShieldConstraintBase extends ShieldConstraintBase implements ConfigurableShieldConstraintInterface {

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
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return [
        'id' => $this->getPluginId(),
      ] + $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
    return $this;
  }

}
