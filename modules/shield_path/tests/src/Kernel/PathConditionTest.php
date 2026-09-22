<?php

namespace Drupal\Tests\shield_path\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\Traits\Core\PathAliasTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Path condition plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_path\Plugin\ShieldCondition\Path
 */
#[RunTestsInSeparateProcesses]
class PathConditionTest extends KernelTestBase {

  use PathAliasTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_path', 'path_alias', 'language', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'language']);
  }

  /**
   * Tests an exact path pattern.
   *
   * @covers ::evaluate
   */
  public function testExactPath(): void {
    $condition = $this->createCondition('/admin/content');

    $this->assertTrue($condition->evaluate($this->request('/admin/content')));
    $this->assertFalse($condition->evaluate($this->request('/admin/config')));
  }

  /**
   * Tests that an empty pattern list never matches.
   *
   * @covers ::evaluate
   */
  public function testEmptyPathsNeverMatches(): void {
    $condition = $this->createCondition('');

    $this->assertFalse($condition->evaluate($this->request('/')));
    $this->assertFalse($condition->evaluate($this->request('/admin')));
  }

  /**
   * Tests a wildcard pattern.
   *
   * @covers ::evaluate
   */
  public function testWildcard(): void {
    $condition = $this->createCondition('/admin/*');

    $this->assertTrue($condition->evaluate($this->request('/admin/config/system')));
    // The wildcard requires the trailing slash, so the prefix alone is not
    // itself included.
    $this->assertFalse($condition->evaluate($this->request('/admin')));
  }

  /**
   * Tests that each line of the pattern list is matched independently.
   *
   * @covers ::evaluate
   */
  public function testMultiplePatterns(): void {
    $condition = $this->createCondition("/foo\n/bar");

    $this->assertTrue($condition->evaluate($this->request('/foo')));
    $this->assertTrue($condition->evaluate($this->request('/bar')));
    $this->assertFalse($condition->evaluate($this->request('/baz')));
  }

  /**
   * Tests that a trailing slash on the request path is ignored.
   *
   * @covers ::evaluate
   */
  public function testTrailingSlashIsIgnored(): void {
    $condition = $this->createCondition('/admin');

    $this->assertTrue($condition->evaluate($this->request('/admin/')));
  }

  /**
   * Tests the "<front>" token against the configured front page.
   *
   * @covers ::evaluate
   */
  public function testFrontPageToken(): void {
    $this->config('system.site')->set('page.front', '/node')->save();
    $condition = $this->createCondition('<front>');

    $this->assertTrue($condition->evaluate($this->request('/node')));
    $this->assertFalse($condition->evaluate($this->request('/user')));
  }

  /**
   * Tests that a request for an alias also matches its system path pattern.
   *
   * The condition resolves the request path to its underlying system path
   * before matching, so a pattern written in terms of the system path also
   * protects every alias pointing at it. The reverse does not hold: a pattern
   * written as the alias only matches a request for that alias itself, since
   * resolution only ever runs in the alias-to-system direction.
   *
   * @covers ::evaluate
   */
  public function testAliasResolvesToSystemPath(): void {
    $this->createPathAlias('/node/1', '/about-us');
    $condition = $this->createCondition('/node/1');

    $this->assertTrue($condition->evaluate($this->request('/node/1')));
    $this->assertTrue($condition->evaluate($this->request('/about-us')));
    $this->assertFalse($condition->evaluate($this->request('/node/2')));
  }

  /**
   * Tests that a pattern written as an alias does not also match the system
   * path.
   *
   * @covers ::evaluate
   */
  public function testAliasPatternDoesNotMatchSystemPath(): void {
    $this->createPathAlias('/node/1', '/about-us');
    $condition = $this->createCondition('/about-us');

    $this->assertTrue($condition->evaluate($this->request('/about-us')));
    $this->assertFalse($condition->evaluate($this->request('/node/1')));
  }

  /**
   * Tests that a configured language prefix is stripped before matching.
   *
   * @covers ::evaluate
   */
  public function testLanguagePrefixIsStripped(): void {
    $this->config('language.negotiation')->set('url.prefixes', ['fr' => 'fr'])->save();
    $condition = $this->createCondition('/admin');

    $this->assertTrue($condition->evaluate($this->request('/fr/admin')));
    // A request with no prefix at all still matches directly.
    $this->assertTrue($condition->evaluate($this->request('/admin')));
    // A prefix that is not configured is not stripped, so it does not match.
    $this->assertFalse($condition->evaluate($this->request('/de/admin')));
  }

  /**
   * Tests that a bare prefix request normalizes to the front root path.
   *
   * @covers ::evaluate
   */
  public function testLanguagePrefixAloneNormalizesToRoot(): void {
    $this->config('language.negotiation')->set('url.prefixes', ['fr' => 'fr'])->save();
    $condition = $this->createCondition('/');

    $this->assertTrue($condition->evaluate($this->request('/fr')));
  }

  /**
   * Tests that a path merely starting with the prefix string is untouched.
   *
   * Stripping must only happen on a full path segment, or a path like
   * "/france" would be corrupted by a configured "fr" prefix.
   *
   * @covers ::evaluate
   */
  public function testLanguagePrefixRequiresSegmentBoundary(): void {
    $this->config('language.negotiation')->set('url.prefixes', ['fr' => 'fr'])->save();
    $condition = $this->createCondition('/france');

    $this->assertTrue($condition->evaluate($this->request('/france')));
  }

  /**
   * Creates a configured Path condition plugin instance.
   *
   * @param string $paths
   *   The configured path patterns, one per line.
   *
   * @return \Drupal\shield\ShieldConditionInterface
   *   The configured plugin instance.
   */
  protected function createCondition(string $paths) {
    return $this->container->get('plugin.manager.shield.condition')
      ->createInstance('path', ['paths' => $paths]);
  }

  /**
   * Builds a request for the given path.
   *
   * @param string $path
   *   The path to request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function request(string $path): Request {
    return Request::create($path);
  }

}
