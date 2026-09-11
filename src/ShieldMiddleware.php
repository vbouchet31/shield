<?php

namespace Drupal\shield;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Middleware for the shield module.
 */
class ShieldMiddleware implements HttpKernelInterface {

  /**
   * Constants representing if configured values should be included or excluded.
   */
  const EXCLUDE_METHOD = 0;
  const INCLUDE_METHOD = 1;

  /**
   * Provide BC compatibility for Drupal 9 / Symfony 4.x.
   */
  const MAIN_REQUEST = 1;

  /**
   * The decorated kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The path matcher.
   *
   * NULL unless the deprecated constructor signature was used.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface|null
   *
   * @deprecated in shield:8.x-1.9 and is removed from shield:2.0.0. Shield's
   *   middleware runs before Drupal loads its module files, so the path
   *   matcher cannot be called from it. There is no replacement.
   *
   * @see https://www.drupal.org/project/shield/issues/3277210
   */
  protected $pathMatcher;

  /**
   * The path alias manager.
   *
   * NULL unless the deprecated constructor signature was used.
   *
   * @var \Drupal\path_alias\AliasManagerInterface|null
   *
   * @deprecated in shield:8.x-1.9 and is removed from shield:2.0.0. Shield's
   *   middleware runs before Drupal loads its module files, so the path alias
   *   manager cannot be called from it. There is no replacement.
   *
   * @see https://www.drupal.org/project/shield/issues/3277210
   */
  protected $pathAliasManager;

  /**
   * Language Manager.
   *
   * NULL unless the deprecated constructor signature was used.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|null
   *
   * @deprecated in shield:8.x-1.9 and is removed from shield:2.0.0. Shield's
   *   middleware runs before Drupal loads its module files, so the language
   *   manager cannot be called from it. Read the prefixes from the
   *   language.negotiation configuration instead.
   *
   * @see https://www.drupal.org/project/shield/issues/3277210
   */
  protected $languageManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The shield logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Basic auth results of the requests seen so far, keyed by object id.
   *
   * @var bool[]
   */
  protected $basicAuthResults = [];

  /**
   * Constructs a ShieldMiddleware object.
   *
   * The path matcher, the path alias manager and the language manager are no
   * longer used and are removed from the signature in shield:2.0.0. Code that
   * has to run on both branches can already leave them out: pass the module
   * handler as the fourth argument and the request stack as the fifth.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|\Drupal\Core\Path\PathMatcherInterface $module_handler
   *   The module handler. Passing the path matcher here is deprecated in
   *   shield:8.x-1.9 and unsupported in shield:2.0.0.
   * @param \Symfony\Component\HttpFoundation\RequestStack|\Drupal\path_alias\AliasManagerInterface $requestStack
   *   The request stack. Passing the path alias manager here is deprecated in
   *   shield:8.x-1.9 and unsupported in shield:2.0.0.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $language_manager
   *   Deprecated in shield:8.x-1.9 and removed from shield:2.0.0.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $legacy_module_handler
   *   Deprecated in shield:8.x-1.9 and removed from shield:2.0.0.
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $legacy_request_stack
   *   Deprecated in shield:8.x-1.9 and removed from shield:2.0.0.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The shield logger channel. On the current signature it is the sixth
   *   argument; on the deprecated one it is the ninth. Omitting it is
   *   deprecated in shield:8.x-1.9 and it will be required in shield:2.0.0.
   *
   * @see https://www.drupal.org/project/shield/issues/3277210
   */
  public function __construct(HttpKernelInterface $http_kernel, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, $module_handler, $requestStack, $language_manager = NULL, $legacy_module_handler = NULL, $legacy_request_stack = NULL, ?LoggerInterface $logger = NULL) {
    // The logger follows the request stack on the current signature, which
    // is the slot the language manager occupies on the deprecated one.
    if ($logger === NULL && $language_manager instanceof LoggerInterface) {
      $logger = $language_manager;
      $language_manager = NULL;
    }

    $this->httpKernel = $http_kernel;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;

    if (!$module_handler instanceof ModuleHandlerInterface) {
      @trigger_error('Passing the path matcher, the path alias manager and the language manager to ' . __METHOD__ . '() is deprecated in shield:8.x-1.9 and is unsupported in shield:2.0.0. Pass the module handler as the fourth argument and the request stack as the fifth. See https://www.drupal.org/project/shield/issues/3277210', E_USER_DEPRECATED);
      // Populating the deprecated properties is the whole point of this
      // branch: subclasses on the old signature may still read them.
      // @phpstan-ignore-next-line
      $this->pathMatcher = $module_handler;
      // @phpstan-ignore-next-line
      $this->pathAliasManager = $requestStack;
      // @phpstan-ignore-next-line
      $this->languageManager = $language_manager;
      $module_handler = $legacy_module_handler;
      $requestStack = $legacy_request_stack;
    }

    if (!$module_handler instanceof ModuleHandlerInterface) {
      throw new \InvalidArgumentException('The module handler is required by ' . __METHOD__ . '().');
    }
    if (!$requestStack instanceof RequestStack) {
      throw new \InvalidArgumentException('The request stack is required by ' . __METHOD__ . '().');
    }

    $this->moduleHandler = $module_handler;
    $this->requestStack = $requestStack;
    if ($logger === NULL) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $logger argument is deprecated in shield:8.x-1.9 and will be required in shield:2.0.0. See https://www.drupal.org/node/3266227', E_USER_DEPRECATED);
      $logger = \Drupal::service('logger.channel.shield');
    }
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $config = $this->configFactory->get('shield.settings');

