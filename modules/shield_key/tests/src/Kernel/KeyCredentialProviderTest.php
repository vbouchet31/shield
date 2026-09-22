<?php

namespace Drupal\Tests\shield_key\Kernel;

use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the key credential provider plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_key\Plugin\ShieldCredentialProvider\Key
 */
#[RunTestsInSeparateProcesses]
class KeyCredentialProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key', 'shield', 'shield_key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A config key provider so the test does not depend on the file system.
    Key::create([
      'id' => 'shield_password',
      'label' => 'Shield password',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'key-secret',
        'base64_encoded' => FALSE,
      ],
    ])->save();
  }

  /**
   * Tests that the provider accepts its own pair and nothing else.
   *
   * @covers ::checkCredentials
   */
  public function testKeyCredentials(): void {
    $provider = $this->createProvider('user', 'shield_password');

    $this->assertTrue($provider->checkCredentials('user', 'key-secret'));
    $this->assertFalse($provider->checkCredentials('user', 'wrong-secret'));
    $this->assertFalse($provider->checkCredentials('wrong-user', 'key-secret'));
    $this->assertFalse($provider->checkCredentials('', ''));
  }

  /**
   * Tests that a non-existent key denies every request without a fatal.
   *
   * @covers ::checkCredentials
   */
  public function testMissingKeyIsRejected(): void {
    $provider = $this->createProvider('user', 'no_such_key');

    $this->assertFalse($provider->checkCredentials('user', 'key-secret'));
  }

  /**
   * Tests that a key whose provider returns NULL denies every request.
   *
   * The env key provider returns NULL, rather than an empty string, when its
   * environment variable is unset - unlike the config provider, whose
   * key_value setting is schema-typed as a string and so can never actually
   * hold NULL.
   *
   * @covers ::checkCredentials
   */
  public function testNullKeyValueIsRejected(): void {
    Key::create([
      'id' => 'shield_null',
      'label' => 'Shield null key',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'SHIELD_TEST_UNSET_ENV_VARIABLE',
        'strip_line_breaks' => TRUE,
        'base64_encoded' => FALSE,
      ],
    ])->save();

    $provider = $this->createProvider('user', 'shield_null');

    $this->assertFalse($provider->checkCredentials('user', ''));
  }

  /**
   * Creates a configured key credential provider plugin instance.
   *
   * @param string $user
   *   The configured username.
   * @param string $pass_key
   *   The ID of the key entity holding the password.
   *
   * @return \Drupal\shield\ShieldCredentialProviderInterface
   *   The configured plugin instance.
   */
  protected function createProvider(string $user, string $pass_key) {
    return $this->container->get('plugin.manager.shield.credential_provider')
      ->createInstance('key', ['user' => $user, 'pass_key' => $pass_key]);
  }

}
