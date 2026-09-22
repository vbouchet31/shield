<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Provides an interface defining a Shield credential provider plugin.
 *
 * Credential provider plugins check a submitted HTTP Basic Auth username
 * and password pair against the set of credentials configured for a policy.
 */
interface ShieldCredentialProviderInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface, DependentPluginInterface {

  /**
   * Returns the title of the credential provider.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The credential provider title.
   */
  public function getTitle();

  /**
   * Returns a human-readable summary of the provider's configuration.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The credential provider summary.
   */
  public function getSummary();

  /**
   * Checks the given credentials against this provider's configuration.
   *
   * @param string $user
   *   The submitted username.
   * @param string $pass
   *   The submitted password.
   *
   * @return bool
   *   TRUE if the credentials match, FALSE otherwise.
   */
  public function checkCredentials($user, $pass);

}
