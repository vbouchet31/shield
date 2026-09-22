<?php

namespace Drupal\Tests\shield_password\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the password credential provider plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_password\Plugin\ShieldCredentialProvider\Password
 */
#[RunTestsInSeparateProcesses]
class PasswordCredentialProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password'];

  /**
   * Tests a single username/password pair.
   *
   * @covers ::checkCredentials
   */
  public function testSinglePair(): void {
    $provider = $this->createProvider(['user'], ['password']);

    $this->assertTrue($provider->checkCredentials('user', 'password'));
    $this->assertFalse($provider->checkCredentials('user', 'wrong-password'));
    $this->assertFalse($provider->checkCredentials('wrong-user', 'password'));
    $this->assertFalse($provider->checkCredentials('', ''));
  }

  /**
   * Tests that a password containing a colon is compared verbatim.
   *
   * @covers ::checkCredentials
   */
  public function testPasswordWithColon(): void {
    $provider = $this->createProvider(['user'], ['pass:word']);

    $this->assertTrue($provider->checkCredentials('user', 'pass:word'));
    $this->assertFalse($provider->checkCredentials('user', 'pass'));
  }

  /**
   * Tests more than one configured username/password pair.
   *
   * @covers ::checkCredentials
   */
  public function testMultiplePairs(): void {
    $provider = $this->createProvider(['user1', 'user2'], ['password1', 'password2']);

    $this->assertTrue($provider->checkCredentials('user1', 'password1'));
    $this->assertTrue($provider->checkCredentials('user2', 'password2'));
    $this->assertFalse($provider->checkCredentials('user1', 'password2'));
    $this->assertFalse($provider->checkCredentials('user2', 'password1'));
  }

  /**
   * Tests that a mismatched username/password count rejects every request.
   *
   * @covers ::checkCredentials
   */
  public function testMismatchedPairCountRejectsEverything(): void {
    $provider = $this->createProvider(['user1', 'user2'], ['password1']);

    $this->assertFalse($provider->checkCredentials('user1', 'password1'));
    $this->assertFalse($provider->checkCredentials('user2', ''));
  }

  /**
   * Tests that a mismatched username/password count is rejected on the form.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateRejectsMismatchedPairCount(): void {
    $errors = $this->validate('user1|user2', 'password1');

    $this->assertArrayHasKey('pass', $errors);
    $this->assertStringContainsString('2 usernames were given but 1 passwords', (string) $errors['pass']);
  }

  /**
   * Tests that a single pair skips the multi-pair checks entirely.
   *
   * A lone empty username is never meaningful to reject on its own - a site
   * that wants no credentials at all simply configures no policy - so the
   * emptiness/uniqueness checks below only start to matter once there is
   * more than one pair to tell apart.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateSkipsChecksForSinglePair(): void {
    $this->assertSame([], $this->validate('', 'password'));
  }

  /**
   * Tests that an empty username among several pairs is rejected.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateRejectsEmptyUsernameAmongMultiplePairs(): void {
    $errors = $this->validate('user1|', 'password1|password2');

    $this->assertArrayHasKey('user', $errors);
    $this->assertStringContainsString('Usernames cannot be empty.', (string) $errors['user']);
  }

  /**
   * Tests that a duplicate username among several pairs is rejected.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateRejectsDuplicateUsername(): void {
    $errors = $this->validate('user1|user1', 'password1|password2');

    $this->assertArrayHasKey('user', $errors);
    $this->assertStringContainsString('Each username can only be listed once.', (string) $errors['user']);
  }

  /**
   * Tests that valid multiple pairs produce no validation errors.
   *
   * @covers ::validateConfigurationForm
   */
  public function testValidateAcceptsValidMultiplePairs(): void {
    $this->assertSame([], $this->validate('user1|user2', 'password1|password2'));
  }

  /**
   * Runs the plugin's form validation against the given raw field values.
   *
   * @param string $user
   *   The raw, pipe-delimited "user" field value.
   * @param string $pass
   *   The raw, pipe-delimited "pass" field value.
   *
   * @return string[]
   *   The resulting form errors, keyed by field name.
   */
  protected function validate(string $user, string $pass): array {
    $provider = $this->createProvider([], []);
    $form = [];
    $form_state = new FormState();
    $form_state->setValue('user', $user);
    $form_state->setValue('pass', $pass);

    $provider->validateConfigurationForm($form, $form_state);

    return $form_state->getErrors();
  }

  /**
   * Creates a configured password credential provider plugin instance.
   *
   * @param array $users
   *   The configured usernames.
   * @param array $passwords
   *   The configured passwords, in the same order as $users.
   *
   * @return \Drupal\shield\ShieldCredentialProviderInterface
   *   The configured plugin instance.
   */
  protected function createProvider(array $users, array $passwords) {
    return $this->container->get('plugin.manager.shield.credential_provider')
      ->createInstance('password', ['users' => $users, 'passwords' => $passwords]);
  }

}
