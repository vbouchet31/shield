<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\key\Entity\Key;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests Shield module.
 *
 * @group shield
 */
class ShieldTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'shield',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Do basic setup to prepare shield operating but disable it.
    $this->config('shield.settings')
      ->set('shield_enable', FALSE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->set('print', 'Hello world!')
      ->save();

    // Generate a user_password key.
    Key::create([
      'id' => 'shield_test',
      'label' => 'Shield test',
      'key_type' => "user_password",
      'key_type_settings' => [],
      'key_provider' => 'file',
      'key_provider_settings' => [
        'file_location' => drupal_get_path('module', 'shield') . '/tests/files/shield_test.key',
        'strip_line_breaks' => FALSE,
      ],
    ])->save();
  }

  /**
   * Validate shield_enable config display or not the http auth prompt.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testShieldEnable() {
    // Assert we are not presented with a http auth prompt.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);

    // Configure shield so it is enabled.
    $this->config('shield.settings')->set('shield_enable', TRUE)->save();

    // Assert we are presented with a http auth prompt.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Validate the authentication message reflects.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testAuthMessage() {
    // Configure shield so it is enabled.
    $this->config('shield.settings')->set('shield_enable', TRUE)->save();

    // Assert the prompted message is the configured one.
    $this->drupalGet('user');
    $this->assertSession()->responseHeaderContains('WWW-Authenticate', 'Basic realm="Hello world!"');

    // Update the shield message.
    $this->config('shield.settings')->set('print', 'Hello entire world!')->save();

    // Assert the prompted message is the update one.
    $this->drupalGet('user');
    $this->assertSession()->responseHeaderContains('WWW-Authenticate', 'Basic realm="Hello entire world!"');
  }

  /**
   * Validate the Shield credential provider.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testShieldCred() {
    // Configure shield so it is enabled.
    $this->config('shield.settings')->set('shield_enable', TRUE)->save();

    // Assert we are presented with a http auth prompt.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);

    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test shield module configuration with key module.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testShieldKey() {
    $path_to_test = 'user';
    $assert_session = $this->assertSession();

    // Assert our key was created and is available.
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->drupalGet('admin/config/system/keys');
    $assert_session->pageTextContains('Shield Test');
    $assert_session->statusCodeEquals(200);

    // Setup shield module using the shield_test key.
    $shield_config = \Drupal::configFactory()->getEditable('shield.settings');
    $shield_config->set('shield_enable', TRUE);
    $shield_config->set('credential_provider', 'multikey');
    $shield_config->set('credentials.multikey.user_pass_key', 'shield_test');
    $shield_config->set('print', 'Hello world!');
    $shield_config->save();

    // Assert we are presented with a http auth prompt.
    $this->drupalGet($path_to_test);
    $assert_session->responseHeaderContains('WWW-Authenticate', 'Basic realm="Hello world!"');
    $assert_session->statusCodeEquals(401);

    // Assert we can authenticate using the credentials from our key.
    $key_values = \Drupal::service('key.repository')->getKey('shield_test')->getKeyValues();
    $user_pass = $key_values['username'] . ':' . $key_values['password'];
    $this->drupalGet($path_to_test, [], ['Authorization' => 'Basic ' . base64_encode("$user_pass")]);
    $assert_session->statusCodeEquals(200);

    // Assert our configuration shows correctly in the UI.
    $this->drupalGet('admin/config/system/shield');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldValueEquals('credentials[credential_provider]', 'multikey');
    $assert_session->fieldValueEquals('credentials[providers][multikey][user_pass_key]', 'shield_test');
    $assert_session->fieldValueEquals('shield_print', 'Hello world!');
  }

}
