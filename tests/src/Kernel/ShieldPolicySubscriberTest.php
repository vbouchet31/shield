<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests ShieldPolicySubscriber: request bypasses and credential checking.
 *
 * Covers the scenarios formerly split between ShieldMiddlewareTest (request
 * credential parsing) and ShieldBypassTest (subrequest/CLI/basic_auth
 * bypasses), now exercised through a real ShieldPolicy config entity and the
 * kernel.request event instead of direct ShieldMiddleware instantiation.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\EventSubscriber\ShieldPolicySubscriber
 */
#[RunTestsInSeparateProcesses]
class ShieldPolicySubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'key',
    'shield',
    'shield_key',
    'shield_password',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['shield']);
    // The basic_auth provider loads user entities and records flood events
    // while it checks the headers Shield leaves in place.
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user']);

    $this->config('shield.settings')
      // The tests run under the CLI SAPI, which Shield lets through by
      // default; the CLI test switches this back on.
      ->set('allow_cli', FALSE)
      ->set('debug_header', TRUE)
      ->save();

    ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'weight' => 0,
      'credential_providers' => [
        [
          'id' => 'password',
          'users' => ['user'],
          'passwords' => ['password'],
        ],
      ],
    ])->save();
  }

  /**
   * Tests the server variables the credentials are read from.
   *
   * @param array $server
   *   Server variables to send the request with.
   * @param bool $expected
   *   Whether the request is expected to pass the shield.
   *
   * @covers ::getRequestCredentials
   * @covers ::onRequest
   *
   * @dataProvider providerCredentialSources
   */
  public function testCredentialSources(array $server, $expected): void {
    $event = $this->dispatchRequest(Request::create('/', 'GET', [], [], [], $server));

    if ($expected) {
      $this->assertNull($event->getResponse());
      $this->assertSame('authenticated', $event->getRequest()->attributes->get('_shield_status'));
    }
    else {
      $this->assertNotNull($event->getResponse());
      $this->assertSame(401, $event->getResponse()->getStatusCode());
    }
  }

  /**
   * Data provider for ::testCredentialSources().
   *
   * @return array[]
   *   Test cases, each holding server variables and the expected outcome.
   */
  public static function providerCredentialSources(): array {
    $valid = 'Basic ' . base64_encode('user:password');
    $invalid = 'Basic ' . base64_encode('user:wrong-password');

    return [
      'no credentials' => [[], FALSE],
      'PHP_AUTH_USER' => [
        ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'password'],
        TRUE,
      ],
      'PHP_AUTH_USER, wrong password' => [
        ['PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'wrong-password'],
        FALSE,
      ],
      'HTTP_AUTHORIZATION' => [['HTTP_AUTHORIZATION' => $valid], TRUE],
      'HTTP_AUTHORIZATION, wrong password' => [
        ['HTTP_AUTHORIZATION' => $invalid],
        FALSE,
      ],
      'REDIRECT_HTTP_AUTHORIZATION' => [
        ['REDIRECT_HTTP_AUTHORIZATION' => $valid],
        TRUE,
      ],
      // The variables a rewrite rule such as
      // "RewriteRule .* - [E=REMOTE_USER:%{HTTP:Authorization}]" leaves the
      // header in on CGI style hosting.
      'REMOTE_USER' => [['REMOTE_USER' => $valid], TRUE],
      'REMOTE_USER, wrong password' => [['REMOTE_USER' => $invalid], FALSE],
      'REDIRECT_REMOTE_USER' => [['REDIRECT_REMOTE_USER' => $valid], TRUE],
      // A REMOTE_USER holding an authenticated user name instead of the
      // forwarded header must not be mistaken for credentials.
      'REMOTE_USER without header' => [['REMOTE_USER' => 'user'], FALSE],
      'unsupported authentication scheme' => [
        ['HTTP_AUTHORIZATION' => 'Bearer ' . base64_encode('user:password')],
        FALSE,
      ],
      'malformed credentials' => [
        ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user')],
        FALSE,
      ],
      'credentials that are not base64' => [
        ['HTTP_AUTHORIZATION' => 'Basic user:password'],
        FALSE,
      ],
    ];
  }

  /**
   * Tests that a password containing a colon is handled.
   *
   * @covers ::getRequestCredentials
   */
  public function testPasswordWithColon(): void {
    $policy = ShieldPolicy::load('test_policy');
    $policy->set('credential_providers', [
      ['id' => 'password', 'users' => ['user'], 'passwords' => ['pass:word']],
    ]);
    $policy->save();

    $server = [
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pass:word'),
    ];
    $event = $this->dispatchRequest(Request::create('/', 'GET', [], [], [], $server));
    $this->assertNull($event->getResponse());
  }

  /**
   * Tests that no error is triggered when no credentials are configured.
   *
   * @covers ::onRequest
   */
  public function testMissingConfiguredCredentials(): void {
    $policy = ShieldPolicy::load('test_policy');
    $policy->set('credential_providers', [
      ['id' => 'key', 'user' => 'user', 'pass_key' => 'no_such_key'],
    ]);
    $policy->save();

    $server = [
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:password'),
    ];
    $event = $this->dispatchRequest(Request::create('/', 'GET', [], [], [], $server));
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Basic realm="Test policy (test_policy)"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Tests that a policy with two providers authenticates either credential.
   *
   * @covers ::onRequest
   */
  public function testMultipleCredentialProvidersAuthenticateWithEither(): void {
    Key::create([
      'id' => 'shield_test_key',
      'label' => 'Shield test key',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'key-secret',
        'base64_encoded' => FALSE,
      ],
    ])->save();

    $policy = ShieldPolicy::load('test_policy');
    $policy->set('credential_providers', [
      ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ['id' => 'key', 'user' => 'key-user', 'pass_key' => 'shield_test_key'],
    ]);
    $policy->save();

    // The first (password) provider matches.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('user', 'password'));
    $this->assertNull($event->getResponse());
    $this->assertSame('authenticated', $event->getRequest()->attributes->get('_shield_status'));

    // The second (key) provider matches.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('key-user', 'key-secret'));
    $this->assertNull($event->getResponse());
    $this->assertSame('authenticated', $event->getRequest()->attributes->get('_shield_status'));

    // Neither provider matches.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('user', 'wrong-password'));
    $this->assertNotNull($event->getResponse());
    $this->assertSame(401, $event->getResponse()->getStatusCode());
  }

  /**
   * Tests that a policy with no credential providers is skipped.
   *
   * @covers ::onRequest
   */
  public function testZeroCredentialProvidersSkipsPolicy(): void {
    $policy = ShieldPolicy::load('test_policy');
    $policy->set('credential_providers', []);
    $policy->save();

    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNull($event->getResponse());
    $this->assertSame('skipped (no configured policy)', $event->getRequest()->attributes->get('_shield_status'));
  }

  /**
   * Tests that the governing policy's ID is only recorded once one governs.
   *
   * @covers ::onRequest
   */
  public function testGoverningPolicyAttribute(): void {
    // No policy governs: nothing to record.
    $policy = ShieldPolicy::load('test_policy');
    $policy->set('credential_providers', []);
    $policy->save();

    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNull($event->getResponse());
    $this->assertFalse($event->getRequest()->attributes->has('_shield_policy'));

    $policy->set('credential_providers', [
      ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
    ])->save();

    // The policy governs the challenge...
    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNotNull($event->getResponse());
    $this->assertSame('test_policy', $event->getRequest()->attributes->get('_shield_policy'));

    // ...and the successful authentication that follows it.
    $event = $this->dispatchRequest($this->createAuthenticatedRequest('user', 'password'));
    $this->assertNull($event->getResponse());
    $this->assertSame('test_policy', $event->getRequest()->attributes->get('_shield_policy'));
  }

  /**
   * Tests that a subrequest is never challenged.
   *
   * @covers ::onRequest
   */
  public function testSubrequestIsBypassed(): void {
    $event = $this->dispatchRequest(Request::create('/'), HttpKernelInterface::SUB_REQUEST);
    $this->assertNull($event->getResponse());

    // The same request is challenged as a main request.
    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNotNull($event->getResponse());
    $this->assertSame(401, $event->getResponse()->getStatusCode());
  }

  /**
   * Tests that the command line is let through only when configured to be.
   *
   * @covers ::onRequest
   */
  public function testCliIsBypassedWhenAllowed(): void {
    // This test depends on running under the CLI SAPI, as PHPUnit does.
    $this->assertSame('cli', PHP_SAPI);

    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNotNull($event->getResponse(), 'With allow_cli off the request is challenged.');
    $this->assertSame(401, $event->getResponse()->getStatusCode());

    $this->config('shield.settings')->set('allow_cli', TRUE)->save();

    $event = $this->dispatchRequest(Request::create('/'));
    $this->assertNull($event->getResponse());
    $this->assertSame('skipped (cli)', $event->getRequest()->attributes->get('_shield_status'));
  }

  /**
   * Tests that Shield's credentials are hidden from basic_auth by default.
   *
   * Core's basic_auth provider reads PHP_AUTH_USER and PHP_AUTH_PW from the
   * request headers. Left in place, the Shield pair would be taken for a
   * Drupal account and the request would end in a 403.
   *
   * @covers ::passThrough
   */
  public function testBasicAuthHeadersAreUnset(): void {
    $this->assertTrue($this->config('shield.settings')->get('unset_basic_auth_headers'), 'Unsetting the headers is the default.');

    $event = $this->dispatchRequest($this->createAuthenticatedRequest('user', 'password'));
    $this->assertNull($event->getResponse());
    $this->assertSame('authenticated', $event->getRequest()->attributes->get('_shield_status'));
    $this->assertFalse($event->getRequest()->headers->has('PHP_AUTH_USER'));
    $this->assertFalse($event->getRequest()->headers->has('PHP_AUTH_PW'));
  }

  /**
   * Tests that the headers are kept when a site opts out of unsetting them.
   *
   * @covers ::passThrough
   */
  public function testBasicAuthHeadersAreKeptWhenConfigured(): void {
    $this->config('shield.settings')->set('unset_basic_auth_headers', FALSE)->save();

    $event = $this->dispatchRequest($this->createAuthenticatedRequest('user', 'password'));
    $this->assertNull($event->getResponse());
    $this->assertSame('user', $event->getRequest()->headers->get('PHP_AUTH_USER'));
    $this->assertSame('password', $event->getRequest()->headers->get('PHP_AUTH_PW'));
  }

  /**
   * Tests that the headers of a real Drupal account are kept.
   *
   * Stripping them would log the account out again right after the
   * basic_auth bypass let it through.
   *
   * @covers ::passThrough
   */
  public function testBasicAuthHeadersAreKeptForDrupalAccounts(): void {
    $this->config('shield.settings')->set('basic_auth_bypass', TRUE)->save();

    $account = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'editor',
      'pass' => 'editor-secret',
      'status' => 1,
    ]);
    $account->save();

    $event = $this->dispatchRequest($this->createAuthenticatedRequest('editor', 'editor-secret'));
    $this->assertNull($event->getResponse());
    $this->assertSame('skipped (basic auth)', $event->getRequest()->attributes->get('_shield_status'));
    $this->assertSame('editor', $event->getRequest()->headers->get('PHP_AUTH_USER'));
    $this->assertSame('editor-secret', $event->getRequest()->headers->get('PHP_AUTH_PW'));
  }

  /**
   * Builds a request carrying basic authentication credentials.
   *
   * @param string $user
   *   The user name to send.
   * @param string $pass
   *   The password to send.
   * @param array $server
   *   Additional server variables.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createAuthenticatedRequest(string $user, string $pass, array $server = []): Request {
    return Request::create('/', 'GET', [], [], [], $server + [
      'PHP_AUTH_USER' => $user,
      'PHP_AUTH_PW' => $pass,
    ]);
  }

  /**
   * Runs the request through ShieldPolicySubscriber::onRequest() directly.
   *
   * Calling the subscriber's own method, rather than dispatching the event
   * through the shared event_dispatcher, keeps this test scoped to the class
   * under test - dispatching for real would also invoke core's
   * RouterListener, which throws a NotFoundHttpException for any path this
   * minimal Kernel test has not registered a route for.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to pass through the subscriber.
   * @param int $type
   *   The request type, HttpKernelInterface::MAIN_REQUEST by default.
   *
   * @return \Symfony\Component\HttpKernel\Event\RequestEvent
   *   The event, holding any response ShieldPolicySubscriber set.
   */
  protected function dispatchRequest(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new RequestEvent($kernel, $request, $type);
    $this->container->get('shield.policy_subscriber')->onRequest($event);
    return $event;
  }

}
