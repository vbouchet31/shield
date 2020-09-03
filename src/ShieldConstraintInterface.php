<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\ConfigurablePluginInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * An interface to define the expected operations of a shield constraint.
 */
interface ShieldConstraintInterface extends PluginInspectionInterface, ConfigurableInterface, DependentPluginInterface, ConfigurablePluginInterface {

  /**
   * Returns a translated string for the constraint title.
   *
   * @return string
   *   Title of the constraint.
   */
  public function getTitle();

  /**
   * Returns a translated description for the constraint description.
   *
   * @return string
   *   Description of the constraint.
   */
  public function getDescription();

  /**
   * Returns a human-readable summary of the constraint.
   *
   * @return string
   *   Summary of the constraint behaviors or restriction.
   */
  public function getSummary();

  /**
   * Evaluate the constraint to check if it applies.
   *
   * @return bool
   *   TRUE if the constraint applies, FALSE otherwise.
   */
  public function evaluate();

  /**
   * Returns the unique ID representing the constraint.
   *
   * @return string
   *   The constraint ID.
   */
  public function getUuid();

  /**
   * Returns the weight of the constraint.
   *
   * @return int|string
   *   Either the integer weight of the constraint, or an empty string.
   */
  public function getWeight();

  /**
   * Sets the weight for this constraint.
   *
   * @param int $weight
   *   The weight for this constraint.
   *
   * @return $this
   */
  public function setWeight($weight);

}
