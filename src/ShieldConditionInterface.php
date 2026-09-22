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
   * Determines whether this condition affects the request's cacheability.
   *
   * Conditions that scope a policy to a subset of requests (e.g. Path) must
   * return TRUE: whenever such a condition matches, the response cannot
   * safely be stored in the page cache, because the page cache key has no
   * knowledge of the Authorization header. Conditions that only narrow an
   * already-scoped policy without being cache-relevant on their own (e.g.
   * HTTP method) should return FALSE.
   *
   * @return bool
   *   TRUE if the condition affects cacheability, FALSE otherwise.
   */
  public function affectsCacheability();

}
