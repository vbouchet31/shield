<?php

namespace Drupal\Tests\shield_domain\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Domain condition plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_domain\Plugin\ShieldCondition\Domain
 */
#[RunTestsInSeparateProcesses]
class DomainConditionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_domain'];

  /**
   * Tests an exact domain match.
   *
   * @covers ::evaluate
   */
  public function testExactDomainMatch(): void {
    $condition = $this->createCondition('www.example.com');

    $this->assertTrue($condition->evaluate($this->request('www.example.com')));
    $this->assertFalse($condition->evaluate($this->request('admin.example.com')));
  }

  /**
   * Tests that an empty pattern list never matches.
   *
   * @covers ::evaluate
   */
  public function testEmptyDomainsNeverMatches(): void {
    $condition = $this->createCondition('');

    $this->assertFalse($condition->evaluate($this->request('www.example.com')));
  }

  /**
   * Tests a wildcard pattern.
   *
   * @covers ::evaluate
   */
  public function testWildcard(): void {
    $condition = $this->createCondition('*.example.com');

    $this->assertTrue($condition->evaluate($this->request('www.example.com')));
    $this->assertTrue($condition->evaluate($this->request('cms.internal.example.com')));
    $this->assertFalse($condition->evaluate($this->request('example.com')));
  }

  /**
   * Tests that each line of the pattern list is matched independently.
   *
   * @covers ::evaluate
   */
  public function testMultipleDomains(): void {
    $condition = $this->createCondition("first.example.com\nwww.example.com");

    $this->assertTrue($condition->evaluate($this->request('first.example.com')));
    $this->assertTrue($condition->evaluate($this->request('www.example.com')));
    $this->assertFalse($condition->evaluate($this->request('admin.example.com')));
  }

  /**
   * Tests that surrounding whitespace around a pattern is ignored.
   *
   * 1.x stripped every space character from the configured list, not just
   * per-line leading/trailing whitespace.
   *
   * @covers ::evaluate
   */
  public function testSurroundingWhitespaceIsIgnored(): void {
    $condition = $this->createCondition('  www.example.com  ');

    $this->assertTrue($condition->evaluate($this->request('www.example.com')));
  }

  /**
   * Tests that matching is case-sensitive, mirroring 1.x behaviour.
   *
   * Request::getHost() always returns a lower-cased host, so a pattern
   * configured in a different case never matches - this is not a deliberate
   * case-insensitive comparison, just a consequence of neither side
   * normalizing case.
   *
   * @covers ::evaluate
   */
  public function testMatchingIsCaseSensitiveToConfiguredPattern(): void {
    $condition = $this->createCondition('WWW.EXAMPLE.COM');

    $this->assertFalse($condition->evaluate($this->request('www.example.com')));
  }

  /**
   * Tests the negate flag's include/exclude equivalent behaviour.
   *
   * evaluate() itself only ever answers "is the host in the configured
   * list" and is unaffected by negate - the policy entity is what applies
   * the XOR (see \Drupal\shield\Entity\ShieldPolicy::matchesConditions()).
   * Applying that XOR by hand here shows the two 1.x methods this replaces:
   * without negate, only a listed domain matches - equivalent to 1.x's
   * INCLUDE_METHOD. With negate, every domain except the listed ones matches
   * - equivalent to 1.x's EXCLUDE_METHOD.
   *
   * @covers ::evaluate
   * @covers \Drupal\shield\ShieldConditionBase::isNegated
   */
  public function testNegateIsIncludeExcludeEquivalent(): void {
    $included = $this->createCondition('admin.example.com');
    $this->assertFalse($included->isNegated());
    $this->assertTrue($included->evaluate($this->request('admin.example.com')));
    $this->assertFalse($included->evaluate($this->request('www.example.com')));

    $excluded = $this->createCondition('admin.example.com', TRUE);
    $this->assertTrue($excluded->isNegated());
    // Raw evaluate() is identical to the non-negated instance; matching
    // policy behaviour (protects everything except admin.example.com) only
    // emerges once matchesConditions() XORs this with isNegated().
    $this->assertTrue($excluded->evaluate($this->request('admin.example.com')));
    $this->assertFalse($excluded->evaluate($this->request('www.example.com')));
  }

  /**
   * Tests that the condition affects cacheability.
   *
   * @covers ::affectsCacheability
   */
  public function testAffectsCacheability(): void {
    $condition = $this->createCondition('www.example.com');

    $this->assertTrue($condition->affectsCacheability());
  }

  /**
   * Creates a configured Domain condition plugin instance.
   *
   * @param string $domains
   *   The configured domain patterns, one per line.
   * @param bool $negate
   *   Whether the condition should be negated.
   *
   * @return \Drupal\shield\ShieldConditionInterface
   *   The configured plugin instance.
   */
  protected function createCondition(string $domains, bool $negate = FALSE) {
    return $this->container->get('plugin.manager.shield.condition')
      ->createInstance('domain', ['domains' => $domains, 'negate' => $negate]);
  }

  /**
   * Builds a request for the given host.
   *
   * @param string $host
   *   The host to request.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function request(string $host): Request {
    return Request::create('https://' . $host . '/');
  }

}
