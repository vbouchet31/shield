<?php

namespace Drupal\shield;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\shield\Attribute\ShieldCredentialProvider;

/**
 * Provides the Shield credential provider plugin manager.
 */
class ShieldCredentialProviderPluginManager extends DefaultPluginManager {

  /**
   * Constructs a new ShieldCredentialProviderPluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ShieldCredentialProvider', $namespaces, $module_handler, ShieldCredentialProviderInterface::class, ShieldCredentialProvider::class);

    $this->alterInfo('shield_credential_provider_info');
    $this->setCacheBackend($cache_backend, 'shield_credential_provider');
  }

}
