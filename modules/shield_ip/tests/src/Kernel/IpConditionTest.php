<?php

namespace Drupal\Tests\shield_ip\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the IP condition plugin.
 *
 * @group shield
 *
 * @coversDefaultClass \Drupal\shield_ip\Plugin\ShieldCondition\Ip
 */
#[RunTestsInSeparateProcesses]
class IpConditionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shield', 'shield_ip'];

  /**
   * Tests an exact IPv4 match.
   *
   * @covers ::evaluate
   */
  public function testExactIpv4Match(): void {
    $condition = $this->createCondition('127.0.0.1');

    $this->assertTrue($condition->evaluate($this->request('127.0.0.1')));
    $this->assertFalse($condition->evaluate($this->request('127.0.0.2')));
  }

  /**
   * Tests an IPv4 CIDR range match.
   *
   * @covers ::evaluate
   */
  public function testIpv4CidrRangeMatch(): void {
    $condition = $this->createCondition('192.168.0.0/24');

    $this->assertTrue($condition->evaluate($this->request('192.168.0.42')));
    $this->assertFalse($condition->evaluate($this->request('192.168.1.42')));
  }

  /**
   * Tests an exact IPv6 match.
   *
   * @covers ::evaluate
   */
  public function testExactIpv6Match(): void {
    $condition = $this->createCondition('2001:db8::8a2e:370:7334');

    $this->assertTrue($condition->evaluate($this->request('2001:db8::8a2e:370:7334')));
    $this->assertFalse($condition->evaluate($this->request('2001:db8::8a2e:370:7335')));
  }

  /**
   * Tests an IPv6 CIDR range match.
   *
   * @covers ::evaluate
   */
  public function testIpv6CidrRangeMatch(): void {
    $condition = $this->createCondition('2001:db8::/32');

    $this->assertTrue($condition->evaluate($this->request('2001:db8::8a2e:370:7334')));
    $this->assertFalse($condition->evaluate($this->request('2001:db9::8a2e:370:7334')));
  }

  /**
   * Tests that each line of the configured list is matched independently.
   *
   * @covers ::evaluate
   */
  public function testMultipleEntries(): void {
    $condition = $this->createCondition("127.0.0.1\n10.0.0.0/8");

    $this->assertTrue($condition->evaluate($this->request('127.0.0.1')));
    $this->assertTrue($condition->evaluate($this->request('10.1.2.3')));
    $this->assertFalse($condition->evaluate($this->request('192.168.0.1')));
  }

  /**
   * Tests that a request IP outside every configured entry does not match.
   *
   * @covers ::evaluate
   */
  public function testNoMatch(): void {
    $condition = $this->createCondition('127.0.0.1');

    $this->assertFalse($condition->evaluate($this->request('8.8.8.8')));
  }

  /**
   * Tests that an empty configuration never matches.
   *
   * @covers ::evaluate
   */
  public function testEmptyConfigurationNeverMatches(): void {
    $condition = $this->createCondition('');

    $this->assertFalse($condition->evaluate($this->request('127.0.0.1')));
    $this->assertFalse($condition->evaluate($this->request('::1')));
  }

  /**
   * Tests that blank lines and surrounding whitespace are ignored.
   *
   * @covers ::evaluate
   */
  public function testBlankLinesAndWhitespaceAreIgnored(): void {
    $condition = $this->createCondition("\n  127.0.0.1  \n\n");

    $this->assertTrue($condition->evaluate($this->request('127.0.0.1')));
  }

  /**
   * Tests that a malformed entry is ignored rather than matching everything.
   *
   * @covers ::evaluate
   */
  public function testMalformedEntryDoesNotMatch(): void {
    $condition = $this->createCondition('not-an-ip');

    $this->assertFalse($condition->evaluate($this->request('127.0.0.1')));
    $this->assertFalse($condition->evaluate($this->request('not-an-ip')));
  }

  /**
   * Tests the negate flag flips the match.
   *
   * @covers ::evaluate
   * @covers ::isNegated
   */
  public function testNegate(): void {
    $condition = $this->createCondition('127.0.0.1', TRUE);

    $this->assertTrue($condition->isNegated());
    $this->assertTrue($condition->evaluate($this->request('127.0.0.1')));
    $this->assertFalse($condition->evaluate($this->request('8.8.8.8')));
  }

  /**
   * Tests that the condition does not gate the page-cache kill switch.
   *
   * The client IP is not part of PageCache::getCacheId(), so this condition
   * must never be allowed to exclude a policy from
   * ShieldPolicy::matchesCacheableConditions()'s sweep.
   *
   * @covers ::affectsCacheability
   */
  public function testAffectsCacheability(): void {
    $condition = $this->createCondition('127.0.0.1');

    $this->assertFalse($condition->affectsCacheability());
  }

  /**
   * Creates a configured IP condition plugin instance.
   *
   * @param string $ips
   *   The configured IPs/CIDR ranges, one per line.
   * @param bool $negate
   *   Whether the condition should be negated.
   *
   * @return \Drupal\shield\ShieldConditionInterface
   *   The configured plugin instance.
   */
  protected function createCondition(string $ips, bool $negate = FALSE) {
    return $this->container->get('plugin.manager.shield.condition')
      ->createInstance('ip', ['ips' => $ips, 'negate' => $negate]);
  }

  /**
   * Builds a request with the given client IP.
   *
   * @param string $ip
   *   The client IP to simulate.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function request(string $ip): Request {
    $request = Request::create('/');
    $request->server->set('REMOTE_ADDR', $ip);
    return $request;
  }

}
