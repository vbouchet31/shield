<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests excluding domains from, and including domains in, Shield protection.
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 *
 * @group shield
 *
 * @see https://www.drupal.org/project/shield/issues/3410025
 */
class ShieldDomainTest extends KernelTestBase {

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
   * Tests which domains are let through the shield.
   *
   * @param string $domains
   *   The configured list of domains.
   * @param int $method
   *   The configured domain method.
   * @param string $host
   *   The host to send the request to.
   * @param bool $expected
   *   Whether the request is expected to pass the shield.
   *
   * @covers ::checkDomainAllowed
   *
   * @dataProvider providerDomains
   */
  public function testDomains($domains, $method, $host, $expected) {
    $this->config('shield.settings')
      ->set('domains', $domains)
      ->set('domains_method', $method)
      ->save();

    $request = Request::create('https://' . $host . '/');
    $response = $this->buildMiddleware()->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);

    $this->assertSame($expected ? 200 : 401, $response->getStatusCode());
    if ($expected) {
      $this->assertSame('skipped (domain)', $request->headers->get('X-Shield-Status'));
    }
  }

  /**
   * Data provider for ::testDomains().
   *
   * @return array[]
   *   Test cases, each holding the domain list, the method, a host and the
   *   outcome.
   */
  public static function providerDomains() {
    $exclude = ShieldMiddleware::EXCLUDE_METHOD;
    $include = ShieldMiddleware::INCLUDE_METHOD;

    return [
      'exclude: listed domain is let through' => [
        'www.example.com',
        $exclude,
        'www.example.com',
        TRUE,
      ],
      'exclude: unlisted domain stays protected' => [
        'www.example.com',
        $exclude,
        'admin.example.com',
        FALSE,
      ],
      'exclude: one of several' => [
        "first.example.com\nwww.example.com\n",
        $exclude,
        'www.example.com',
        TRUE,
      ],
      'exclude: wildcard' => [
        '*.example.com',
        $exclude,
        'www.example.com',
        TRUE,
      ],
      'exclude: surrounding whitespace' => [
        '  www.example.com  ',
        $exclude,
        'www.example.com',
        TRUE,
      ],
      'exclude: empty list protects everything' => [
        '',
        $exclude,
        'www.example.com',
        FALSE,
      ],
      'include: listed domain is protected' => [
        'admin.example.com',
        $include,
        'admin.example.com',
        FALSE,
      ],
      'include: unlisted domain is let through' => [
        'admin.example.com',
        $include,
        'www.example.com',
        TRUE,
      ],
      'include: wildcard' => [
        '*.internal.example.com',
        $include,
        'cms.internal.example.com',
        FALSE,
      ],
      // Without this, switching to the include method with nothing listed
      // would leave every domain unprotected.
      'include: empty list protects everything' => [
        '',
        $include,
        'www.example.com',
        FALSE,
      ],
    ];
  }

  /**
   * Sites upgrading from before the method existed keep excluding domains.
   *
   * @covers ::checkDomainAllowed
   */
  public function testMissingMethodExcludes() {
    $this->config('shield.settings')
      ->set('domains', 'www.example.com')
      ->clear('domains_method')
      ->save();

    $request = Request::create('https://www.example.com/');
    $response = $this->buildMiddleware()->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('skipped (domain)', $request->headers->get('X-Shield-Status'));
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
