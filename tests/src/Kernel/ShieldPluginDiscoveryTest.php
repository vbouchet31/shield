<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that each sub-module's plugin is found via attribute-only discovery.
 *
 * Both plugin managers are constructed without a trailing annotation-class
 * argument, unlike memory_limit_policy/password_policy's reference managers -
 * this is the one place the design diverges from either reference module, so
 * it gets its own dedicated smoke test rather than relying only on the
 * createInstance() calls elsewhere already failing loudly if discovery broke.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\ShieldConditionPluginManager
 */
#[RunTestsInSeparateProcesses]
class ShieldPluginDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'shield',
    'shield_http_method',
    'shield_key',
    'shield_password',
    'shield_path',
  ];

  /**
   * Tests that every condition sub-module's plugin is discovered.
   *
   * @covers ::getDefinitions
   */
  public function testConditionPluginsAreDiscovered(): void {
    $definitions = $this->container->get('plugin.manager.shield.condition')->getDefinitions();

    $this->assertArrayHasKey('path', $definitions);
    $this->assertArrayHasKey('http_method', $definitions);
    $this->assertCount(2, $definitions);
  }

  /**
   * Tests that every credential provider sub-module's plugin is discovered.
   *
   * @covers ::getDefinitions
   */
  public function testCredentialProviderPluginsAreDiscovered(): void {
    $definitions = $this->container->get('plugin.manager.shield.credential_provider')->getDefinitions();

    $this->assertArrayHasKey('password', $definitions);
    $this->assertArrayHasKey('key', $definitions);
    $this->assertCount(2, $definitions);
  }

}
