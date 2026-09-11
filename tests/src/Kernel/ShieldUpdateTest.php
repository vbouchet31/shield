<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Shield update path.
 *
 * @group shield
 */
#[RunTestsInSeparateProcesses]
class ShieldUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'path_alias'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['shield']);
    \Drupal::moduleHandler()->loadInclude('shield', 'install');
  }

  /**
   * Rewinds shield.settings to the shape shipped by shield 8.x-1.1.
   */
  protected function seedLegacyConfig(string $user, string $pass): void {
    // Write straight to the storage: the 8.x-1.1 shape no longer matches the
    // module's schema, so it cannot be saved through the config factory.
    $storage = \Drupal::service('config.storage');
    $data = $storage->read('shield.settings');
    unset($data['shield_enable'], $data['credential_provider'], $data['credentials']);
    $data['user'] = $user;
    $data['pass'] = $pass;
    $storage->write('shield.settings', $data);
    \Drupal::configFactory()->reset('shield.settings');
  }

  /**
   * Credentials stored in active config survive the 1.1 -> 1.2 update.
   */
  public function testUpdateMigratesActiveConfigCredentials(): void {
    $this->seedLegacyConfig('shield_user', 'shield_pass');

    $message = shield_update_8002();
    shield_update_8003();

    $this->assertNull($message, 'No settings.php warning for credentials already in active config.');
    $config = \Drupal::configFactory()->get('shield.settings');
    $this->assertSame('shield', $config->get('credential_provider'));
    $this->assertSame('shield_user', $config->get('credentials.shield.user'));
    $this->assertSame('shield_pass', $config->get('credentials.shield.pass'));
    $this->assertTrue($config->get('shield_enable'));
  }

  /**
   * Credentials set in settings.php survive the 1.1 -> 1.2 update.
   */
  public function testUpdateKeepsSettingsPhpOverriddenCredentials(): void {
    // A 1.1 site that kept its credentials out of exported config.
    $this->seedLegacyConfig('', '');
    $GLOBALS['config']['shield.settings']['user'] = 'override_user';
    $GLOBALS['config']['shield.settings']['pass'] = 'override_pass';

    $message = shield_update_8002();
    shield_update_8003();

    $this->assertNotNull($message, 'The update warns about the stale settings.php override.');
    $this->assertStringContainsString('settings.php', (string) $message);
    $config = \Drupal::configFactory()->get('shield.settings');
    $this->assertSame('override_user', $config->get('credentials.shield.user'));
    $this->assertSame('override_pass', $config->get('credentials.shield.pass'));
    $this->assertTrue($config->get('shield_enable'), 'Shield stays enabled after the update.');
  }

  /**
   * A site already broken by the old update recovers its credentials.
   */
  public function testUpdateRecoversCredentialsDroppedByTheOldUpdate(): void {
    // The state the old shield_update_8002() left behind: the new keys are
    // empty, Shield is off, and settings.php still overrides the old keys.
    \Drupal::configFactory()->getEditable('shield.settings')
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', '')
      ->set('credentials.shield.pass', '')
      ->set('shield_enable', FALSE)
      ->save();
    $GLOBALS['config']['shield.settings']['user'] = 'override_user';
    $GLOBALS['config']['shield.settings']['pass'] = 'override_pass';

    $message = shield_update_8009();

    $this->assertNotNull($message);
    $config = \Drupal::configFactory()->get('shield.settings');
    $this->assertSame('override_user', $config->get('credentials.shield.user'));
    $this->assertSame('override_pass', $config->get('credentials.shield.pass'));
    $this->assertTrue($config->get('shield_enable'), 'Shield is re-enabled.');
  }

  /**
   * The recovery update leaves a healthy site alone.
   */
  public function testRecoveryUpdateSkipsHealthySites(): void {
    \Drupal::configFactory()->getEditable('shield.settings')
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'shield_user')
      ->set('credentials.shield.pass', 'shield_pass')
      ->set('shield_enable', TRUE)
      ->save();

    $this->assertNull(shield_update_8009());

    $config = \Drupal::configFactory()->get('shield.settings');
    $this->assertSame('shield_user', $config->get('credentials.shield.user'));
    $this->assertTrue($config->get('shield_enable'));
  }

  /**
   * The basic_auth bypass defaults to off on an existing site.
   */
  public function testBasicAuthBypassDefaultsToOff(): void {
    \Drupal::configFactory()->getEditable('shield.settings')
      ->clear('basic_auth_bypass')
      ->save(TRUE);

    shield_update_8012();

    $this->assertFalse(\Drupal::configFactory()->get('shield.settings')->get('basic_auth_bypass'));
  }

  /**
   * The update leaves a site that already opted in alone.
   */
  public function testBasicAuthBypassUpdateKeepsExistingValue(): void {
    \Drupal::configFactory()->getEditable('shield.settings')
      ->set('basic_auth_bypass', TRUE)
      ->save(TRUE);

    shield_update_8012();

    $this->assertTrue(\Drupal::configFactory()->get('shield.settings')->get('basic_auth_bypass'));
  }

}
