<?php

namespace Drupal\shield\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Psr\Log\LoggerInterface;
use Drupal\shield\ShieldPolicyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Evaluates Shield policies against the incoming request.
 *
 * Running as a kernel.request subscriber means this always executes after
 * DrupalKernel::preHandle()/moduleHandler->loadAll(), so plugin discovery,
 * entity loading and hooks are all safe here.
 */
class ShieldPolicySubscriber implements EventSubscriberInterface {

  /**
   * Basic auth results of the requests seen so far, keyed by object id.
   *
   * @var bool[]
   */
  protected $basicAuthResults = [];

  /**
   * Constructs a new ShieldPolicySubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The shield logger channel.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $killSwitch
   *   The page cache kill switch.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected ModuleHandlerInterface $moduleHandler,
    protected KillSwitch $killSwitch,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority 1024: above every other kernel.request listener registered by
    // core (the highest is 1000), and in particular above RouterListener
    // (32). Running before routing means a request to a non-existent path
    // never gets the chance to throw NotFoundHttpException before Shield can
    // challenge it.
    return [KernelEvents::REQUEST => ['onRequest', 1024]];
  }

  /**
   * Evaluates policies and challenges or lets through the request.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $config = $this->configFactory->get('shield.settings');

    if (!$event->isMainRequest()) {
      return;
    }

    // The master switch: when off, no policy is evaluated at all, regardless
    // of its own status. Checked first so that nothing below it - the kill
    // switch included - ever runs while Shield is globally disabled.
    if (!$config->get('shield_enable')) {
      $request->attributes->set('_shield_status', 'skipped (shield disabled)');
      $this->passThrough($request);
      return;
    }

    if (PHP_SAPI === 'cli' && $config->get('allow_cli')) {
      $request->attributes->set('_shield_status', 'skipped (cli)');
      $this->passThrough($request);
      return;
    }

    /** @var \Drupal\shield\ShieldPolicyInterface[] $policies */
    $policies = $this->entityTypeManager->getStorage('shield_policy')->loadByProperties(['status' => TRUE]);
    if (!$policies) {
      $request->attributes->set('_shield_status', 'disabled');
      $this->passThrough($request);
      return;
    }

    // A policy is not applied to any request - it neither governs access nor
    // counts towards the cacheability sweep below - until it has at least one
    // credential provider configured. Without this, a freshly created,
    // enabled policy whose conditions match would lock out its matched paths
    // with no way for anyone to authenticate through.
    $policies = array_filter($policies, function (ShieldPolicyInterface $policy) {
      return $policy->hasCredentialProviders();
    });
    if (!$policies) {
      $request->attributes->set('_shield_status', 'skipped (no configured policy)');
      $this->passThrough($request);
      return;
    }

    uasort($policies, function (ShieldPolicyInterface $a, ShieldPolicyInterface $b) {
      return $a->getWeight() <=> $b->getWeight();
    });

    // Pass 1: cacheability sweep. Every enabled policy is considered here,
    // regardless of which one ends up governing the request below - an
    // IP/method-only rule downstream of the governing policy could still
    // make this response unsafe to cache.
    foreach ($policies as $policy) {
      if ($policy->matchesCacheableConditions($request)) {
        $this->killSwitch->trigger();
        break;
      }
    }

    // Pass 2: weight-ordered, first-match governing policy. Deliberately a
    // separate loop that breaks on the first match.
    $governing = NULL;
    foreach ($policies as $policy) {
      if ($policy->matchesConditions($request)) {
        $governing = $policy;
        break;
      }
    }

    if (!$governing) {
      $request->attributes->set('_shield_status', 'skipped (no matching policy)');
      $this->passThrough($request);
      return;
    }
    $request->attributes->set('_shield_policy', $governing->id());

