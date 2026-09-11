<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\Core\DrupalKernelInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\ShieldMiddleware;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests that core's procedural includes are loaded before authenticating.
 *
 * Shield's middleware runs at priority 250, ahead of
 * http_middleware.kernel_pre_handle at priority 100, so
 * DrupalKernel::preHandle() has not loaded core/includes/common.inc yet. A
 * basic_auth login that rehashes the password saves the user entity, and that
 * save returns SAVED_UPDATED — a fatal \Error when the include is missing.
 *
 * @group shield
 *
 * @see \Drupal\shield\ShieldMiddleware::bypass()
 * @see https://www.drupal.org/project/shield/issues/3615866
 */
#[RunTestsInSeparateProcesses]
class ShieldLegacyIncludesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'path_alias', 'basic_auth', 'user', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['shield']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
  }

  /**
   * The middleware loads the legacy includes before basic_auth authenticates.
   */
  public function testLegacyIncludesLoadedBeforeAuthentication(): void {
    $kernel = $this->prophesizeLegacyIncludeSpy();

    $middleware = $this->buildMiddleware();
    $request = Request::create('/', 'GET', [], [], [], [
      'PHP_AUTH_USER' => 'someone',
      'PHP_AUTH_PW' => 'wrong-password',
    ]);
    $middleware->handle($request);

    $this->assertGreaterThan(0, $kernel->legacyIncludeCalls, 'ShieldMiddleware asked the kernel to load the legacy includes before handing the request to basic_auth.');
  }

  /**
   * The legacy includes are not loaded when basic_auth sends no credentials.
   */
  public function testLegacyIncludesNotLoadedWithoutCredentials(): void {
    $kernel = $this->prophesizeLegacyIncludeSpy();

    $middleware = $this->buildMiddleware();
    $middleware->handle(Request::create('/'));

    $this->assertSame(0, $kernel->legacyIncludeCalls, 'A request without basic auth credentials does not reach the authentication path.');
  }

  /**
   * Builds a middleware wrapping an inner kernel that returns a fixed response.
   *
   * Shield is left disabled so that handle() takes the bypass() path, which is
   * where the basic_auth header handling lives.
   *
   * @return \Drupal\shield\ShieldMiddleware
   *   The middleware under test.
   */
  protected function buildMiddleware(): ShieldMiddleware {
    $this->config('shield.settings')
      ->set('shield_enable', FALSE)
      ->set('allow_cli', FALSE)
      ->set('unset_basic_auth_headers', TRUE)
      ->save();

    $inner = new class implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('');
      }

    };

    return new ShieldMiddleware(
      $inner,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('path.matcher'),
      $this->container->get('path_alias.manager'),
      $this->container->get('language_manager'),
      $this->container->get('module_handler'),
      $this->container->get('request_stack')
    );
  }

  /**
   * Replaces the kernel service with one that counts legacy include loads.
   *
   * @return object
   *   The replacement kernel, carrying a public $legacyIncludeCalls counter.
   */
  protected function prophesizeLegacyIncludeSpy(): object {
    $spy = new class($this->container->get('kernel')) {

      /**
       * Number of times loadLegacyIncludes() has been called.
       *
       * @var int
       */
      public $legacyIncludeCalls = 0;

      /**
       * The real kernel every other call is forwarded to.
       *
       * @var \Drupal\Core\DrupalKernelInterface
       */
      protected $inner;

      /**
       * Constructs the spy.
       *
       * @param \Drupal\Core\DrupalKernelInterface $inner
       *   The real kernel.
       */
      public function __construct(DrupalKernelInterface $inner) {
        $this->inner = $inner;
      }

      /**
       * Counts the call, then lets the real kernel do the work.
       */
      public function loadLegacyIncludes() {
        $this->legacyIncludeCalls++;
        return $this->inner->loadLegacyIncludes();
      }

      /**
       * Forwards every other kernel method untouched.
       *
       * @param string $name
       *   The method name.
       * @param array $arguments
       *   The method arguments.
       *
       * @return mixed
       *   Whatever the real kernel returns.
       */
      public function __call($name, array $arguments) {
        return call_user_func_array([$this->inner, $name], $arguments);
      }

    };
    $this->container->set('kernel', $spy);

    return $spy;
  }

}
