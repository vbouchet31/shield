<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that an empty "include" path list cannot be saved.
 *
 * With the include method Shield only protects the listed paths, so clearing
 * the list silently unprotects the whole site while the settings still say
 * Shield is enabled.
 *
 * @group shield
 *
 * @see https://www.drupal.org/project/shield/issues/3491255
 */
class ShieldEmptyIncludePathsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('shield.settings')
      ->set('shield_enable', TRUE)
      ->set('debug_header', TRUE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->set('method', 1)
      ->set('paths', '/user/login')
      ->save();
  }

  /**
   * Clearing the paths while including them must be rejected by the form.
   */
  public function testEmptyIncludePathsIsRejected() {
    $account = $this->drupalCreateUser(['administer shield']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/shield');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'exceptions[paths][shield_method]' => 1,
      'exceptions[paths][shield_paths]' => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Including an empty list of paths would disable Shield everywhere.');

    // The stored configuration is untouched, so the site stays protected.
    $this->assertEquals('/user/login', $this->config('shield.settings')->get('paths'));

    // Excluding nothing is still allowed: it protects every path, which is
    // exactly why the redirect back to the settings form is shielded.
    $this->drupalGet('admin/config/system/shield');
    $this->submitForm([
      'exceptions[paths][shield_method]' => 0,
      'exceptions[paths][shield_paths]' => '',
    ], 'Save configuration');
    $this->assertSession()->statusCodeEquals(401);
    $this->assertEquals('', $this->config('shield.settings')->get('paths'));
    $this->assertEquals(0, $this->config('shield.settings')->get('method'));
  }

}
