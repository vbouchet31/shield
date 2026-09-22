<?php

namespace Drupal\shield_key\Plugin\ShieldCredentialProvider;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCredentialProvider;
use Drupal\shield\ShieldCredentialProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks credentials against a username and a Key module entity.
 */
#[ShieldCredentialProvider(
  id: 'key',
  title: new TranslatableMarkup('Key'),
  description: new TranslatableMarkup('Check credentials against a username and a password stored in a Key module entity.'),
)]
class Key extends ShieldCredentialProviderBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new Key credential provider object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin
   *   instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['user' => '', 'pass_key' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#default_value' => $this->configuration['user'],
      '#required' => TRUE,
    ];
    $form['pass_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['pass_key'],
      '#empty_option' => $this->t('- Please select -'),
      '#key_filters' => ['type' => 'authentication'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['user'] = $form_state->getValue('user');
    $this->configuration['pass_key'] = $form_state->getValue('pass_key');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('User: @user (key: @key)', [
      '@user' => $this->configuration['user'],
      '@key' => $this->configuration['pass_key'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    if ($this->configuration['pass_key']) {
      $key = $this->entityTypeManager->getStorage('key')->load($this->configuration['pass_key']);
      if ($key) {
        $dependencies['config'][] = $key->getConfigDependencyName();
      }
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function checkCredentials($user, $pass) {
    if ($this->configuration['user'] === '' || !hash_equals((string) $this->configuration['user'], (string) $user)) {
      return FALSE;
    }

    if (!$this->configuration['pass_key']) {
      return FALSE;
    }

    /** @var \Drupal\key\KeyInterface|null $key */
    $key = $this->entityTypeManager->getStorage('key')->load($this->configuration['pass_key']);
    if (!$key) {
      return FALSE;
    }

    $key_value = $key->getKeyValue();
    if ($key_value === NULL) {
      return FALSE;
    }

    return hash_equals((string) $key_value, (string) $pass);
  }

}
