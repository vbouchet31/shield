<?php

namespace Drupal\shield_multikey\Plugin\ShieldCredentialProvider;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCredentialProvider;
use Drupal\shield\ShieldCredentialProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks credentials against a username/password pair stored in one Key.
 */
#[ShieldCredentialProvider(
  id: 'multikey',
  title: new TranslatableMarkup('Key (user/password)'),
  description: new TranslatableMarkup('Check credentials against a username and password stored together in a single Key module entity.'),
)]
class Multikey extends ShieldCredentialProviderBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new Multikey credential provider object.
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
    return ['user_pass_key' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['user_pass_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('User/password'),
      '#default_value' => $this->configuration['user_pass_key'],
      '#empty_option' => $this->t('- Please select -'),
      '#key_filters' => ['type' => 'user_password'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['user_pass_key'] = $form_state->getValue('user_pass_key');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('Key: @key', ['@key' => $this->configuration['user_pass_key']]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    if ($this->configuration['user_pass_key']) {
      $key = $this->entityTypeManager->getStorage('key')->load($this->configuration['user_pass_key']);
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
    if (!$this->configuration['user_pass_key']) {
      return FALSE;
    }

    /** @var \Drupal\key\KeyInterface|null $key */
    $key = $this->entityTypeManager->getStorage('key')->load($this->configuration['user_pass_key']);
    if (!$key) {
      return FALSE;
    }

    // getKeyValues() decodes the key's raw JSON value via the user_password
    // key type's multivalue unserialize(), which returns NULL for a value
    // that is not valid JSON. Neither that nor a value missing one of the
    // two expected fields must be allowed to fall through to a partial
    // (username-only or password-only) comparison.
    $values = $key->getKeyValues();
    if (!is_array($values) || !isset($values['username'], $values['password'])) {
      return FALSE;
    }

    return hash_equals((string) $values['username'], (string) $user)
      && hash_equals((string) $values['password'], (string) $pass);
  }

}
