<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests bypassing the shield based on the request user agent.
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 *
 * @group shield
 */
class ShieldUserAgentTest extends KernelTestBase {

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
      ->set('debug_header', TRUE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->save();
  }

  /**
   * Tests which user agents are let through the shield.
   *
   * @param string $allowlist
   *   The configured user agent allowlist.
   * @param string|null $user_agent
   *   The user agent to send the request with, or NULL to send none.
   * @param bool $expected
   *   Whether the request is expected to pass the shield.
   *
   * @covers ::checkUserAgentAllowed
   *
   * @dataProvider providerUserAgentAllowlist
   */
  public function testUserAgentAllowlist($allowlist, $user_agent, $expected) {
    $this->config('shield.settings')
      ->set('user_agents', $allowlist)
      ->save();

    $request = $this->createRequest($user_agent);
    $response = $this->buildMiddleware()->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);

    $this->assertSame($expected ? 200 : 401, $response->getStatusCode());
    if ($expected) {
      $this->assertSame('skipped (user agent)', $request->headers->get('X-Shield-Status'));
    }
  }

  /**
   * Data provider for ::testUserAgentAllowlist().
   *
   * @return array[]
   *   Test cases, each holding the allowlist, a user agent and the outcome.
   */
  public static function providerUserAgentAllowlist() {
    $facebook = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    return [
      'empty allowlist' => ['', $facebook, FALSE],
      'allowlist holding only blank lines' => ["\n  \n", $facebook, FALSE],
      'matching user agent' => ['facebookexternalhit', $facebook, TRUE],
      'matching user agent, different case' => [
        'FacebookExternalHit',
        $facebook,
        TRUE,
      ],
      'matching one of several' => [
        "Twitterbot\nfacebookexternalhit\n",
        $facebook,
        TRUE,
      ],
      'surrounding whitespace in the allowlist' => [
        '  facebookexternalhit  ',
        $facebook,
        TRUE,
      ],
      'non matching user agent' => ['Twitterbot', $facebook, FALSE],
      'no user agent sent' => ['facebookexternalhit', NULL, FALSE],
      'empty user agent sent' => ['facebookexternalhit', '', FALSE],
    ];
  }

  /**
   * Tests that the allowlist does not override a more specific bypass.
   *
   * An allowed IP is checked before the user agent, so the status header has
   * to keep reporting the reason the request was actually let through.
   *
   * @covers ::handle
   */
  public function testEarlierBypassTakesPrecedence() {
    $this->config('shield.settings')
      ->set('allowlist', '127.0.0.1')
      ->set('user_agents', 'Twitterbot')
      ->save();

    $request = $this->createRequest('Twitterbot/1.0');
    $response = $this->buildMiddleware()->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (ip)', $request->headers->get('X-Shield-Status'));
  }

  /**
   * Builds a request carrying the given user agent.
   *
   * @param string|null $user_agent
   *   The user agent to send, or NULL to send none.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request to pass to the middleware.
   */
  protected function createRequest($user_agent) {
    $server = $user_agent === NULL ? [] : ['HTTP_USER_AGENT' => $user_agent];
    return Request::create('/', 'GET', [], [], [], $server);
  }

  /**
   * Builds the middleware with a kernel that reports a passed request.
   *
   * @return \Drupal\shield\ShieldMiddleware
   *   The middleware to test.
   */
  protected function buildMiddleware() {
    $http_kernel = $this->createMock(HttpKernelInterface::class);
    $http_kernel->expects($this->any())
      ->method('handle')
      ->willReturn(new Response('Passed the shield.'));

    return new ShieldMiddleware(
      $http_kernel,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('path.matcher'),
      $this->container->get('path_alias.manager'),
      $this->container->get('language_manager'),
      $this->container->get('module_handler'),
      $this->container->get('request_stack')
    );
  }

}
