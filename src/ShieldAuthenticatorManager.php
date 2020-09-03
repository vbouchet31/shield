<?php

namespace Drupal\shield;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager that controls shield authenticators.
 */
class ShieldAuthenticatorManager extends DefaultPluginManager {

  /**
   * Constructs a new ShieldAuthenticatorPluginManager.
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
    parent::__construct('Plugin/ShieldAuthenticator', $namespaces, $module_handler, 'Drupal\shield\ShieldAuthenticatorInterface', 'Drupal\shield\Annotation\ShieldAuthenticator');

    $this->alterInfo('shield_authenticator_info');
    $this->setCacheBackend($cache_backend, 'shield_authenticator');
  }

}
