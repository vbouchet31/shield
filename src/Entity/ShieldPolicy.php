<?php

namespace Drupal\shield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\ShieldPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a Shield policy configuration entity class.
 */
#[ConfigEntityType(
  id: 'shield_policy',
  label: new TranslatableMarkup('Shield policy'),
  label_singular: new TranslatableMarkup('Shield policy'),
  label_plural: new TranslatableMarkup('Shield policies'),
  label_count: [
    'singular' => '@count Shield policy',
    'plural' => '@count Shield policies',
  ],
  config_prefix: 'shield_policy',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => 'Drupal\shield\Controller\ShieldPolicyListBuilder',
    'form' => [
      'add' => 'Drupal\shield\Form\ShieldPolicyFormAdd',
      'edit' => 'Drupal\shield\Form\ShieldPolicyFormEdit',
      'delete' => 'Drupal\shield\Form\ShieldPolicyDeleteForm',
      'enable' => 'Drupal\shield\Form\ShieldPolicyEnableForm',
      'disable' => 'Drupal\shield\Form\ShieldPolicyDisableForm',
    ],
  ],
  links: [
    'add-form' => '/admin/config/system/shield/policy/add',
    'edit-form' => '/admin/config/system/shield/policy/{shield_policy}',
    'delete-form' => '/admin/config/system/shield/policy/{shield_policy}/delete',
    'collection' => '/admin/config/system/shield/policy',
    'enable' => '/admin/config/system/shield/policy/{shield_policy}/enable',
    'disable' => '/admin/config/system/shield/policy/{shield_policy}/disable',
  ],
  admin_permission: 'administer shield',
  config_export: [
    'id',
    'label',
    'status',
    'weight',
    'conditions',
    'credential_providers',
  ],
)]
class ShieldPolicy extends ConfigEntityBase implements ShieldPolicyInterface {

  /**
   * The ID of the policy.
   *
   * @var string
   */
  protected $id;

  /**
   * The policy label.
   *
   * @var string
   */
  protected $label;

  /**
   * The weight of the policy.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Condition plugin configurations, keyed by delta.
   *
   * @var array
   */
  protected $conditions = [];

  /**
   * Credential provider plugin configurations, keyed by delta.
   *
   * @var array
   */
  protected $credential_providers = [];

  /**
   * The condition plugin collection.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $conditionsCollection;

  /**
   * The credential provider plugin collection.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $credentialProvidersCollection;

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function getConditions() {
    return $this->conditions;
  }

  /**
   * {@inheritdoc}
   */
  public function getCondition($key) {
    return $this->conditions[$key] ?? NULL;
  }

  /**
   * Returns the lazily-instantiated condition plugin collection.
   *
   * @return \Drupal\Core\Plugin\DefaultLazyPluginCollection
   *   The condition plugin collection.
   */
  public function getConditionsCollection() {
    if (!isset($this->conditionsCollection)) {
      $this->conditionsCollection = new DefaultLazyPluginCollection(\Drupal::service('plugin.manager.shield.condition'), $this->conditions);
    }
    return $this->conditionsCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function matchesConditions(Request $request) {
    foreach ($this->getConditionsCollection() as $condition) {
      /** @var \Drupal\shield\ShieldConditionInterface $condition */
      $result = $condition->evaluate($request);
      if ($condition->isNegated() ? $result : !$result) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function matchesCacheableConditions(Request $request) {
    foreach ($this->getConditionsCollection() as $condition) {
      /** @var \Drupal\shield\ShieldConditionInterface $condition */
      if (!$condition->affectsCacheability()) {
        continue;
      }
      $result = $condition->evaluate($request);
      if ($condition->isNegated() ? $result : !$result) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCredentialProviders() {
    return !empty($this->credential_providers);
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentialProviders() {
    return $this->credential_providers;
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentialProviderConfig($key) {
    return $this->credential_providers[$key] ?? NULL;
  }

  /**
   * Returns the lazily-instantiated credential provider plugin collection.
   *
   * @return \Drupal\Core\Plugin\DefaultLazyPluginCollection
   *   The credential provider plugin collection.
   */
  public function getCredentialProvidersCollection() {
    if (!isset($this->credentialProvidersCollection)) {
      $this->credentialProvidersCollection = new DefaultLazyPluginCollection(\Drupal::service('plugin.manager.shield.credential_provider'), $this->credential_providers);
    }
    return $this->credentialProvidersCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function checkCredentials($user, $pass) {
    foreach ($this->getCredentialProvidersCollection() as $provider) {
      /** @var \Drupal\shield\ShieldCredentialProviderInterface $provider */
      if ($provider->checkCredentials($user, $pass)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRealm() {
    return $this->label() . ' (' . $this->id() . ')';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    foreach ($this->getConditionsCollection() as $condition) {
      $this->addDependencies($this->getPluginDependencies($condition));
    }

    foreach ($this->getCredentialProvidersCollection() as $provider) {
      $this->addDependencies($this->getPluginDependencies($provider));
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    // Coarse, admin-triggered invalidation: a policy save (including an
    // enable/disable, which is a status-only save) can change which
    // requests are protected, so any stale cache.page entry for a
    // newly-protected path must not be served.
    // The cache.page service only exists when the page_cache module is
    // installed.
    if (\Drupal::hasService('cache.page')) {
      \Drupal::service('cache.page')->deleteAll();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    parent::postDelete($storage, $entities);
    if (\Drupal::hasService('cache.page')) {
      \Drupal::service('cache.page')->deleteAll();
    }
  }

}
