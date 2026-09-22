<?php

namespace Drupal\shield;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides an interface defining a Shield policy entity.
 */
interface ShieldPolicyInterface extends ConfigEntityInterface {

  /**
   * Returns the weight of this policy.
   *
   * @return int
   *   The weight.
   */
  public function getWeight();

  /**
   * Returns all condition configurations from the policy.
   *
   * @return array
   *   The policy conditions, keyed by delta.
   */
  public function getConditions();

  /**
   * Returns a specific condition configuration from the policy.
   *
   * @param int $key
   *   The condition delta.
   *
   * @return array|null
   *   The condition configuration, or NULL if not found.
   */
  public function getCondition($key);

  /**
   * Determines whether all of the policy's conditions match the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE if the policy applies to the request, FALSE otherwise.
   */
  public function matchesConditions(Request $request);

  /**
   * Determines whether the policy's cacheability-affecting conditions match.
   *
   * Evaluates only the subset of conditions for which
   * ShieldConditionInterface::affectsCacheability() is TRUE. A policy with no
   * such conditions returns TRUE (worst-case/vacuous assumption).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE if the request must be treated as not cacheable, FALSE otherwise.
   */
  public function matchesCacheableConditions(Request $request);

  /**
   * Determines whether the policy has any credential provider configured.
   *
   * A policy is not applied to any request - it neither governs access nor
   * counts towards page cache kill-switch decisions - until this is TRUE.
   *
   * @return bool
   *   TRUE if at least one credential provider plugin is set on the policy.
   */
  public function hasCredentialProviders();

  /**
   * Returns all credential provider configurations from the policy.
   *
   * @return array
   *   The policy credential providers, keyed by delta.
   */
  public function getCredentialProviders();

  /**
   * Returns a specific credential provider configuration from the policy.
   *
   * @param int $key
   *   The credential provider delta.
   *
   * @return array|null
   *   The credential provider configuration, or NULL if not found.
   */
  public function getCredentialProviderConfig($key);

  /**
   * Determines whether any of the policy's credential providers match.
   *
   * The configured credential providers are combined with OR: the request is
   * authenticated as soon as one of them accepts the submitted credentials.
   *
   * @param string $user
   *   The submitted user name.
   * @param string $pass
   *   The submitted password.
   *
   * @return bool
   *   TRUE if at least one credential provider accepts the credentials.
   */
  public function checkCredentials($user, $pass);

  /**
   * Builds the WWW-Authenticate realm string for this policy.
   *
   * @return string
   *   The realm string.
   */
  public function buildRealm();

}
