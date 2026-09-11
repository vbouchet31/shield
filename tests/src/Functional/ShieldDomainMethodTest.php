<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests saving the domain method on the settings form.
 *
 * @group shield
 *
 * @see https://www.drupal.org/project/shield/issues/3410025
 */
class ShieldDomainMethodTest extends BrowserTestBase {

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
      ->set('debug_header', TRUE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->save();
  }

  /**
   * The domain method is saved, and an empty include list is rejected.
   */
  public function testDomainMethodIsSaved() {
    $account = $this->drupalCreateUser(['administer shield']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/system/shield');
    $this->assertSession()->statusCodeEquals(200);
    // The method defaults to excluding the listed domains, which is what sites
    // upgrading from before it existed were doing.
    $this->assertSession()->checkboxChecked('edit-exceptions-domains-shield-domains-method-0');

    $this->submitForm([
      'exceptions[domains][shield_domains_method]' => 1,
      'exceptions[domains][shield_domains]' => 'admin.example.com',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('shield.settings');
    $this->assertEquals(1, $config->get('domains_method'));
    $this->assertEquals('admin.example.com', $config->get('domains'));

    // Including nothing would leave no domain protected at all.
    $this->drupalGet('admin/config/system/shield');
    $this->submitForm([
      'exceptions[domains][shield_domains_method]' => 1,
      'exceptions[domains][shield_domains]' => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('Enter at least one domain to include, or select "Exclude" to protect every domain.');
    $this->assertEquals('admin.example.com', $this->config('shield.settings')->get('domains'));

    // Excluding nothing is allowed: it protects every domain.
    $this->drupalGet('admin/config/system/shield');
    $this->submitForm([
      'exceptions[domains][shield_domains_method]' => 0,
      'exceptions[domains][shield_domains]' => '',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('shield.settings');
    $this->assertEquals(0, $config->get('domains_method'));
    $this->assertEquals('', $config->get('domains'));
  }

}
