<?php

namespace Drupal\Tests\shield_multikey\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the multikey credential provider plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_multikey\Plugin\ShieldCredentialProvider\Multikey
 */
#[RunTestsInSeparateProcesses]
class MultikeyCredentialProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['key', 'shield', 'shield_multikey'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createUserPasswordKey('shield_user_pass', 'user', 'secret');
  }

  /**
   * Tests that the provider accepts its own pair and nothing else.
   *
   * @covers ::checkCredentials
   */
  public function testMatchingCredentials(): void {
    $provider = $this->createProvider('shield_user_pass');

    $this->assertTrue($provider->checkCredentials('user', 'secret'));
  }

  /**
   * Tests that a wrong username is rejected even with the right password.
   *
   * @covers ::checkCredentials
   */
  public function testWrongUsernameIsRejected(): void {
    $provider = $this->createProvider('shield_user_pass');

    $this->assertFalse($provider->checkCredentials('wrong-user', 'secret'));
  }

  /**
   * Tests that a wrong password is rejected even with the right username.
   *
   * @covers ::checkCredentials
   */
  public function testWrongPasswordIsRejected(): void {
    $provider = $this->createProvider('shield_user_pass');

    $this->assertFalse($provider->checkCredentials('user', 'wrong-secret'));
  }

  /**
   * Tests that an unconfigured key ID denies every request without a fatal.
   *
   * @covers ::checkCredentials
   */
  public function testUnsetKeyIsRejected(): void {
    $provider = $this->createProvider('');

    $this->assertFalse($provider->checkCredentials('user', 'secret'));
  }

  /**
   * Tests that a non-existent key denies every request without a fatal.
   *
   * @covers ::checkCredentials
   */
  public function testMissingKeyIsRejected(): void {
    $provider = $this->createProvider('no_such_key');

    $this->assertFalse($provider->checkCredentials('user', 'secret'));
  }

  /**
   * Tests that a key whose value is not valid JSON is rejected, not fatal.
   *
   * @covers ::checkCredentials
   */
  public function testMalformedKeyValueIsRejected(): void {
    Key::create([
      'id' => 'shield_malformed',
      'label' => 'Shield malformed key',
      'key_type' => 'user_password',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'not valid json',
        'base64_encoded' => FALSE,
      ],
    ])->save();

    $provider = $this->createProvider('shield_malformed');

    $this->assertFalse($provider->checkCredentials('user', 'secret'));
  }

  /**
   * Tests that a key value missing one of the two fields is rejected.
   *
   * @covers ::checkCredentials
   */
  public function testIncompleteKeyValueIsRejected(): void {
    Key::create([
      'id' => 'shield_incomplete',
      'label' => 'Shield incomplete key',
      'key_type' => 'user_password',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => Json::encode(['username' => 'user']),
        'base64_encoded' => FALSE,
      ],
    ])->save();

    $provider = $this->createProvider('shield_incomplete');

    $this->assertFalse($provider->checkCredentials('user', ''));
  }

  /**
   * Creates a Key entity of type user_password with the given pair.
   *
   * @param string $id
   *   The key entity ID.
   * @param string $username
   *   The username to store in the key.
   * @param string $password
   *   The password to store in the key.
   */
  protected function createUserPasswordKey(string $id, string $username, string $password): void {
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => 'user_password',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => Json::encode(['username' => $username, 'password' => $password]),
        'base64_encoded' => FALSE,
      ],
    ])->save();
  }

  /**
   * Creates a configured multikey credential provider plugin instance.
   *
   * @param string $user_pass_key
   *   The ID of the key entity holding the username/password pair.
   *
   * @return \Drupal\shield\ShieldCredentialProviderInterface
   *   The configured plugin instance.
   */
  protected function createProvider(string $user_pass_key) {
    return $this->container->get('plugin.manager.shield.credential_provider')
      ->createInstance('multikey', ['user_pass_key' => $user_pass_key]);
  }

}
