<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface to define the expected operations of a shield authentication method.
 */
interface ShieldAuthenticatorInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Returns a translated string for the authenticator title.
   *
   * @return string
   *   Title of the authenticator.
   */
  public function getTitle();

  /**
   * Returns a translated description for the authenticator description.
   *
   * @return string
   *   Description of the authenticator.
   */
  public function getDescription();

  /**
   * Returns a human-readable summary of the authenticator.
   *
   * @return string
   *   Summary of the authenticator behavior.
   */
  public function getSummary();

}
