<?php

namespace Drupal\Tests\shield\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shield\Entity\ShieldPolicy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that saving, enabling, disabling, or deleting a policy flushes the
 * page cache.
 *
 * A newly-protected or newly-unprotected path must never be served from a
 * stale cache.page entry recorded under the previous policy state.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield\Entity\ShieldPolicy
 */
#[RunTestsInSeparateProcesses]
class ShieldPolicySaveCacheFlushTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password', 'shield_path', 'path_alias', 'page_cache', 'system', 'user'];

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
   * Tests that creating a policy flushes the page cache.
   *
   * @covers ::postSave
   */
  public function testSaveFlushesCache(): void {
    $this->putCacheEntry();
    $this->assertCacheEntryExists();

    $this->createPolicy();

    $this->assertCacheEntryDoesNotExist();
  }

  /**
   * Tests that disabling an existing policy flushes the page cache.
   *
   * A status-only change is still a save, and it changes which requests are
   * protected just as much as adding or removing a condition would.
   *
   * @covers ::postSave
   */
  public function testDisablingFlushesCache(): void {
    $policy = $this->createPolicy();
    $this->putCacheEntry();
    $this->assertCacheEntryExists();

    $policy->set('status', FALSE)->save();

    $this->assertCacheEntryDoesNotExist();
  }

  /**
   * Tests that re-enabling a policy flushes the page cache.
   *
   * @covers ::postSave
   */
  public function testEnablingFlushesCache(): void {
    $policy = $this->createPolicy();
    $policy->set('status', FALSE)->save();
    $this->putCacheEntry();
    $this->assertCacheEntryExists();

    $policy->set('status', TRUE)->save();

    $this->assertCacheEntryDoesNotExist();
  }

  /**
   * Tests that deleting a policy flushes the page cache.
   *
   * @covers ::postDelete
   */
  public function testDeleteFlushesCache(): void {
    $policy = $this->createPolicy();
    $this->putCacheEntry();
    $this->assertCacheEntryExists();

    $policy->delete();

    $this->assertCacheEntryDoesNotExist();
  }

  /**
   * Creates and saves a Shield policy.
   *
   * @return \Drupal\shield\Entity\ShieldPolicy
   *   The saved policy.
   */
  protected function createPolicy(): ShieldPolicy {
    $policy = ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => TRUE,
      'conditions' => [
        ['id' => 'path', 'paths' => '/admin'],
      ],
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ]);
    $policy->save();
    return $policy;
  }

  /**
   * Writes a dummy entry directly into the cache.page bin.
   */
  protected function putCacheEntry(): void {
    $this->container->get('cache.page')->set('shield_test_entry', 'dummy value');
  }

  /**
   * Asserts that the dummy cache.page entry is still present.
   */
  protected function assertCacheEntryExists(): void {
    $this->assertNotFalse($this->container->get('cache.page')->get('shield_test_entry'), 'The cache.page entry must exist before the policy change under test.');
  }

  /**
   * Asserts that the dummy cache.page entry has been flushed.
   */
  protected function assertCacheEntryDoesNotExist(): void {
    $this->assertFalse($this->container->get('cache.page')->get('shield_test_entry'), 'The policy change must flush the cache.page bin.');
  }

}
