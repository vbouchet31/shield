<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that a condition's negate flag flips policy matching correctly.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\Entity\ShieldPolicy
 */
#[RunTestsInSeparateProcesses]
class ShieldConditionNegateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password', 'shield_path', 'shield_http_method', 'path_alias', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['shield', 'system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
  }

  /**
   * Tests that a negated condition matches everything except its own scope.
   *
   * @covers ::matchesConditions
   */
  public function testNegateFlipsMatch(): void {
    $policy = $this->createPolicy([
      ['id' => 'path', 'negate' => TRUE, 'paths' => '/jsonapi/*'],
    ]);

    $this->assertFalse($policy->matchesConditions(Request::create('/jsonapi/node/article')));
    $this->assertTrue($policy->matchesConditions(Request::create('/admin')));
    $this->assertTrue($policy->matchesConditions(Request::create('/')));
  }

  /**
   * Tests that a non-negated condition only matches its own scope.
   *
   * Sanity check for testNegateFlipsMatch(): the same path pattern without
   * negate produces the opposite outcome on the same requests.
   *
   * @covers ::matchesConditions
   */
  public function testWithoutNegateOnlyMatchesOwnScope(): void {
    $policy = $this->createPolicy([
      ['id' => 'path', 'negate' => FALSE, 'paths' => '/jsonapi/*'],
    ]);

    $this->assertTrue($policy->matchesConditions(Request::create('/jsonapi/node/article')));
    $this->assertFalse($policy->matchesConditions(Request::create('/admin')));
  }

  /**
   * Tests that a negated condition still ANDs with a second condition.
   *
   * @covers ::matchesConditions
   */
  public function testNegateComposesWithAnotherCondition(): void {
    $policy = $this->createPolicy([
      ['id' => 'path', 'negate' => TRUE, 'paths' => '/api/*'],
      ['id' => 'http_method', 'negate' => FALSE, 'methods' => ['POST']],
    ]);

    // Outside /api and POST: both conditions pass.
    $this->assertTrue($policy->matchesConditions(Request::create('/admin', 'POST')));
    // Outside /api but GET: the second condition fails.
    $this->assertFalse($policy->matchesConditions(Request::create('/admin', 'GET')));
    // Inside /api and POST: the negated first condition fails.
    $this->assertFalse($policy->matchesConditions(Request::create('/api/node', 'POST')));
    // Inside /api and GET: both conditions fail.
    $this->assertFalse($policy->matchesConditions(Request::create('/api/node', 'GET')));
  }

  /**
   * Creates an unsaved Shield policy configured with the given conditions.
   *
   * The policy is deliberately not saved: matchesConditions() is exercised
   * directly against the entity, with no request dispatch involved.
   *
   * @param array $conditions
   *   The condition plugin configurations.
   *
   * @return \Drupal\shield\Entity\ShieldPolicy
   *   The unsaved policy.
   */
  protected function createPolicy(array $conditions): ShieldPolicy {
    return ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'conditions' => $conditions,
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ]);
  }

}