    $input = $this->getRequestCredentials($request);
    if ($input !== NULL && $governing->checkCredentials($input[0], $input[1])) {
      $request->attributes->set('_shield_status', 'authenticated');
      if ($config->get('log_authentication')) {
        $this->logger->notice('Authenticated %user from %ip on %path.', [
          '%user' => $input[0],
          '%ip' => $request->getClientIp(),
          '%path' => $request->getPathInfo(),
        ]);
      }
      $this->passThrough($request);
      return;
    }

    // Let a request through that carries the credentials of a Drupal
    // account, if the site opted in. Core's basic_auth provider performs the
    // check, so flood control, blocked accounts and password hashing are all
    // handled there rather than reimplemented here. Checked only after the
    // governing policy's own credentials have already failed to match, so a
    // request presenting valid Shield credentials never pays for a
    // basic_auth attempt.
    if ($config->get('basic_auth_bypass')
      && $this->moduleHandler->moduleExists('basic_auth')
      && $this->basicAuthRequestAuthenticate($request)) {
      $request->attributes->set('_shield_status', 'skipped (basic auth)');
      $this->passThrough($request);
      return;
    }

    $response = new Response();
    $response->headers->set('WWW-Authenticate', 'Basic realm="' . $governing->buildRealm() . '"');
    $request->attributes->set('_shield_status', 'pending');
    $response->setStatusCode(401);
    $event->setResponse($response);
  }

  /**
   * Lets the request continue through the normal kernel.
   *
   * Ported from ShieldMiddleware::bypass(): stops core's own basic_auth
   * authentication provider from also trying to authenticate this request
   * (and colliding with Shield's own decision above) whenever Shield is
   * about to let the request through, for any reason.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  protected function passThrough(Request $request) {
    $config = $this->configFactory->get('shield.settings');
    if ($this->moduleHandler->moduleExists('basic_auth')
      && $config->get('unset_basic_auth_headers')
      && !$this->basicAuthRequestAuthenticate($request)) {
      $request->headers->remove('PHP_AUTH_USER');
      $request->headers->remove('PHP_AUTH_PW');
    }
  }

  /**
   * Extracts the basic authentication credentials sent with the request.
   *
   * PHP only populates PHP_AUTH_USER and PHP_AUTH_PW when it runs as an
   * Apache module. On CGI, FastCGI and FPM setups - which most shared
   * hosting uses - the Authorization header has to be read back from
   * wherever the web server left it, which is either HTTP_AUTHORIZATION, or
   * the REDIRECT_ prefixed copy Apache creates for env variables set by a
   * rewrite rule. Some hosts only allow the header to be forwarded in
   * REMOTE_USER, so that variable is inspected as well.
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
   * Checks if the current request authenticated with basic_auth.
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
    // A request is checked at most once: onRequest()'s opt-in bypass and
    // passThrough()'s header unsetting both need the answer, and repeating
    // the check would cost a second password hash and a second flood event.
    $key = spl_object_id($request);
    if (isset($this->basicAuthResults[$key])) {
      return $this->basicAuthResults[$key];
    }
    $this->basicAuthResults[$key] = FALSE;

    // phpcs:disable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    /** @var \Drupal\basic_auth\Authentication\Provider\BasicAuth $basicAuthService */
    // @phpstan-ignore-next-line
    $basicAuthService = \Drupal::service('basic_auth.authentication.basic_auth');
    // phpcs:enable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    $authenticate = FALSE;
    if ($basicAuthService->applies($request)) {
      // Authenticating below rehashes the account's password when the stored
      // hash is out of date, and that saves the user entity;
      // EntityStorageBase::doSave() then returns SAVED_UPDATED, which is
      // declared in core/includes/common.inc. Those procedural includes are
      // not guaranteed to be loaded outside of a normal page request flow.
      //
      // PHP 8.4 raised bcrypt's default cost from 10 to 12, so every hash
      // written under PHP 8.3 or earlier now needs a rehash and every
      // basic_auth login on such a site hits this.
      //
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
        $this->requestStack->pop();
        $this->basicAuthResults[$key] = TRUE;
        return TRUE;
      }
      $this->requestStack->pop();
    }
    return FALSE;
  }

}
