<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\shield\Entity\ShieldPolicy;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that distinct policies use distinct HTTP Basic Auth realms.
 *
 * RFC 7617's protection space is scheme+host+port+realm: a browser only
 * reuses cached credentials automatically for requests that match all four.
 * Giving every policy its own realm is what stops one policy's cached
 * credentials from being silently offered to (or evicting the cache for)
 * another policy on the same site. This test cannot observe a browser's own
 * credential cache - BrowserTestBase never auto-resends Authorization - but
 * it verifies the server-side contract that behavior depends on: distinct
 * realm strings per policy, and fully independent credential checks between
 * them.
 *
 * @group shield
 */
#[RunTestsInSeparateProcesses]
class ShieldRealmIsolationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password', 'shield_path', 'path_alias'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createPathPolicy('policy_a', '/user/login', 'a-user', 'a-password');
    $this->createPathPolicy('policy_b', '/user/register', 'b-user', 'b-password');
  }

  /**
   * Tests that each policy's path challenges with its own, distinct realm.
   */
  public function testPoliciesChallengeWithDistinctRealms(): void {
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('WWW-Authenticate', 'Basic realm="policy_a (policy_a)"');

    $this->drupalGet('user/register');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('WWW-Authenticate', 'Basic realm="policy_b (policy_b)"');
  }

  /**
   * Tests that one policy's credentials do not authenticate another's path.
   */
  public function testCredentialsDoNotCrossPolicies(): void {
    $credentials_a = ['Authorization' => 'Basic ' . base64_encode('a-user:a-password')];
    $credentials_b = ['Authorization' => 'Basic ' . base64_encode('b-user:b-password')];

    $this->drupalGet('user/login', [], $credentials_a);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('user/register', [], $credentials_a);
    $this->assertSession()->statusCodeEquals(401);

    $this->drupalGet('user/register', [], $credentials_b);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet('user/login', [], $credentials_b);
    $this->assertSession()->statusCodeEquals(401);
  }

  /**
   * Tests that visiting policy B leaves policy A's realm and access unchanged.
   *
   * The Authorization header is set once on the session, mirroring a browser
   * that has cached policy A's credentials and keeps auto-sending them on
   * every request until a different protection space forces a fresh prompt.
   */
  public function testVisitingOtherPolicyDoesNotAffectFirst(): void {
    $this->getSession()->setRequestHeader('Authorization', 'Basic ' . base64_encode('a-user:a-password'));

    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);

    // The still-attached policy A credentials are not valid for policy B's
    // path: a distinct protection space means a distinct challenge.
    $this->drupalGet('user/register');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('WWW-Authenticate', 'Basic realm="policy_b (policy_b)"');

    // Policy A's own realm and access are unaffected by having just visited
    // policy B.
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Creates and saves a Shield policy scoped to a path via a Path condition.
   *
   * @param string $id
   *   The policy ID.
   * @param string $path
   *   The path pattern the policy is scoped to.
   * @param string $user
   *   The configured username.
   * @param string $pass
   *   The configured password.
   */
  protected function createPathPolicy(string $id, string $path, string $user, string $pass): void {
    ShieldPolicy::create([
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
      'conditions' => [
        ['id' => 'path', 'paths' => $path],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => [$user], 'passwords' => [$pass]],
      ],
    ])->save();
  }

}