    // Bypass as shield is disabled.
    if (!$config->get('shield_enable')) {
      $request->headers->set('X-Shield-Status', 'disabled');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as it is a subrequest.
    if ($type != self::MAIN_REQUEST) {
      $request->headers->set('X-Shield-Status', 'skipped (subrequest)');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as shield is configured to allow CLI.
    if (PHP_SAPI === 'cli' && $config->get('allow_cli')) {
      $request->headers->set('X-Shield-Status', 'skipped (cli)');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as the path is allowed.
    if ($this->checkPathAllowed($request, $config)) {
      $request->headers->set('X-Shield-Status', 'skipped (path)');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as the HTTP method is allowed.
    if (!empty($config->get('http_method_allowlist')[strtolower($request->getMethod())])) {
      $request->headers->set('X-Shield-Status', 'skipped (http method)');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as the IP is allowed.
    if ($allowlist = $config->get('allowlist')) {
      $allowlist = array_filter(array_map('trim', explode("\n", $allowlist)));
      if (IpUtils::checkIp($request->getClientIp(), $allowlist)) {
        $request->headers->set('X-Shield-Status', 'skipped (ip)');
        return $this->bypass($request, $type, $catch);
      }
    }

    // Bypass as the domain is allowed.
    if ($this->checkDomainAllowed($request, $config)) {
      $request->headers->set('X-Shield-Status', 'skipped (domain)');
      return $this->bypass($request, $type, $catch);
    }

    // Bypass as the user agent is allowed.
    if ($this->checkUserAgentAllowed($request, $config)) {
      $request->headers->set('X-Shield-Status', 'skipped (user agent)');
      return $this->bypass($request, $type, $catch);
    }

    // Check if user has provided credentials.
    $user = NULL;
    $pass = NULL;
    $credential_provider = $config->get('credential_provider');

    // The key and multikey providers load a key entity. Entity discovery runs
    // hook_entity_type_build() and friends, so the module files have to be
    // loaded first or the module_implements cache is populated with an
    // incomplete implementation list.
    // @see https://www.drupal.org/project/shield/issues/3277210
    if ($credential_provider === 'key' || $credential_provider === 'multikey') {
      $this->moduleHandler->loadAll();
    }

    switch ($credential_provider) {
      case 'shield':
        $user = $config->get('credentials.shield.user');
        $pass = $config->get('credentials.shield.pass');
        break;

      case 'key':
        $user = $config->get('credentials.key.user');

        /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
        $storage = $this->entityTypeManager->getStorage('key');
        /** @var \Drupal\key\KeyInterface $pass_key */
        $pass_key = $storage->load($config->get('credentials.key.pass_key'));
        if ($pass_key) {
          $pass = $pass_key->getKeyValue();
        }
        break;

      case 'multikey':
        /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
        $storage = $this->entityTypeManager->getStorage('key');
        /** @var \Drupal\key\KeyInterface $user_pass_key */
        $user_pass_key = $storage->load($config->get('credentials.multikey.user_pass_key'));
        if ($user_pass_key) {
          $values = $user_pass_key->getKeyValues();
          $user = $values['username'];
          $pass = $values['password'];
        }
        break;
    }
    $credentials = $this->getRequestCredentials($request);
    if ($credentials !== NULL && isset($user, $pass) && hash_equals($user, $credentials[0]) && hash_equals($pass, $credentials[1])) {
      $request->headers->set('X-Shield-Status', 'authenticated');
      if ($config->get('log_authentication')) {
        $this->logger->notice('Authenticated %user from %ip on %path.', [
          '%user' => $user,
          '%ip' => $request->getClientIp(),
          '%path' => $request->getPathInfo(),
        ]);
      }
      return $this->bypass($request, $type, $catch);
    }

    // Let a request through that carries the credentials of a Drupal account,
    // if the site opted in. Core's basic_auth provider performs the check, so
    // flood control, blocked accounts and password hashing are all handled
    // there rather than reimplemented here.
    if ($config->get('basic_auth_bypass')
      && $this->moduleHandler->moduleExists('basic_auth')
      && $this->basicAuthRequestAuthenticate($request)) {
      $request->headers->set('X-Shield-Status', 'skipped (basic auth)');
      return $this->bypass($request, $type, $catch);
    }

    $response = new Response();
    $response->headers->add([
      'WWW-Authenticate' => 'Basic realm="' . strtr($config->get('print'), [
        '[user]' => $user ?? '',
        '[pass]' => $pass ?? '',
      ]) . '"',
    ]);
    if ($config->get('debug_header')) {
      $response->headers->add(['X-Shield-Status' => 'pending']);
    }

    $response->setStatusCode(401);
    return $response;
  }

  /**
   * Extracts the basic authentication credentials sent with the request.
   *
   * PHP only populates PHP_AUTH_USER and PHP_AUTH_PW when it runs as an Apache
   * module. On CGI, FastCGI and FPM setups - which most shared hosting uses -
   * the Authorization header has to be read back from wherever the web server
   * left it, which is either HTTP_AUTHORIZATION, or the REDIRECT_ prefixed
   * copy Apache creates for env variables set by a rewrite rule. Some hosts
   * only allow the header to be forwarded in REMOTE_USER, so that variable is
   * inspected as well.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to read the credentials from.
   *
   * @return string[]|null
   *   An indexed array holding the submitted user name and password, or NULL
   *   if the request carries no basic authentication credentials.
   *
   * @see https://www.drupal.org/project/shield/issues/2902135
   */
  protected function getRequestCredentials(Request $request) {
    if ($request->server->has('PHP_AUTH_USER') && $request->server->has('PHP_AUTH_PW')) {
      return [
        (string) $request->server->get('PHP_AUTH_USER'),
        (string) $request->server->get('PHP_AUTH_PW'),
      ];
    }

    $variables = [
      'HTTP_AUTHORIZATION',
      'REDIRECT_HTTP_AUTHORIZATION',
      'REMOTE_USER',
      'REDIRECT_REMOTE_USER',
    ];
    foreach ($variables as $variable) {
      $header = $request->server->get($variable);
      if (!is_string($header) || stripos($header, 'basic ') !== 0) {
        continue;
      }

      $credentials = base64_decode(substr($header, 6), TRUE);
      if ($credentials === FALSE || !str_contains($credentials, ':')) {
        continue;
      }

      return explode(':', $credentials, 2);
    }

    return NULL;
  }

  /**
   * Forward the request to the normal Kernel for processing.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param int $type
   *   The type of the request.
   * @param bool $catch
   *   Whether to catch exceptions or not.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A Response instance.
   *
   * @throws \Exception
   *   When an Exception occurs during processing.
   */
  public function bypass(Request $request, $type, $catch) {
    $basic_auth_enabled = $this->moduleHandler->moduleExists('basic_auth');
    $config = $this->configFactory->get('shield.settings');
    if ($basic_auth_enabled && $config->get('unset_basic_auth_headers') && !$this->basicAuthRequestAuthenticate($request)) {
      // Unset basic auth headers to prevent basic_auth trigger on
      // subsequent kernel calls.
      $request->headers->remove('PHP_AUTH_USER');
      $request->headers->remove('PHP_AUTH_PW');
    }

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Checks if the current path should be allowed to bypass shield.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The global request object.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The current Shield config.
   *
   * @return bool
   *   TRUE if the current path should be bypassed, and FALSE if not.
   */
  public function checkPathAllowed(Request $request, ImmutableConfig $config) {
    $paths_to_check = $config->get('paths');
    $method = $config->get('method');

    // If nothing specified in path config we can return early.
    if (empty($paths_to_check)) {
      if ($method == self::EXCLUDE_METHOD) {
        return FALSE;
      }
      elseif ($method == self::INCLUDE_METHOD) {
        return TRUE;
      }
    }

    // Shield runs before Drupal's own kernel_pre_handle middleware, so the
    // incoming URL is all that can safely be inspected here. Anything routed
    // through the path alias manager, the language manager or the path matcher
    // ends up invoking hooks before the module files are loaded.
    // @see https://www.drupal.org/project/shield/issues/3277210
    $path = $this->stripLanguagePrefix($request->getPathInfo());

    // Remove trailing slash.
    $path = rtrim($path, '/');

    // Make it simple slash again for home page.
    $path = $path === '' ? '/' : $path;

    $path_match = $this->matchPatterns($path, $paths_to_check, TRUE);

    return $path_match && $method == self::EXCLUDE_METHOD || !$path_match && $method == self::INCLUDE_METHOD;
  }

  /**
   * Checks if the request user agent should be allowed to bypass shield.
   *
   * The user agent is supplied by the client and can be set to anything, so
   * this is a convenience for letting well behaved crawlers through, not a
   * security boundary.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The global request object.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The current Shield config.
   *
   * @return bool
   *   TRUE if the request user agent should be bypassed, and FALSE if not.
   */
  public function checkUserAgentAllowed(Request $request, ImmutableConfig $config) {
    $user_agent = $request->headers->get('User-Agent');
    $allowlist = (string) $config->get('user_agents');

    if ($user_agent === NULL || $user_agent === '' || trim($allowlist) === '') {
      return FALSE;
    }

    $patterns = array_filter(array_map('trim', explode("\n", $allowlist)), 'strlen');
    foreach ($patterns as $pattern) {
      if (stripos($user_agent, $pattern) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Removes a configured language prefix from the start of a path.
   *
   * The language manager cannot be used here: it loads configurable language
   * entities. The negotiation settings hold the same prefixes and are a plain
   * config read.
   *
   * @param string $path
   *   The incoming request path.
   *
   * @return string
   *   The path without its language prefix, if it had one.
   */
  protected function stripLanguagePrefix(string $path): string {
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    if (empty($prefixes) || !is_array($prefixes)) {
      return $path;
    }

    foreach ($prefixes as $prefix) {
      if ($prefix === '' || !is_string($prefix)) {
        continue;
      }
      if ($path === '/' . $prefix) {
        return '/';
      }
      if (str_starts_with($path, '/' . $prefix . '/')) {
        return substr($path, strlen($prefix) + 1);
      }
    }

    return $path;
  }

  /**
   * Matches a value against newline separated literal or wildcard patterns.
   *
   * This deliberately does not use the path.matcher service. That service
   * expands the <front> keyword by reading system.site, which config overrides
   * may answer with an entity query, and entity queries are not safe before
   * the module files are loaded.
   *
   * @param string $value
   *   The path or host name to match.
   * @param string $patterns
   *   Newline separated patterns. An asterisk matches any number of
   *   characters.
   * @param bool $is_path
   *   Whether the value is a path, in which case <front> matches the site
   *   root.
   *
   * @return bool
   *   TRUE if one of the patterns matches the value.
   */
  protected function matchPatterns(string $value, string $patterns, bool $is_path = FALSE): bool {
    foreach (preg_split('/\R/u', $patterns) ?: [] as $pattern) {
      $pattern = trim($pattern);
      if ($pattern === '') {
        continue;
      }
      if ($is_path && $pattern === '<front>') {
        $pattern = '/';
      }
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
      if (preg_match($regex, $value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if the current domain should be allowed to bypass shield.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The global request object.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The current Shield config.
   *
   * @return bool
   *   TRUE if the current domain should be bypassed, and FALSE if not.
   */
  public function checkDomainAllowed(Request $request, ImmutableConfig $config) {
    $domains_to_check = str_replace(' ', '', (string) $config->get('domains'));
    $method = (int) $config->get('domains_method');

    // An empty list singles out no domain at all: in exclude mode there is
    // nothing to let through, and in include mode there is nothing to narrow
    // protection down to. Either way the whole site stays protected, so the
    // include method cannot leave every domain unprotected at once.
    if ($domains_to_check === '') {
      return FALSE;
    }

    // Matched without the path matcher service for the reason given on
    // ::matchPatterns(); the '*' wildcard works the same way.
    $domain_match = $this->matchPatterns($request->getHost(), $domains_to_check);

    return $domain_match && $method === self::EXCLUDE_METHOD || !$domain_match && $method === self::INCLUDE_METHOD;
  }

  /**
   * Check if current request authenticated with basic auth.
   *
   * Call this if basic_auth is enabled.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object on which headers will be modified.
   *
   * @return bool
   *   TRUE if the request carries the credentials of a Drupal account that
   *   core's basic_auth provider accepts, FALSE otherwise.
   */
  private function basicAuthRequestAuthenticate(Request $request) {
    // A request is checked at most once: the bypass in handle() and the header
    // unsetting in bypass() both need the answer, and repeating the check
    // would cost a second password hash and a second flood event.
    $key = spl_object_id($request);
    if (isset($this->basicAuthResults[$key])) {
      return $this->basicAuthResults[$key];
    }
    $this->basicAuthResults[$key] = FALSE;

    // Authenticating loads a user entity, which triggers entity type
    // discovery. Load the module files first so that the hooks fired during
    // discovery are complete. This sits here rather than at the call sites
    // so that every caller - the opt-in bypass in handle() as much as the
    // header unsetting in bypass() - gets it.
    // @see https://www.drupal.org/project/shield/issues/3277210
    $this->moduleHandler->loadAll();

    // basic_auth is an optional dependency: its service does not exist unless
    // that module is installed, so the provider cannot be injected and is
    // looked up here instead. Every caller has already checked
    // moduleExists('basic_auth').
    // phpcs:disable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    /** @var \Drupal\basic_auth\Authentication\Provider\BasicAuth $basicAuthService */
    // @phpstan-ignore-next-line
    $basicAuthService = \Drupal::service('basic_auth.authentication.basic_auth');
    // phpcs:enable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $authenticate = FALSE;
    // Check for empty username and password in case of shield disabled.
    if ($basicAuthService->applies($request)) {
      // Core's procedural includes are loaded by DrupalKernel::preHandle(),
      // which runs in the http_middleware.kernel_pre_handle middleware at
      // priority 100. Shield sits at priority 250 so that it can answer before
      // the page cache, which means it is reached while those includes are
      // still missing.
      //
      // Authenticating below rehashes the account's password when the stored
      // hash is out of date, and that saves the user entity;
      // EntityStorageBase::doSave() then returns SAVED_UPDATED, which is
      // declared in core/includes/common.inc. Under PHP 8 an undefined
      // constant is an \Error rather than a warning, and the catch below only
      // catches \Exception, so it escapes the middleware as a 500. The site
      // cannot recover on its own, because the save that would store the fresh
      // hash is the one that dies.
      //
      // PHP 8.4 raised bcrypt's default cost from 10 to 12, so every hash
      // written under PHP 8.3 or earlier now needs a rehash and every
      // basic_auth login on such a site hits this.
      //
      // @see \Drupal\Core\DrupalKernel::preHandle()
      // @see https://www.drupal.org/project/shield/issues/3615866
      // phpcs:disable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      // @phpstan-ignore-next-line
      \Drupal::service('kernel')->loadLegacyIncludes();
      // phpcs:enable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal

      // We need to push the current request to the request stack because
      // basic_auth uses a flood functionality which needs the client IP.
      $this->requestStack->push($request);
      try {
        $authenticate = $basicAuthService->authenticate($request);
      }
      catch (\Exception $e) {
        // Do nothing.
      }
      if ($authenticate) {
        // Reset request stack, as we don't need it anymore.
        $this->requestStack->pop();
        $this->basicAuthResults[$key] = TRUE;
        return TRUE;
      }
      $this->requestStack->pop();
    }
    return FALSE;
  }

}
