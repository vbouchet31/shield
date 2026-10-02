<?php

namespace Drupal\Tests\shield_user_agent\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the User agent condition plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_user_agent\Plugin\ShieldCondition\UserAgent
 */
#[RunTestsInSeparateProcesses]
class UserAgentConditionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_user_agent'];

  /**
   * Tests that a configured substring matches the User-Agent header.
   *
   * @covers ::evaluate
   */
  public function testSubstringMatch(): void {
    $condition = $this->createCondition('facebookexternalhit');

    $this->assertTrue($condition->evaluate($this->request('facebookexternalhit/1.1')));
  }

  /**
   * Tests that matching is case-insensitive.
   *
   * @covers ::evaluate
   */
  public function testCaseInsensitiveMatch(): void {
    $condition = $this->createCondition('Twitterbot');

    $this->assertTrue($condition->evaluate($this->request('twitterbot/1.0')));
    $this->assertTrue($condition->evaluate($this->request('TWITTERBOT/1.0')));
  }

  /**
   * Tests that a request whose user agent does not contain any configured
   * substring does not match.
   *
   * @covers ::evaluate
   */
  public function testNoMatch(): void {
    $condition = $this->createCondition('facebookexternalhit');

    $this->assertFalse($condition->evaluate($this->request('Mozilla/5.0')));
  }

  /**
   * Tests that a missing User-Agent header never matches.
   *
   * @covers ::evaluate
   */
  public function testMissingUserAgentHeaderNeverMatches(): void {
    $condition = $this->createCondition('facebookexternalhit');

    $request = Request::create('/');
    $request->headers->remove('User-Agent');
    $this->assertFalse($condition->evaluate($request));
  }

  /**
   * Tests that an empty User-Agent header never matches.
   *
   * @covers ::evaluate
   */
  public function testEmptyUserAgentHeaderNeverMatches(): void {
    $condition = $this->createCondition('facebookexternalhit');

    $this->assertFalse($condition->evaluate($this->request('')));
  }

  /**
   * Tests that an empty configured list never matches.
   *
   * @covers ::evaluate
   */
  public function testEmptyPatternsNeverMatches(): void {
    $condition = $this->createCondition('');

    $this->assertFalse($condition->evaluate($this->request('Mozilla/5.0')));
  }

  /**
   * Tests that each configured line is matched independently.
   *
   * @covers ::evaluate
   */
  public function testMultiplePatterns(): void {
    $condition = $this->createCondition("facebookexternalhit\nTwitterbot");

    $this->assertTrue($condition->evaluate($this->request('facebookexternalhit/1.1')));
    $this->assertTrue($condition->evaluate($this->request('Twitterbot/1.0')));
    $this->assertFalse($condition->evaluate($this->request('Mozilla/5.0')));
  }

  /**
   * Tests that the negate flag flips the match result.
   *
   * @covers ::isNegated
   */
  public function testNegateFlag(): void {
    $condition = $this->createCondition('facebookexternalhit', TRUE);

    $this->assertTrue($condition->isNegated());
    $this->assertTrue($condition->evaluate($this->request('facebookexternalhit/1.1')));
    $this->assertFalse($condition->evaluate($this->request('Mozilla/5.0')));
  }

  /**
   * Tests that the condition does not gate the page-cache kill switch.
   *
   * The User-Agent header is not part of PageCache::getCacheId(), so this
   * condition must never be allowed to exclude a policy from
   * ShieldPolicy::matchesCacheableConditions()'s sweep.
   *
   * @covers ::affectsCacheability
   */
  public function testAffectsCacheability(): void {
    $condition = $this->createCondition('facebookexternalhit');

    $this->assertFalse($condition->affectsCacheability());
  }

  /**
   * Creates a configured User agent condition plugin instance.
   *
   * @param string $user_agents
   *   The configured user agent substrings, one per line.
   * @param bool $negate
   *   Whether the condition should be negated.
   *
   * @return \Drupal\shield\ShieldConditionInterface
   *   The configured plugin instance.
   */
  protected function createCondition(string $user_agents, bool $negate = FALSE) {
    return $this->container->get('plugin.manager.shield.condition')
      ->createInstance('user_agent', ['user_agents' => $user_agents, 'negate' => $negate]);
  }

  /**
   * Builds a request with the given User-Agent header.
   *
   * @param string $user_agent
   *   The User-Agent header value.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function request(string $user_agent): Request {
    $request = Request::create('/');
    $request->headers->set('User-Agent', $user_agent);
    return $request;
  }

}
