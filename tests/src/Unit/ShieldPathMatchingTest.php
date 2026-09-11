<?php

namespace Drupal\Tests\shield\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\shield\ShieldMiddleware;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests that Shield matches paths and domains without Drupal services.
 *
 * Shield's middleware runs before Drupal loads its module files. Calling the
 * path matcher, the alias manager or the language manager from there fires
 * hooks with an incomplete implementation list, which then gets cached.
 *
 * @group shield
 *
 * @see https://www.drupal.org/project/shield/issues/3277210
 *
 * @coversDefaultClass \Drupal\shield\ShieldMiddleware
 */
class ShieldPathMatchingTest extends UnitTestCase {

  /**
   * Builds a config factory answering the language.negotiation prefixes.
   *
   * @param array $prefixes
   *   The language prefixes to report.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The stubbed config factory.
   */
  protected function buildConfigFactory(array $prefixes = []): ConfigFactoryInterface {
    $negotiation = $this->createMock(ImmutableConfig::class);
    $negotiation->method('get')
      ->with('url.prefixes')
      ->willReturn($prefixes);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('language.negotiation')
      ->willReturn($negotiation);

    return $config_factory;
  }

  /**
   * Builds a middleware using the current constructor signature.
   *
   * @param array $prefixes
   *   The language prefixes to report from language.negotiation.
   *
   * @return \Drupal\shield\ShieldMiddleware
   *   The middleware to test.
   */
  protected function buildMiddleware(array $prefixes = []): ShieldMiddleware {
    return new ShieldMiddleware(
      $this->createMock(HttpKernelInterface::class),
      $this->buildConfigFactory($prefixes),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      new RequestStack(),
      $this->createMock(LoggerInterface::class)
    );
  }

