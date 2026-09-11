<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the request level exceptions and the basic_auth header handling.
 *
 * Covers the bypasses that do not have a test of their own: subrequests, the
 * command line, the IP allowlist, and what the decorated kernel receives once
 * Shield has let a request through.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 */
class ShieldBypassTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'key',
    'path_alias',
    'shield',
    'system',
    'user',
  ];

  /**
   * The request the decorated kernel received, if Shield let one through.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $forwardedRequest;

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
      ->set('shield_enable', TRUE)
      // The tests run under the CLI SAPI, which Shield lets through by
      // default; the CLI test switches this back on.
      ->set('allow_cli', FALSE)
      ->set('debug_header', TRUE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->save();
  }

  /**
   * Tests that a subrequest is never challenged.
   *
   * @covers ::handle
   */
  public function testSubrequestIsBypassed(): void {
    $request = Request::create('/protected');
    $response = $this->handle($request, HttpKernelInterface::SUB_REQUEST);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (subrequest)', $request->headers->get('X-Shield-Status'));

    // The same request is challenged as a main request.
    $response = $this->handle(Request::create('/protected'));
    $this->assertSame(401, $response->getStatusCode());
  }

  /**
   * Tests that the command line is let through only when configured to be.
   *
   * @covers ::handle
   */
  public function testCliIsBypassedWhenAllowed(): void {
    // This test depends on running under the CLI SAPI, as PHPUnit does.
    $this->assertSame('cli', PHP_SAPI);

    $request = Request::create('/protected');
    $response = $this->handle($request);
    $this->assertSame(401, $response->getStatusCode(), 'With allow_cli off the request is challenged.');

    $this->config('shield.settings')->set('allow_cli', TRUE)->save();

    $request = Request::create('/protected');
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (cli)', $request->headers->get('X-Shield-Status'));
  }

  /**
   * Tests the IP allowlist.
   *
   * @param string $allowlist
   *   The configured allowlist.
   * @param string $client_ip
   *   The address the request comes from.
   * @param bool $expected
   *   Whether the request is expected to pass the shield.
   *
   * @dataProvider providerIpAllowlist
   *
   * @covers ::handle
   */
  public function testIpAllowlist(string $allowlist, string $client_ip, bool $expected): void {
    $this->config('shield.settings')->set('allowlist', $allowlist)->save();

    $request = Request::create('/protected', 'GET', [], [], [], ['REMOTE_ADDR' => $client_ip]);
    $response = $this->handle($request);

    $this->assertSame($expected ? 200 : 401, $response->getStatusCode());
    if ($expected) {
      $this->assertSame('skipped (ip)', $request->headers->get('X-Shield-Status'));
    }
  }

  /**
   * Data provider for ::testIpAllowlist().
   *
   * @return array[]
   *   Test cases holding the allowlist, the client address and the outcome.
   */
  public static function providerIpAllowlist(): array {
    return [
      'empty allowlist' => ['', '203.0.113.10', FALSE],
      'allowlist holding only blank lines' => ["\n  \n", '203.0.113.10', FALSE],
      'exact address' => ['203.0.113.10', '203.0.113.10', TRUE],
      'other address' => ['203.0.113.10', '203.0.113.11', FALSE],
      'address inside a range' => ['203.0.113.0/24', '203.0.113.200', TRUE],
      'address outside a range' => ['203.0.113.0/24', '203.0.114.1', FALSE],
      'second line matches' => ["198.51.100.1\n203.0.113.10", '203.0.113.10', TRUE],
      'surrounding whitespace and blank lines are ignored' => [
        "  198.51.100.1  \n\n\t203.0.113.0/24\n",
        '203.0.113.10',
        TRUE,
      ],
      'windows line endings' => ["198.51.100.1\r\n203.0.113.10", '203.0.113.10', TRUE],
      'IPv6 address' => ['2001:db8::1', '2001:db8::1', TRUE],
      'IPv6 range' => ['2001:db8::/32', '2001:db8:1::42', TRUE],
      'IPv6 address outside the range' => ['2001:db8::/32', '2001:db9::1', FALSE],
      'IPv4 allowlist does not match IPv6 client' => ['203.0.113.0/24', '2001:db8::1', FALSE],
    ];
  }

  /**
   * Tests that an allowlisted address skips the credential check entirely.
   *
   * @covers ::handle
   */
  public function testIpAllowlistTakesPrecedenceOverCredentials(): void {
    $this->config('shield.settings')->set('allowlist', '203.0.113.10')->save();

    // Wrong credentials from an allowlisted address still pass.
    $request = Request::create('/protected', 'GET', [], [], [], [
      'REMOTE_ADDR' => '203.0.113.10',
      'PHP_AUTH_USER' => 'user',
      'PHP_AUTH_PW' => 'wrong',
    ]);
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (ip)', $request->headers->get('X-Shield-Status'));
  }

  /**
   * Tests that Shield's credentials are hidden from basic_auth by default.
   *
   * Core's basic_auth provider reads PHP_AUTH_USER and PHP_AUTH_PW from the
   * request headers. Left in place, the Shield pair would be taken for a
   * Drupal account and the request would end in a 403.
   *
   * @covers ::bypass
   */
  public function testBasicAuthHeadersAreUnset(): void {
    $this->assertTrue($this->config('shield.settings')->get('unset_basic_auth_headers'), 'Unsetting the headers is the default.');

    $request = $this->createAuthenticatedRequest('user', 'password');
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('authenticated', $this->forwardedRequest->headers->get('X-Shield-Status'));
    $this->assertFalse($this->forwardedRequest->headers->has('PHP_AUTH_USER'));
    $this->assertFalse($this->forwardedRequest->headers->has('PHP_AUTH_PW'));

    // Requests let through by an exception are stripped as well.
    $this->config('shield.settings')->set('allowlist', '203.0.113.10')->save();
    $request = $this->createAuthenticatedRequest('user', 'wrong', ['REMOTE_ADDR' => '203.0.113.10']);
    $this->handle($request);
    $this->assertSame('skipped (ip)', $this->forwardedRequest->headers->get('X-Shield-Status'));
    $this->assertFalse($this->forwardedRequest->headers->has('PHP_AUTH_USER'));
    $this->assertFalse($this->forwardedRequest->headers->has('PHP_AUTH_PW'));
  }

  /**
   * Tests that the headers are kept when a site opts out of unsetting them.
   *
   * @covers ::bypass
   */
  public function testBasicAuthHeadersAreKeptWhenConfigured(): void {
    $this->config('shield.settings')->set('unset_basic_auth_headers', FALSE)->save();

    $request = $this->createAuthenticatedRequest('user', 'password');
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('user', $this->forwardedRequest->headers->get('PHP_AUTH_USER'));
    $this->assertSame('password', $this->forwardedRequest->headers->get('PHP_AUTH_PW'));
  }

  /**
   * Tests that the headers of a real Drupal account are kept.
   *
   * Stripping them would log the account out again right after the
   * basic_auth bypass let it through.
   *
   * @covers ::bypass
   */
  public function testBasicAuthHeadersAreKeptForDrupalAccounts(): void {
    $this->config('shield.settings')->set('basic_auth_bypass', TRUE)->save();

    $account = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'editor',
      'pass' => 'editor-secret',
      'status' => 1,
    ]);
    $account->save();

    $request = $this->createAuthenticatedRequest('editor', 'editor-secret');
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (basic auth)', $this->forwardedRequest->headers->get('X-Shield-Status'));
    $this->assertSame('editor', $this->forwardedRequest->headers->get('PHP_AUTH_USER'));
    $this->assertSame('editor-secret', $this->forwardedRequest->headers->get('PHP_AUTH_PW'));
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
    return Request::create('/protected', 'GET', [], [], [], $server + [
      'PHP_AUTH_USER' => $user,
      'PHP_AUTH_PW' => $pass,
    ]);
  }

  /**
   * Passes a request through the middleware.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   * @param int $type
   *   The request type.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response of the middleware.
   */
  protected function handle(Request $request, int $type = ShieldMiddleware::MAIN_REQUEST): Response {
    $this->forwardedRequest = NULL;

    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturnCallback(function (Request $forwarded) {
      $this->forwardedRequest = $forwarded;
      return new Response('passed through');
    });

    $middleware = new ShieldMiddleware(
      $kernel,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
      $this->container->get('request_stack'),
      $this->container->get('logger.channel.shield')
    );

    return $middleware->handle($request, $type, TRUE);
  }

}
