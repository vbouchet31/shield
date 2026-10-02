<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests that the page cache kill switch fires exactly when it must.
 *
 * This is the regression the plugin-based redesign targets: page_cache's
 * cache ID has no Authorization-header awareness, so any response Shield
 * lets through on a path it also protects must never be cached, regardless
 * of *why* the request was let through.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\EventSubscriber\ShieldPolicySubscriber
 */
#[RunTestsInSeparateProcesses]
class ShieldKillSwitchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'path_alias',
    'shield',
    'shield_http_method',
    'shield_password',
    'shield_path',
    'shield_user_agent',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['shield', 'system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);

    // The tests run under the CLI SAPI, which Shield lets through by
    // default.
    $this->config('shield.settings')->set('allow_cli', FALSE)->save();
  }

  /**
   * Tests that the kill switch fires even when basic_auth_bypass applies.
   *
   * @covers ::onRequest
   */
  public function testKillSwitchFiresOnBasicAuthBypass(): void {
    $this->config('shield.settings')->set('basic_auth_bypass', TRUE)->save();
    $this->createPathPolicy('/admin');

    $account = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'editor',
      'pass' => 'editor-secret',
      'status' => 1,
    ]);
    $account->save();

    $event = $this->dispatchRequest(Request::create('/admin', 'GET', [], [], [], [
      'PHP_AUTH_USER' => 'editor',
      'PHP_AUTH_PW' => 'editor-secret',
    ]));
    $this->assertNull($event->getResponse());
    $this->assertKillSwitchTriggered();
  }

  /**
   * Tests that a policy with zero cacheability-affecting conditions still
   * fires the kill switch for every request it protects.
   *
   * An HTTP-method-only policy has no Path (or other cacheability-affecting)
   * condition, so ShieldPolicy::matchesCacheableConditions() vacuously
   * returns TRUE for it - this is the deliberate worst-case default, not an
   * oversight: without it, a method-scoped policy could be assumed safe to
   * cache when it is not.
   *
   * @covers ::onRequest
   */
  public function testKillSwitchFiresForVacuouslyTrueCacheabilityPolicy(): void {
    ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'conditions' => [
        ['id' => 'http_method', 'methods' => ['POST']],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ])->save();

    // A GET request does not match the governing policy at all - it is
    // let through with "skipped (no matching policy)" - but the sweep still
    // considers every enabled policy for cacheability, independently of
    // whether one of them governs this particular request.
    $event = $this->dispatchRequest(Request::create('/', 'GET'));
    $this->assertNull($event->getResponse());
    $this->assertKillSwitchTriggered();
  }

  /**
   * Tests that a non-cache-key-aware condition never vetoes the kill switch.
   *
   * Regression test: a policy scoped by Path (cache-key-aware, gates the
   * sweep) AND User agent (not cache-key-aware, must not gate the sweep).
   * A request under the protected path whose User-Agent does *not* match
   * the configured list must still trigger the kill switch, because Path
   * alone already puts the request in scope - the request cannot be
   * assumed cacheable just because it happens to fail the User agent
   * condition, or a later request with a listed user agent could be served
   * this one's cached response unchallenged.
   *
   * @covers ::onRequest
   */
  public function testKillSwitchFiresRegardlessOfNonCacheKeyAwareCondition(): void {
    ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'conditions' => [
        ['id' => 'path', 'paths' => '/admin'],
        ['id' => 'user_agent', 'user_agents' => 'Chrome/15'],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ])->save();

    // The request's User-Agent does not match "Chrome/15", so the User
    // agent condition fails and the policy does not govern this request at
    // all (matchesConditions() ANDs every condition, Path AND User agent) -
    // the request passes through unchallenged. But it is still within the
    // policy's Path scope, so the sweep must kill the page cache: this is
    // exactly the reported bug, where an unchallenged response like this
    // one got cached and was then served to a later request that should
    // have been challenged.
    $event = $this->dispatchRequest(Request::create('/admin', 'GET', [], [], [], [
      'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0',
    ]));
    $this->assertNull($event->getResponse());
    $this->assertSame('skipped (no matching policy)', $event->getRequest()->attributes->get('_shield_status'));
    $this->assertKillSwitchTriggered();
  }

  /**
   * Tests that an unprotected path never triggers the kill switch.
   *
   * @covers ::onRequest
   */
  public function testKillSwitchDoesNotFireForUnprotectedPath(): void {
    $this->createPathPolicy('/admin');

    // "/" does not match the "/admin" policy at all, so the request passes
    // through untouched - no 401, and no kill switch trigger either.
    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNull($event->getResponse());
    $this->assertFalse($this->killSwitchTriggered(), 'The kill switch must not fire for a request no enabled policy matches.');
  }

  /**
   * Tests that zero enabled policies never triggers the kill switch.
   *
   * @covers ::onRequest
   */
  public function testKillSwitchDoesNotFireWhenDisabled(): void {
    $event = $this->dispatchRequest(Request::create('/admin'));
    $this->assertNull($event->getResponse());
    $this->assertFalse($this->killSwitchTriggered());
  }

  /**
   * Creates and saves a Shield policy scoped to a path via a Path condition.
   *
   * @param string $path
   *   The path pattern the policy is scoped to.
   */
  protected function createPathPolicy(string $path): void {
    ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'conditions' => [
        ['id' => 'path', 'paths' => $path],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ])->save();
  }

  /**
   * Asserts that the page cache kill switch was triggered.
   */
  protected function assertKillSwitchTriggered(): void {
    $this->assertTrue($this->killSwitchTriggered(), 'The kill switch must fire.');
  }

  /**
   * Checks whether the page cache kill switch was triggered.
   *
   * @return bool
   *   TRUE if the kill switch denies caching the current response.
   */
  protected function killSwitchTriggered(): bool {
    $policy = $this->container->get('page_cache_kill_switch');
    return $policy->check(new Response(), Request::create('/')) === ResponsePolicyInterface::DENY;
  }

  /**
   * Runs the request through ShieldPolicySubscriber::onRequest() directly.
   *
   * See ShieldPolicySubscriberTest for why this bypasses the shared
   * event_dispatcher.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to pass through the subscriber.
   *
   * @return \Symfony\Component\HttpKernel\Event\RequestEvent
   *   The event, holding any response ShieldPolicySubscriber set.
   */
  protected function dispatchRequest(Request $request): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('shield.policy_subscriber')->onRequest($event);
    return $event;
  }

}
