<?php

namespace Drupal\shield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\shield\ShieldConstraintInterface;
use Drupal\shield\ShieldConstraintPluginCollection;
use Drupal\shield\ShieldPolicyInterface;

/**
 * Defines a Shield Policy configuration entity class.
 *
 * @ConfigEntityType(
 *   id = "shield_policy",
 *   label = @Translation("Shield policy"),
 *   label_collection = @Translation("Shield policies"),
 *   label_singular = @Translation("shield policy"),
 *   label_plural = @Translation("shield policies"),
 *   label_count = @PluralTranslation(
 *     singular = "@count shield policy",
 *     plural = "@count shield policies",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\shield\ShieldPolicyListBuilder",
 *     "form" = {
 *       "add" = "Drupal\shield\Form\ShieldPolicyAddForm",
 *       "edit" = "Drupal\shield\Form\ShieldPolicyEditForm",
 *       "delete" = "Drupal\shield\Form\ShieldPolicyDeleteForm"
 *     },
 *   },
 *   config_prefix = "shield",
 *   config_export = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status",
 *     "weight" = "weight",
 *     "langcode" = "langcode",
 *     "constraints" = "constraints"
 *   },
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status",
 *     "weight" = "weight"
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/system/shield/policy/{shield_policy}/edit",
 *     "delete-form" = "/admin/config/system/shield/policy/{shield_policy}/delete",
 *     "collection" = "/admin/config/system/shield/policies"
 *   }
 * )
 */
class ShieldPolicy extends ConfigEntityBase implements ShieldPolicyInterface, EntityWithPluginCollectionInterface {

  /**
   * The ID of the policy.
   *
   * @var int
   */
  protected $id;

  /**
   * The policy title.
   *
   * @var string
   */
  protected $label;

  /**
   * The weight for this policy.
   *
   * @var int
   */
  protected $weight;

  /**
   * The status for this policy.
   *
   * @var bool
   */
  protected $status;

  /**
   * The array of constraints for this policy.
   *
   * @var array
   */
  protected $constraints = [];

  /**
   * Holds the collection of constraints that are used by this policy.
   *
   * @var \Drupal\shield\ShieldConstraintPluginCollection
   */
  protected $constraintsCollection;

  /**
   * The authenticator for this policy.
   *
   * @var \Drupal\shield\ShieldAuthenticatorInterface
   */
  protected $authenticator;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->label;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * Returns the constraint plugin manager.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   The constraint plugin manager.
   */
  protected function getShieldConstraintPluginManager() {
    return \Drupal::service('plugin.manager.shield.shield_constraint');
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return ['constraints' => $this->getConstraints()];
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    if (!$this->constraintsCollection) {
      $this->constraintsCollection = new ShieldConstraintPluginCollection($this->getShieldConstraintPluginManager(), $this->constraints);
      $this->constraintsCollection->sort();
    }
    return $this->constraintsCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraint($constraint) {
    return $this->getConstraints()->get($constraint);
  }

  /**
   * {@inheritdoc}
   */
  public function addConstraint(array $configuration) {
    $configuration['uuid'] = $this->uuidGenerator()->generate();
    $this->getConstraints()->addInstanceId($configuration['uuid'], $configuration);

    return $configuration['uuid'];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteConstraint(ShieldConstraintInterface $constraint) {
    $this->getConstraints()->removeInstanceId($constraint->getUuid());
    $this->save();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthenticator() {
    return $this->authenticator;
  }

  /**
   * Evaluate the policy to check if it applies.
   *
   * @return bool
   *   TRUE if the policy applies, FALSE otherwise.
   */
  public function evaluate() {
    foreach ($this->getConstraints() as $constraint) {
      $plugin = \Drupal::service('plugin.manager.shield.shield_constraint');

      /** @var \Drupal\shield\ShieldConstraintInterface $constraint */
      $constraint = $plugin->createInstance($constraint['id'], $constraint);

      if (!$constraint->evaluate()) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
