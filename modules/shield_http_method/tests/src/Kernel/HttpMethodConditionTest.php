<?php

namespace Drupal\Tests\shield_http_method\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the HTTP method condition plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_http_method\Plugin\ShieldCondition\HttpMethod
 */
#[RunTestsInSeparateProcesses]
class HttpMethodConditionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_http_method'];

  /**
   * Tests that only the configured methods match.
   *
   * @covers ::evaluate
   */
  public function testConfiguredMethodsMatch(): void {
    $condition = $this->createCondition(['POST', 'PUT']);

    $this->assertTrue($condition->evaluate(Request::create('/', 'POST')));
    $this->assertTrue($condition->evaluate(Request::create('/', 'PUT')));
    $this->assertFalse($condition->evaluate(Request::create('/', 'GET')));
    $this->assertFalse($condition->evaluate(Request::create('/', 'DELETE')));
  }

  /**
   * Tests that no configured method never matches.
   *
   * @covers ::evaluate
   */
  public function testNoConfiguredMethodsNeverMatches(): void {
    $condition = $this->createCondition([]);

    $this->assertFalse($condition->evaluate(Request::create('/', 'GET')));
    $this->assertFalse($condition->evaluate(Request::create('/', 'POST')));
  }

  /**
   * Tests that the condition does not affect cacheability by default.
   *
   * Unlike Path, matching an HTTP method does not narrow a policy to a
   * subset of GET requests a page cache would otherwise serve from cache,
   * so it must not trigger the kill switch on its own.
   *
   * @covers ::affectsCacheability
   */
  public function testDoesNotAffectCacheability(): void {
    $condition = $this->createCondition(['POST']);

    $this->assertFalse($condition->affectsCacheability());
  }

  /**
   * Creates a configured HTTP method condition plugin instance.
   *
   * @param string[] $methods
   *   The configured HTTP methods.
   *
   * @return \Drupal\shield\ShieldConditionInterface
   *   The configured plugin instance.
   */
  protected function createCondition(array $methods) {
    return $this->container->get('plugin.manager.shield.condition')
      ->createInstance('http_method', ['methods' => $methods]);
  }

}
