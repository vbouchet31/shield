<?php

namespace Drupal\shield\Plugin\ShieldConstraint;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\shield\ShieldAuthenticatorBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Register a username and password.
 *
 * @ShieldAuthenticator(
 *   id = "username_password",
 *   title = @Translation("Username & Password"),
 *   description = @Translation("Default authentication method with unique username and password.")
 * )
 */
class UsernamePwd extends ShieldAuthenticatorBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      //'#default_value' => $shield_config->get('credentials.shield.user'),
      '#description' => $this->t('Leave blank to disable authentication.'),
    ];
    $form['pass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      //'#default_value' => $shield_config->get('credentials.shield.pass'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    //$this->configuration['enable'] = $form_state->getValue('enable');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    //return $this->t('Enabled: @status', ['@status' => $this->configuration['enable'] ? $this->t('Yes') : $this->t('No')]);
  }

}
