<?php

namespace Drupal\shield;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Manages shield constraint plugins.
 *
 * @see \Drupal\shield\Annotation\ShieldConstraint
 * @see \Drupal\shield\ShieldConstraintInterface
 * @see \Drupal\shield\ShieldConstraintBase
 * @see \Drupal\shield\ConfigurableShieldConstraintInterface
 * @see \Drupal\shield\ConfigurableShieldConstraintBase
 */
class ShieldConstraintManager extends DefaultPluginManager {

  /**
   * Constructs a new ShieldConstraintManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ShieldConstraint', $namespaces, $module_handler, 'Drupal\shield\ShieldConstraintInterface', 'Drupal\shield\Annotation\ShieldConstraint');

    $this->alterInfo('shield_constraint_info');
    $this->setCacheBackend($cache_backend, 'shield_constraint_plugins');
  }

}
