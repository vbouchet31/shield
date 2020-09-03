<?php

namespace Drupal\shield;

use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Defines the interface for configurable shield constraints.
 *
 * @see \Drupal\shield\Annotation\ShieldConstraint
 * @see \Drupal\shield\ConfigurableShieldConstraintInterface
 * @see \Drupal\shield\ShieldConstraintInterface
 * @see \Drupal\shield\ShieldConstraintBase
 * @see \Drupal\shield\ShieldConstraintManager
 * @see plugin_api
 */
interface ConfigurableShieldConstraintInterface extends ShieldConstraintInterface, PluginFormInterface {
}
