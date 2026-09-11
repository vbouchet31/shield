<?php

namespace Drupal\Tests\shield\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that an excluded path cannot disable Shield for the rest of the site.
 *
 * Shield's middleware runs in front of the page cache, so a response that was
 * allowed through on an excluded path must never make the site reachable on
 * any other path, and removing the exclusion must take effect immediately.
 *
 * @group shield
 *
 * @see https://www.drupal.org/project/shield/issues/3491255
 */
class ShieldJsonApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'shield',
    'basic_auth',
    'node',
    'serialization',
    'jsonapi',
    'page_cache',
    'dynamic_page_cache',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateNode(['type' => 'article', 'title' => 'Test article']);
    // JSON:API derives its routes from the resource types, which only exist
    // once the bundle does.
    $this->container->get('router.builder')->rebuild();

    $this->config('shield.settings')
      ->set('shield_enable', TRUE)
      ->set('debug_header', TRUE)
      ->set('credential_provider', 'shield')
      ->set('credentials.shield.user', 'user')
      ->set('credentials.shield.pass', 'password')
      ->set('method', 0)
      ->set('paths', "/jsonapi\n/jsonapi/*")
      ->save();
  }

  /**
   * A served JSON:API response must not open up the rest of the site.
   */
  public function testShieldStillProtectsAfterJsonApiRequest() {
    // Anonymous users may view published content, so JSON:API answers.
    $this->drupalGet('jsonapi/node/article');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'skipped (path)');

    // Every other path must still be shielded.
    $this->assertShielded('user/login');
    $this->assertShielded('');
    $this->assertShielded('node/1');

  }

  /**
   * An API client sending Shield's credentials must not unlock later requests.
   *
   * This mirrors the reported reproduction: a JSON:API call made from Postman
   * or cURL, which sends the same Authorization header on every request it
   * makes afterwards.
   */
  public function testShieldStillProtectsAfterAuthenticatedJsonApiRequest() {
    $authorization = ['Authorization' => 'Basic ' . base64_encode('user:password')];
    $this->drupalGet('jsonapi/node/article', [], $authorization);
    $this->assertSession()->statusCodeEquals(200);

    // While the client keeps sending the credentials it stays authenticated,
    // which is Shield working as designed rather than a bypass.
    $this->drupalGet('user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'authenticated');

    // As soon as the credentials are dropped the site is shielded again, on
    // the excluded path as well as everywhere else.
    $this->getSession()->setRequestHeader('Authorization', '');

    $this->assertShielded('user/login');
    $this->assertShielded('');
  }

  /**
   * Removing the exclusion must restore protection on the excluded path.
   */
  public function testShieldProtectsAgainAfterExclusionIsRemoved() {
    $this->drupalGet('jsonapi/node/article');
    $this->assertSession()->statusCodeEquals(200);

    $this->config('shield.settings')->set('paths', '')->save();

    $this->assertShielded('jsonapi/node/article');
    $this->assertShielded('user/login');
    $this->assertShielded('');
  }

  /**
   * Asserts that a path is answered with the Shield authentication prompt.
   *
   * @param string $path
   *   The path to request.
   */
  protected function assertShielded(string $path): void {
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(401);
    $this->assertSession()->responseHeaderEquals('X-Shield-Status', 'pending');
  }

}
