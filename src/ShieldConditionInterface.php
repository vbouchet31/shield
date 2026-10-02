<?php

namespace Drupal\shield;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides an interface defining a Shield condition plugin.
 *
 * Condition plugins determine whether a Shield policy applies to a given
 * request (e.g. the request path, the HTTP method). Each policy AND-combines
 * the results of its configured conditions.
 */
interface ShieldConditionInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface, DependentPluginInterface {

  /**
   * Returns the title of the condition.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The condition title.
   */
  public function getTitle();

  /**
   * Returns the description of the condition.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The condition description.
   */
  public function getDescription();

  /**
   * Returns a human-readable summary of the condition's configuration.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The condition summary.
   */
  public function getSummary();

  /**
   * Evaluates the condition against the given request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return bool
   *   TRUE if the condition matches, FALSE otherwise.
   */
  public function evaluate(Request $request);

  /**
   * Determines whether this condition is negated.
   *
   * @return bool
   *   TRUE if the condition is negated, FALSE otherwise.
   */
  public function isNegated();

  /**
   * Determines whether this condition may gate the page-cache kill switch.
   *
   * ShieldPolicy::matchesCacheableConditions() ANDs together every condition
   * on a policy for which this returns TRUE, to decide whether the page
   * cache kill switch must fire for the current request. Defaults to
   * reflecting the plugin's \Drupal\shield\Attribute\ShieldCondition
   * `cacheKeyAware` property - see that attribute's docblock for the
   * operational rule condition plugins must follow.
   *
   * @return bool
   *   TRUE if the condition affects cacheability, FALSE otherwise.
   */
  public function affectsCacheability();

}
