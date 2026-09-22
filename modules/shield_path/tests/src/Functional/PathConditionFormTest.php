<?php

namespace Drupal\Tests\shield_path\Functional;

use Drupal\shield\Entity\ShieldPolicy;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Path condition's configuration form.
 *
 * @group shield
 */
#[RunTestsInSeparateProcesses]
class PathConditionFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_password', 'shield_path'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that an empty path list cannot be saved.
   *
   * Unlike the legacy global "include" path list, a Path condition's paths
   * field is required at the form level: there is no include/exclude method
   * that could otherwise be left silently unprotecting every request.
   */
  public function testEmptyPathsIsRejected(): void {
    // Disabled: a status-TRUE policy with no conditions yet would match
    // every request (see ShieldPolicy::matchesConditions()) and 401-challenge
    // the test's own login and form submissions.
    $policy = ShieldPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test policy',
      'status' => FALSE,
      'credential_providers' => [
        ['id' => 'password', 'users' => ['user'], 'passwords' => ['password']],
      ],
    ]);
    $policy->save();

    $account = $this->drupalCreateUser(['administer shield']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/shield/policy/condition/add/test_policy/path');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['paths' => ''], 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Paths field is required.');

    // Nothing was saved: the policy still has no conditions.
    $policy = ShieldPolicy::load('test_policy');
    $this->assertSame([], $policy->getConditions());

    // A non-empty path list is accepted.
    $this->submitForm(['paths' => '/admin'], 'Save');
    $policy = ShieldPolicy::load('test_policy');
    $conditions = $policy->getConditions();
    $this->assertCount(1, $conditions);
    $this->assertSame('/admin', reset($conditions)['paths']);
  }

}
