<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\key\Entity\Key;
use Drupal\shield\Entity\ShieldPolicy;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Shield policies end to end, over real HTTP requests.
 *
 * Replaces ShieldTest and ShieldJsonApiTest: scenarios still relevant to the
 * plugin-based, multi-policy architecture are ported here. IP allowlist, the
 * multikey credential provider and the removed global settings form UI are
 * out of scope and have no replacement.
 *
 * @group shield
 */
#[RunTestsInSeparateProcesses]
class ShieldPolicyFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'shield',
    'shield_password',
    'shield_key',
    'shield_path',
    'key',
    'basic_auth',
    'dblog',
    // Provides router_test.11, a route that opts into basic_auth. Core's own
    // BasicAuthTest uses it for the same reason: basic_auth is not a global
    // authentication provider, so it never applies to ordinary HTML routes.
    'router_test',
    'page_cache',
    'dynamic_page_cache',
    'node',
    'serialization',
    'jsonapi',
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

    $this->config('shield.settings')->set('debug_header', TRUE)->save();
  }

  /**
   * Validate debug_header config adds the X-Shield-Status header.
   */
  public function testShieldHeader(): void {
    // No enabled policy: nothing to challenge.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'disabled');

    $this->config('shield.settings')->set('debug_header', FALSE)->save();
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderDoesNotExist('X-Shield-Status');

    $this->createPasswordPolicy();

    // The challenge itself carries no header while the option is off.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderDoesNotExist('X-Shield-Status');

    $this->config('shield.settings')->set('debug_header', TRUE)->save();
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

  /**
   * Validate debug_header also adds X-Shield-Policy-Name once one governs.
   */
  public function testShieldPolicyNameHeader(): void {
    // No enabled policy: nothing governs, so nothing to name.
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderDoesNotExist('X-Shield-Policy-Name');

    $this->createPasswordPolicy();

    // The policy governs the challenge...
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Policy-Name', 'test_policy');

    // ...and the successful authentication that follows it.
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Policy-Name', 'test_policy');

    // The option controls both headers together.
    $this->config('shield.settings')->set('debug_header', FALSE)->save();
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderDoesNotExist('X-Shield-Policy-Name');
  }

  /**
   * Validate a policy's status controls whether it challenges requests.
   */
  public function testShieldEnable(): void {
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'disabled');

    $policy = $this->createPasswordPolicy(['status' => FALSE]);
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'disabled');

    $policy->set('status', TRUE)->save();
    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

  /**
   * Validate the authentication realm reflects the policy's own label and ID.
   */
  public function testAuthRealm(): void {
    $policy = $this->createPasswordPolicy(['label' => 'Hello world!']);

    $this->drupalGet('user');
    $this->assertSession()->responseHeaderContains('WWW-Authenticate', 'Basic realm="Hello world! (' . $policy->id() . ')"');

    $policy->set('label', 'Hello entire world!')->save();
    $this->drupalGet('user');
    $this->assertSession()->responseHeaderContains('WWW-Authenticate', 'Basic realm="Hello entire world! (' . $policy->id() . ')"');
  }

  /**
   * Validate the password credential provider end to end.
   */
  public function testShieldCred(): void {
    $this->createPasswordPolicy();

    $this->drupalGet('user');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');

    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'authenticated');
  }

  /**
   * Validate that several configured username/password pairs are accepted.
   */
  public function testShieldMultipleCred(): void {
    $this->config('shield.settings')->set('log_authentication', TRUE)->save();
    $this->createPasswordPolicy([
      'credential_providers' => [
        [
          'id' => 'password',
          'users' => ['user', 'second', 'third'],
          'passwords' => ['password', 'secret', 'another'],
        ],
      ],
    ]);

    // Every configured pair authenticates, and the log names the pair that
    // was used.
    foreach (['user' => 'password', 'second' => 'secret', 'third' => 'another'] as $user => $pass) {
      $this->drupalGet('user/login', [], ['Authorization' => 'Basic ' . base64_encode("$user:$pass")]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'authenticated');

      $entries = $this->getShieldLogEntries();
      $variables = unserialize(end($entries)->variables, ['allowed_classes' => FALSE]);
      $this->assertSame($user, $variables['%user']);
    }

    // A password belonging to another user does not authenticate.
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('second:another')]);
    $this->assertSession()->statusCodeEquals(401);

    // An unknown user does not authenticate.
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('fourth:password')]);
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Validate that a Key managed password may still contain a pipe.
   */
  public function testShieldKeyPasswordWithPipe(): void {
    Key::create([
      'id' => 'shield_pipe',
      'label' => 'Shield pipe',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'pass|word',
        'base64_encoded' => FALSE,
      ],
    ])->save();

    $this->createPasswordPolicy([
      'credential_providers' => [
        ['id' => 'key', 'user' => 'user', 'pass_key' => 'shield_pipe'],
      ],
    ]);

    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:pass|word')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'authenticated');
  }

  /**
   * Validate the basic_auth headers unset feature.
   */
  public function testShieldWithBasicAuth(): void {
    // We don't need to test the case with unset_basic_auth_headers set to
    // TRUE, as it is the default value and is exercised by testShieldCred().
    $this->config('shield.settings')->set('unset_basic_auth_headers', FALSE)->save();
    $this->createPasswordPolicy();

    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Validate the basic_auth bypass is off unless it is configured on.
   */
  public function testShieldBasicAuthBypass(): void {
    $this->createPasswordPolicy();
    $account = $this->drupalCreateUser();
    $credentials = [
      'Authorization' => 'Basic ' . base64_encode($account->getAccountName() . ':' . $account->passRaw),
    ];

    // A Drupal account is not a Shield credential by default.
    $this->drupalGet('router_test/test11', [], $credentials);
    $this->assertSession()->statusCodeEquals(401);

    $this->config('shield.settings')->set('basic_auth_bypass', TRUE)->save();

    // With the option on the account passes Shield, and basic_auth then logs
    // it in: the route requires an authenticated user and prints its name.
    $this->drupalGet('router_test/test11', [], $credentials);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'skipped (basic auth)');
    $this->assertSession()->pageTextContains($account->getAccountName());

    // The Shield credentials keep working alongside it.
    $this->drupalGet('<front>', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'authenticated');

    // A wrong password is still rejected by Shield, not passed through.
    $this->drupalGet('<front>', [], [
      'Authorization' => 'Basic ' . base64_encode($account->getAccountName() . ':wrong'),
    ]);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

  /**
   * Validate a blocked account cannot open the shield.
   */
  public function testShieldBasicAuthBypassBlockedAccount(): void {
    $this->config('shield.settings')->set('basic_auth_bypass', TRUE)->save();
    $this->createPasswordPolicy();

    $account = $this->drupalCreateUser();
    $credentials = [
      'Authorization' => 'Basic ' . base64_encode($account->getAccountName() . ':' . $account->passRaw),
    ];
    $this->drupalGet('router_test/test11', [], $credentials);
    $this->assertSession()->statusCodeEquals(200);

    $account->block()->save();

    $this->drupalGet('router_test/test11', [], $credentials);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

  /**
   * Validate successful authentications are only logged when configured.
   */
  public function testShieldAuthenticationLogging(): void {
    $this->createPasswordPolicy();

    // The login form is requested throughout, as it is served directly: a
    // path that redirects would be counted as two authenticated requests.
    $path = 'user/login';
    $credentials = ['Authorization' => 'Basic ' . base64_encode('user:password')];

    // Nothing is logged while the option is off, whether the credentials are
    // accepted or rejected.
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(401);
    $this->drupalGet($path, [], $credentials);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $this->getShieldLogEntries());

    $this->config('shield.settings')->set('log_authentication', TRUE)->save();

    // A rejected request is still not logged.
    $this->drupalGet($path, [], ['Authorization' => 'Basic ' . base64_encode('user:wrong')]);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSame([], $this->getShieldLogEntries());

    // A successful authentication records the user, IP address and path.
    $this->drupalGet($path, [], $credentials);
    $this->assertSession()->statusCodeEquals(200);
    $entries = $this->getShieldLogEntries();
    $this->assertCount(1, $entries);
    $this->assertSame('Authenticated %user from %ip on %path.', $entries[0]->message);
    $variables = unserialize($entries[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertSame('user', $variables['%user']);
    $this->assertSame('/' . $path, $variables['%path']);
    // The address the test runner reaches the site from depends on the
    // environment, so only assert that a usable one was recorded.
    $client_ip = $variables['%ip'];
    $this->assertNotFalse(filter_var($client_ip, FILTER_VALIDATE_IP));
  }

  /**
   * Validate adding, listing and deleting credential providers via the UI.
   */
  public function testCredentialProviderAdminUi(): void {
    Key::create([
      'id' => 'shield_ui_key',
      'label' => 'Shield UI key',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'key-secret',
        'base64_encoded' => FALSE,
      ],
    ])->save();

    // Disabled: a status-TRUE policy with no conditions yet would match
    // every request and 401-challenge the test's own login and form
    // submissions, same as PathConditionFormTest::testEmptyPathsIsRejected().
    $this->createPasswordPolicy(['status' => FALSE]);

    $account = $this->drupalCreateUser(['administer shield']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/shield/policy/credential-provider/add/test_policy/key');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['user' => 'key-user', 'pass_key' => 'shield_ui_key'], 'Save');

    $policy = ShieldPolicy::load('test_policy');
    $providers = $policy->getCredentialProviders();
    $this->assertCount(2, $providers);
    end($providers);
    $key_delta = key($providers);

    $this->drupalGet('admin/config/system/shield/policy/test_policy');
    $this->assertSession()->pageTextContains('Username and password');
    $this->assertSession()->pageTextContains('Key');

    // Enabling the policy protects the rest of the site, including this
    // admin session, from here on: the Authorization header is attached to
    // every remaining request the same way testShieldBasicAuthBypass() does.
    $policy->set('status', TRUE)->save();
    $this->drupalGet('admin/config/system/shield/policy/test_policy', [], [
      'Authorization' => 'Basic ' . base64_encode('user:password'),
    ]);
    $this->assertSession()->statusCodeEquals(200);

    $this->getSession()->setRequestHeader('Authorization', '');

    // Either credential set authenticates an anonymous request.
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('key-user:key-secret')]);
    $this->assertSession()->statusCodeEquals(200);

    // Deleting the key provider through the UI removes only that entry.
    $this->drupalGet("admin/config/system/shield/policy/credential-provider/delete/test_policy/key/$key_delta", [], [
      'Authorization' => 'Basic ' . base64_encode('user:password'),
    ]);
    $this->submitForm([], 'Delete');
    $this->getSession()->setRequestHeader('Authorization', '');

    $policy = ShieldPolicy::load('test_policy');
    $this->assertCount(1, $policy->getCredentialProviders());

    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('key-user:key-secret')]);
    $this->assertSession()->statusCodeEquals(401);
    $this->drupalGet('user', [], ['Authorization' => 'Basic ' . base64_encode('user:password')]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * A path excluded via a negated Path condition must not weaken the rest of
   * the site, and removing the exclusion must restore protection there
   * immediately.
   *
   * Shield's subscriber runs in front of the page cache, so a response that
   * was allowed through on an excluded path must never make the site
   * reachable on any other path.
   *
   * @see https://www.drupal.org/project/shield/issues/3491255
   */
  public function testExcludedPathDoesNotWeakenOtherPaths(): void {
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateNode(['type' => 'article', 'title' => 'Test article']);
    // JSON:API derives its routes from the resource types, which only exist
    // once the bundle does.
    $this->container->get('router.builder')->rebuild();

    $this->createPasswordPolicy([
      'conditions' => [
        ['id' => 'path', 'negate' => TRUE, 'paths' => "/jsonapi\n/jsonapi/*"],
      ],
    ]);

    // Anonymous users may view published content, so JSON:API answers - the
    // negated condition means no policy governs this path at all.
    $this->drupalGet('jsonapi/node/article');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'skipped (no matching policy)');

    // Every other path must still be shielded.
    $this->assertShielded('user/login');
    $this->assertShielded('');
    $this->assertShielded('node/1');

    // Sending Shield's own credentials on the excluded path - mirroring an
    // API client that keeps sending the same Authorization header on every
    // request it makes - must not unlock anything else either.
    $authorization = ['Authorization' => 'Basic ' . base64_encode('user:password')];
    $this->drupalGet('jsonapi/node/article', [], $authorization);
    $this->assertSession()->statusCodeEquals(200);
    $this->getSession()->setRequestHeader('Authorization', '');
    $this->assertShielded('user/login');

    // Removing the exclusion restores protection on the previously-excluded
    // path as well.
    $policy = ShieldPolicy::load('test_policy');
    $policy->set('conditions', [])->save();
    $this->assertShielded('jsonapi/node/article');
    $this->assertShielded('user/login');
  }

  /**
   * Asserts that a path is answered with the Shield authentication prompt.
   *
   * @param string $path
   *   The path to request.
   */
  protected function assertShielded(string $path): void {
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

  /**
   * Creates and saves a Shield policy using the password credential provider.
   *
   * @param array $overrides
   *   Values overriding the defaults passed to ShieldPolicy::create().
   *
   * @return \Drupal\shield\Entity\ShieldPolicy
   *   The saved policy.
   */
  protected function createPasswordPolicy(array $overrides = []): ShieldPolicy {
    $policy = ShieldPolicy::create($overrides + [
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ]);
    $policy->save();
    return $policy;
  }

  /**
   * Returns the log entries Shield has recorded, oldest first.
   *
   * @return object[]
   *   The watchdog rows of the shield channel.
   */
  protected function getShieldLogEntries() {
    return \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message', 'variables'])
      ->condition('type', 'shield')
      ->orderBy('wid')
      ->execute()
      ->fetchAll();
  }

}
