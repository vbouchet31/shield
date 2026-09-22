<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests weight-ordered, first-match governing policy selection.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\EventSubscriber\ShieldPolicySubscriber
 */
#[RunTestsInSeparateProcesses]
class ShieldPolicyOrderingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password', 'shield_path', 'path_alias', 'system', 'user'];

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
   * Tests that the lowest-weight matching policy governs the request.
   *
   * @covers ::onRequest
   */
  public function testLowestWeightGoverns(): void {
    $this->createPathPolicy('policy_a', '/admin', 0, 'a-user', 'a-password');
    $this->createPathPolicy('policy_b', '/admin', 10, 'b-user', 'b-password');

    // Policy A's credentials, the lower-weight overlapping policy, work.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('a-user', 'a-password'));
    $this->assertNull($event->getResponse());

    // Policy B's credentials do not, because policy A governs instead.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('b-user', 'b-password'));
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Basic realm="policy_a (policy_a)"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Tests that reordering the weights flips which policy governs.
   *
   * @covers ::onRequest
   */
  public function testReorderingFlipsGoverningPolicy(): void {
    $this->createPathPolicy('policy_a', '/admin', 0, 'a-user', 'a-password');
    $this->createPathPolicy('policy_b', '/admin', 10, 'b-user', 'b-password');

    ShieldPolicy::load('policy_a')->set('weight', 10)->save();
    ShieldPolicy::load('policy_b')->set('weight', 0)->save();

    // Policy B now governs, since it has the lower weight.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('b-user', 'b-password'));
    $this->assertNull($event->getResponse());

    $event = $this->dispatchRequest($this->createAuthenticatedRequest('a-user', 'a-password'));
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame('Basic realm="policy_b (policy_b)"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Tests that a disabled lower-weight policy is skipped entirely.
   *
   * @covers ::onRequest
   */
  public function testDisabledLowerWeightPolicyIsSkipped(): void {
    $this->createPathPolicy('policy_a', '/admin', 0, 'a-user', 'a-password');
    $this->createPathPolicy('policy_b', '/admin', 10, 'b-user', 'b-password');

    ShieldPolicy::load('policy_a')->set('status', FALSE)->save();

    // Policy B now governs, since policy A is disabled and not loaded at
    // all - not merely deprioritized.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('b-user', 'b-password'));
    $this->assertNull($event->getResponse());

    $event = $this->dispatchRequest($this->createAuthenticatedRequest('a-user', 'a-password'));
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame('Basic realm="policy_b (policy_b)"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Tests that a non-overlapping policy never governs another's path.
   *
   * @covers ::onRequest
   */
  public function testNonOverlappingPolicyDoesNotGovern(): void {
    $this->createPathPolicy('policy_a', '/admin', 0, 'a-user', 'a-password');
    $this->createPathPolicy('policy_b', '/api', 10, 'b-user', 'b-password');

    // Policy B's credentials do not open /admin: policy B does not match
    // this path at all, regardless of weight.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('b-user', 'b-password'));
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame('Basic realm="policy_a (policy_a)"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Creates and saves a Shield policy scoped to a path via a Path condition.
   *
   * @param string $id
   *   The policy ID.
   * @param string $path
   *   The path pattern the policy is scoped to.
   * @param int $weight
   *   The policy's weight.
   * @param string $user
   *   The configured username.
   * @param string $pass
   *   The configured password.
   */
  protected function createPathPolicy(string $id, string $path, int $weight, string $user, string $pass): void {
    ShieldPolicy::create([
      'id' => $id,
      'label' => $id,
      'status' => TRUE,
      'weight' => $weight,
      'conditions' => [
        ['id' => 'path', 'paths' => $path],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => [$user], 'passwords' => [$pass]],
      ],
    ])->save();
  }

  /**
   * Builds a request to "/admin" carrying basic authentication credentials.
   *
   * @param string $user
   *   The user name to send.
   * @param string $pass
   *   The password to send.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createAuthenticatedRequest(string $user, string $pass): Request {
    return Request::create('/admin', 'GET', [], [], [], [
      'PHP_AUTH_USER' => $user,
      'PHP_AUTH_PW' => $pass,
    ]);
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
