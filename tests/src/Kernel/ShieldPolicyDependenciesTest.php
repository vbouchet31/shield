<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a policy's config dependencies cover every credential provider.
 *
 * A policy with several OR'd providers must list the config dependencies of
 * all of them, not just the first - calculateDependencies() loops the whole
 * collection precisely so a Key entity used by a second or third provider is
 * not silently left off the policy's dependency list.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\Entity\ShieldPolicy
 */
#[RunTestsInSeparateProcesses]
class ShieldPolicyDependenciesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key', 'shield', 'shield_key', 'shield_password'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['shield']);
  }

  /**
   * Tests dependencies are collected from every configured provider.
   *
   * @covers ::calculateDependencies
   */
  public function testDependenciesCoverEveryCredentialProvider(): void {
    $key_a = Key::create([
      'id' => 'shield_dependency_key_a',
      'label' => 'Shield dependency key A',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'secret-a', 'base64_encoded' => FALSE],
    ]);
    $key_a->save();

    $key_b = Key::create([
      'id' => 'shield_dependency_key_b',
      'label' => 'Shield dependency key B',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'secret-b', 'base64_encoded' => FALSE],
    ]);
    $key_b->save();

    $policy = ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
        ['id' => 'key', 'user' => 'user-a', 'pass_key' => 'shield_dependency_key_a'],
        ['id' => 'key', 'user' => 'user-b', 'pass_key' => 'shield_dependency_key_b'],
      ],
    ]);
    $policy->save();

    $dependencies = $policy->getDependencies();
    $this->assertArrayHasKey('config', $dependencies);
    $this->assertContains($key_a->getConfigDependencyName(), $dependencies['config']);
    $this->assertContains($key_b->getConfigDependencyName(), $dependencies['config']);
  }

}
