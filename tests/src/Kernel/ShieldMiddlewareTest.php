<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests how the middleware reads the submitted credentials.
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 *
 * @group shield
 */
class ShieldMiddlewareTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'key',
    'path_alias',
    'shield',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['shield']);
    $this->config('shield.settings')
      ->set('shield_enable', TRUE)
      // The tests run under the CLI SAPI, which shield lets through by
      // default.
      ->set('allow_cli', FALSE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->save();
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
   *
   * @dataProvider providerCredentialSources
   */
  public function testCredentialSources(array $server, $expected) {
    $response = $this->handleRequest($server);

    if ($expected) {
      $this->assertSame(200, $response->getStatusCode());
    }
    else {
      $this->assertSame(401, $response->getStatusCode());
    }
  }

  /**
   * Data provider for ::testCredentialSources().
   *
   * @return array[]
   *   Test cases, each holding server variables and the expected outcome.
   */
  public static function providerCredentialSources() {
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
  public function testPasswordWithColon() {
    $this->config('shield.settings')
      ->set('credentials.shield.pass', 'pass:word')
      ->save();

    $server = [
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pass:word'),
    ];
    $this->assertSame(200, $this->handleRequest($server)->getStatusCode());
  }

  /**
   * Tests that no error is triggered when no credentials are configured.
   *
   * @covers ::handle
   */
  public function testMissingConfiguredCredentials() {
    $this->config('shield.settings')
      ->set('credential_provider', 'key')
      ->set('credentials.key.user', 'user')
      ->set('credentials.key.pass_key', 'no_such_key')
      ->set('print', '[user]/[pass]')
      ->save();

    $server = [
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:password'),
    ];
    $response = $this->handleRequest($server);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Basic realm="user/"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Passes a request with the given server variables to the middleware.
   *
   * @param array $server
   *   Server variables to send the request with.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response of the middleware.
   */
  protected function handleRequest(array $server) {
    $http_kernel = $this->createMock(HttpKernelInterface::class);
    $http_kernel->expects($this->any())
      ->method('handle')
      ->willReturn(new Response('Passed the shield.'));

    $middleware = new ShieldMiddleware(
      $http_kernel,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('path.matcher'),
      $this->container->get('path_alias.manager'),
      $this->container->get('language_manager'),
      $this->container->get('module_handler'),
      $this->container->get('request_stack')
    );

    $request = Request::create('/', 'GET', [], [], [], $server);
    return $middleware->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);
  }

}
