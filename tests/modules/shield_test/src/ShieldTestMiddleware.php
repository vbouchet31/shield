<?php

namespace Drupal\shield_test;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware for shield tests.
 */
class ShieldTestMiddleware implements HttpKernelInterface {

  /**
   * The decorated kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * Constructs a ShieldTestMiddleware object.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   */
  public function __construct(HttpKernelInterface $http_kernel) {
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    // Allow faking the IP address for requests to test the
    // IP allowlist feature.
    if (drupal_valid_test_ua() && $request->headers->has('Shield-Fake-Test-Ip')) {
      $request->server->set('REMOTE_ADDR', $request->headers->get('Shield-Fake-Test-Ip'));
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

}
