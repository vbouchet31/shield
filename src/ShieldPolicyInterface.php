<?php

namespace Drupal\shield;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining a shield policy entity.
 */
interface ShieldPolicyInterface extends ConfigEntityInterface {

  /**
   * Returns a specific constraint.
   *
   * @param string $constraint
   *   The constraint ID.
   *
   * @return \Drupal\shield\ShieldConstraintInterface
   *   The constraint object.
   */
  public function getConstraint($constraint);

  /**
   * Returns the constraints for this policy.
   *
   * @return \Drupal\shield\ShieldConstraintPluginCollection|\Drupal\shield\ShieldConstraintInterface[]
   *   The constraints plugin collection.
   */
  public function getConstraints();

  /**
   * Saves constraint for this policy.
   *
   * @param array $configuration
   *   An array of constraint configuration.
   *
   * @return string
   *   The constraint ID.
   */
  public function addConstraint(array $configuration);

  /**
   * Deletes constraint from this policy.
   *
   * @param \Drupal\shield\ShieldConstraintInterface $constraint
   *   The constraint object.
   *
   * @return $this
   */
  public function deleteConstraint(ShieldConstraintInterface $constraint);

  /**
   * Return the authentication method for this policy.
   *
   * @return \Drupal\Shield\ShieldAuthenticatorInterface
   *   The authenticator object.
   */
  public function getAuthenticator();
}