  /**
   * Builds the shield.settings stub used by ::checkPathAllowed().
   *
   * @param string $paths
   *   The configured paths.
   * @param int $method
   *   Either ShieldMiddleware::EXCLUDE_METHOD or ::INCLUDE_METHOD.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The stubbed configuration.
   */
  protected function buildSettings(string $paths, int $method): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['paths', $paths],
      ['method', $method],
    ]);
    return $config;
  }

  /**
   * Tests path matching against the incoming request only.
   *
   * @param string $path
   *   The incoming request path.
   * @param string $paths
   *   The configured paths.
   * @param int $method
   *   Either ShieldMiddleware::EXCLUDE_METHOD or ::INCLUDE_METHOD.
   * @param bool $expected
   *   Whether Shield is expected to let the request through.
   *
   * @dataProvider providerCheckPathAllowed
   *
   * @covers ::checkPathAllowed
   */
  public function testCheckPathAllowed(string $path, string $paths, int $method, bool $expected): void {
    $middleware = $this->buildMiddleware();
    $config = $this->buildSettings($paths, $method);

    $this->assertSame($expected, $middleware->checkPathAllowed(Request::create($path), $config));
  }

  /**
   * Data provider for ::testCheckPathAllowed().
   *
   * @return array
   *   Sets of a request path, configured paths, the method and the result.
   */
  public static function providerCheckPathAllowed(): array {
    return [
      'no excluded path protects everything' => [
        '/user/login', '', ShieldMiddleware::EXCLUDE_METHOD, FALSE,
      ],
      'no included path protects nothing' => [
        '/user/login', '', ShieldMiddleware::INCLUDE_METHOD, TRUE,
      ],
      'literal excluded path' => [
        '/user/login', '/user/login', ShieldMiddleware::EXCLUDE_METHOD, TRUE,
      ],
      'other path stays protected' => [
        '/admin', '/user/login', ShieldMiddleware::EXCLUDE_METHOD, FALSE,
      ],
      'one of several excluded paths' => [
        '/api/status',
        "/user/login\n/api/status",
        ShieldMiddleware::EXCLUDE_METHOD,
        TRUE,
      ],
      'blank lines and stray whitespace' => [
        '/api/status',
        "  /user/login  \r\n\r\n\t/api/status \r\n",
        ShieldMiddleware::EXCLUDE_METHOD,
        TRUE,
      ],
      'wildcard' => [
        '/api/v2/status', '/api/*/status', ShieldMiddleware::EXCLUDE_METHOD, TRUE,
      ],
      'trailing slash is ignored' => [
        '/user/login/', '/user/login', ShieldMiddleware::EXCLUDE_METHOD, TRUE,
      ],
      'front keyword matches the site root' => [
        '/', '<front>', ShieldMiddleware::EXCLUDE_METHOD, TRUE,
      ],
      'regular expression characters stay literal' => [
        '/file/name.php', '/file/name_php', ShieldMiddleware::EXCLUDE_METHOD, FALSE,
      ],
      'included path is protected' => [
        '/user/login', '/user/*', ShieldMiddleware::INCLUDE_METHOD, FALSE,
      ],
      'path outside the included paths is not protected' => [
        '/about', '/user/*', ShieldMiddleware::INCLUDE_METHOD, TRUE,
      ],
    ];
  }

  /**
   * Tests that a configured language prefix is stripped before matching.
   *
   * @covers ::checkPathAllowed
   * @covers ::stripLanguagePrefix
   */
  public function testLanguagePrefixIsStripped(): void {
    $middleware = $this->buildMiddleware(['en' => 'en', 'nl' => 'nl']);
    $config = $this->buildSettings('/user/login', ShieldMiddleware::EXCLUDE_METHOD);

    $this->assertTrue($middleware->checkPathAllowed(Request::create('/nl/user/login'), $config));
    $this->assertTrue($middleware->checkPathAllowed(Request::create('/user/login'), $config));
    $this->assertFalse($middleware->checkPathAllowed(Request::create('/de/user/login'), $config));
  }

  /**
   * Tests a prefix that differs from the language code it belongs to.
   *
   * Site builders are free to set any prefix in the URL language detection
   * settings, so the prefix cannot be inferred from the language code.
   *
   * @covers ::checkPathAllowed
   * @covers ::stripLanguagePrefix
   *
   * @see https://www.drupal.org/project/shield/issues/3281594
   */
  public function testCustomLanguagePrefixIsStripped(): void {
    $middleware = $this->buildMiddleware(['en' => '', 'nl' => 'dutch']);
    $config = $this->buildSettings('/user/login', ShieldMiddleware::EXCLUDE_METHOD);

    $this->assertTrue($middleware->checkPathAllowed(Request::create('/dutch/user/login'), $config));
    $this->assertTrue($middleware->checkPathAllowed(Request::create('/user/login'), $config));
    // The language code itself is not a prefix, so it is not stripped.
    $this->assertFalse($middleware->checkPathAllowed(Request::create('/nl/user/login'), $config));
  }

  /**
   * Tests that the front page of a prefixed language is matched.
   *
   * @covers ::checkPathAllowed
   * @covers ::stripLanguagePrefix
   */
  public function testLanguagePrefixedFrontPage(): void {
    $middleware = $this->buildMiddleware(['nl' => 'nl']);
    $config = $this->buildSettings('<front>', ShieldMiddleware::EXCLUDE_METHOD);

    $this->assertTrue($middleware->checkPathAllowed(Request::create('/nl'), $config));
    $this->assertTrue($middleware->checkPathAllowed(Request::create('/'), $config));
    $this->assertFalse($middleware->checkPathAllowed(Request::create('/nl/about'), $config));
  }

  /**
   * Tests that an unprefixed path is left alone when no language is set up.
   *
   * @covers ::stripLanguagePrefix
   */
  public function testPathWithoutLanguageNegotiation(): void {
    $middleware = $this->buildMiddleware();
    $config = $this->buildSettings('/en/user/login', ShieldMiddleware::EXCLUDE_METHOD);

    $this->assertTrue($middleware->checkPathAllowed(Request::create('/en/user/login'), $config));
  }

  /**
   * Tests that the deprecated constructor signature still works.
   *
   * The three services it takes are mocked without any expectation, so the
   * test fails if Shield calls one of them while matching a path.
   *
   * @covers ::__construct
   */
  public function testDeprecatedConstructorArguments(): void {
    $path_matcher = $this->createMock(PathMatcherInterface::class);
    $path_matcher->expects($this->never())->method($this->anything());
    $alias_manager = $this->createMock(AliasManagerInterface::class);
    $alias_manager->expects($this->never())->method($this->anything());
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->expects($this->never())->method($this->anything());

    $deprecations = [];
    set_error_handler(static function ($severity, $message) use (&$deprecations) {
      $deprecations[] = $message;
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      $middleware = new ShieldMiddleware(
        $this->createMock(HttpKernelInterface::class),
        $this->buildConfigFactory(['nl' => 'nl']),
        $this->createMock(EntityTypeManagerInterface::class),
        $path_matcher,
        $alias_manager,
        $language_manager,
        $this->createMock(ModuleHandlerInterface::class),
        new RequestStack(),
        $this->createMock(LoggerInterface::class)
      );
    }
    finally {
      restore_error_handler();
    }

    $this->assertCount(1, $deprecations);
    $this->assertStringContainsString('is deprecated in shield:8.x-1.9', $deprecations[0]);

    $config = $this->buildSettings('/user/login', ShieldMiddleware::EXCLUDE_METHOD);
    $this->assertTrue($middleware->checkPathAllowed(Request::create('/nl/user/login'), $config));
  }

  /**
   * Tests that the current constructor signature raises no deprecation.
   *
   * @covers ::__construct
   */
  public function testCurrentConstructorArguments(): void {
    $deprecations = [];
    set_error_handler(static function ($severity, $message) use (&$deprecations) {
      $deprecations[] = $message;
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      $this->buildMiddleware();
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $deprecations);
  }

  /**
   * Tests that a missing module handler is rejected outright.
   *
   * @covers ::__construct
   */
  public function testModuleHandlerIsRequired(): void {
    // The deprecated signature is what gets us here, so silence its notice.
    set_error_handler(static fn () => TRUE, E_USER_DEPRECATED);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('The module handler is required');

      new ShieldMiddleware(
        $this->createMock(HttpKernelInterface::class),
        $this->buildConfigFactory(),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(PathMatcherInterface::class),
        $this->createMock(AliasManagerInterface::class),
        $this->createMock(LanguageManagerInterface::class),
        NULL,
        new RequestStack(),
        $this->createMock(LoggerInterface::class)
      );
    }
    finally {
      restore_error_handler();
    }
  }

}
