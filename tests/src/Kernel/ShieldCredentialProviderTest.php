<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests HTTP authentication against each credential provider.
 *
 * Every provider has to accept exactly the pair it holds and reject anything
 * else, and the realm has to print that provider's user name when asked to.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 */
class ShieldCredentialProviderTest extends KernelTestBase {

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
      // The tests run under the CLI SAPI, which Shield lets through by
      // default.
      ->set('allow_cli', FALSE)
      ->set('debug_header', TRUE)
      ->set('print', 'Realm for [user]')
      ->save();

    // A single value key for the 'key' provider, and a user/password key for
    // the 'multikey' provider. Both use the config key provider so the test
    // does not depend on the file system.
    Key::create([
      'id' => 'shield_password',
      'label' => 'Shield password',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'key-secret',
        'base64_encoded' => FALSE,
      ],
    ])->save();
    Key::create([
      'id' => 'shield_pair',
      'label' => 'Shield pair',
      'key_type' => 'user_password',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => '{"username":"pair-user","password":"pair-secret"}',
        'base64_encoded' => FALSE,
      ],
    ])->save();
  }

  /**
   * Tests that a provider accepts its own pair and nothing else.
   *
   * @param array $settings
   *   The shield.settings values selecting and configuring the provider.
   * @param string $user
   *   The user name the provider is expected to accept.
   * @param string $pass
   *   The password the provider is expected to accept.
   *
   * @dataProvider providerCredentialProviders
   *
   * @covers ::handle
   * @covers ::buildCredentials
   */
  public function testCredentialProvider(array $settings, string $user, string $pass): void {
    $config = $this->config('shield.settings');
    foreach ($settings as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    // Without credentials the client is challenged, and the realm prints the
    // user name the provider resolved.
    $request = Request::create('/protected');
    $response = $this->handle($request);
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Basic realm="Realm for ' . $user . '"', $response->headers->get('WWW-Authenticate'));
    $this->assertSame('pending', $response->headers->get('X-Shield-Status'));

    // The configured pair passes.
    $request = $this->createRequest($user, $pass);
    $response = $this->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('authenticated', $request->headers->get('X-Shield-Status'));

    // A wrong password, a wrong user name and the other providers' pairs are
    // all rejected.
    $rejected = [
      [$user, 'wrong-' . $pass],
      ['wrong-' . $user, $pass],
      ['', ''],
      ['user', 'password'],
      ['user', 'key-secret'],
      ['pair-user', 'pair-secret'],
    ];
    foreach ($rejected as [$attempt_user, $attempt_pass]) {
      if ($attempt_user === $user && $attempt_pass === $pass) {
        continue;
      }
      $response = $this->handle($this->createRequest($attempt_user, $attempt_pass));
      $this->assertSame(401, $response->getStatusCode(), "$attempt_user:$attempt_pass is rejected.");
    }
  }

  /**
   * Data provider for ::testCredentialProvider().
   *
   * @return array[]
   *   Test cases holding the provider settings and the pair they accept.
   */
  public static function providerCredentialProviders(): array {
    return [
      'shield' => [
        [
          'credential_provider' => 'shield',
          'credentials.shield.user' => 'user',
          'credentials.shield.pass' => 'password',
        ],
        'user',
        'password',
      ],
      'key' => [
        [
          'credential_provider' => 'key',
          'credentials.key.user' => 'user',
          'credentials.key.pass_key' => 'shield_password',
        ],
        'user',
        'key-secret',
      ],
      'multikey' => [
        [
          'credential_provider' => 'multikey',
          'credentials.multikey.user_pass_key' => 'shield_pair',
        ],
        'pair-user',
        'pair-secret',
      ],
    ];
  }

  /**
   * Tests that a multikey key missing either value denies every request.
   *
   * @covers ::handle
   * @covers ::buildCredentials
   */
  public function testMultikeyWithoutPassword(): void {
    Key::create([
      'id' => 'shield_half_pair',
      'label' => 'Shield half pair',
      'key_type' => 'user_password',
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => '{"username":"half-user","password":null}',
        'base64_encoded' => FALSE,
      ],
    ])->save();
    $this->config('shield.settings')
      ->set('credential_provider', 'multikey')
      ->set('credentials.multikey.user_pass_key', 'shield_half_pair')
      ->save();

    $response = $this->handle($this->createRequest('half-user', ''));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame('Basic realm="Realm for half-user"', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Builds a request carrying basic authentication credentials.
   *
   * @param string $user
   *   The user name to send.
   * @param string $pass
   *   The password to send.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createRequest(string $user, string $pass): Request {
    return Request::create('/protected', 'GET', [], [], [], [
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode("$user:$pass"),
    ]);
  }

  /**
   * Passes a request through the middleware.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response of the middleware.
   */
  protected function handle(Request $request): Response {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn(new Response('passed through'));

    $middleware = new ShieldMiddleware(
      $kernel,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('module_handler'),
      $this->container->get('request_stack'),
      $this->container->get('logger.channel.shield')
    );

    return $middleware->handle($request, ShieldMiddleware::MAIN_REQUEST, TRUE);
  }

}
