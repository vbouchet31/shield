<?php

namespace Drupal\shield_domain\Plugin\ShieldCondition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCondition;
use Drupal\shield\ShieldConditionBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Matches the request's host name against a list of domain patterns.
 *
 * 1.x's domain feature had a separate include/exclude "method" radio button
 * (`domains_method`), structurally identical to `shield_path`'s now-removed
 * `shield_method`. Re-deriving the equivalence against
 * \Drupal\shield\ShieldConditionBase::isNegated() and
 * \Drupal\shield\Entity\ShieldPolicy::matchesConditions(): a condition
 * "matches" (i.e. the policy applies/protects) when evaluate() returns TRUE
 * and the condition is not negated, or when evaluate() returns FALSE and it
 * is. Reading ::evaluate() below as "is the host in the configured list",
 * non-negated behaves exactly like 1.x's INCLUDE_METHOD (only listed domains
 * are protected) and negated behaves exactly like EXCLUDE_METHOD (every
 * domain except the listed ones is protected). This is the same
 * simplification `shield_path`'s Path plugin made, for the same reason: the
 * base class's `negate` checkbox already expresses the distinction, so a
 * second, redundant include/exclude control is dropped here too.
 */
#[ShieldCondition(
  id: 'domain',
  title: new TranslatableMarkup('Domain'),
  // The host is part of PageCache::getCacheId(), so a domain match may
  // safely gate the kill-switch sweep - see ShieldCondition::$cacheKeyAware.
  cacheKeyAware: TRUE,
  description: new TranslatableMarkup('Match the request against a list of domain host names.'),
)]
class Domain extends ShieldConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['domains' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Domains'),
      '#description' => $this->t('Enter one domain host name per line. An asterisk (*) matches any number of characters, e.g. "*.example.com".'),
      '#default_value' => $this->configuration['domains'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['domains'] = $form_state->getValue('domains');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $domains = array_filter(array_map('trim', preg_split('/\R/u', (string) $this->configuration['domains']) ?: []));
    return $this->t('Domains: @domains', ['@domains' => implode(', ', $domains)]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    // 1.x strips every space character from the whole configured list before
    // matching (\Drupal\shield\ShieldMiddleware::checkDomainAllowed()), not
    // just leading/trailing whitespace per line as Path's paths do. Preserved
    // here so a pattern like " www.example.com " keeps matching exactly as
    // it did in 1.x.
    $patterns = str_replace(' ', '', (string) $this->configuration['domains']);
    if (trim($patterns) === '') {
      return FALSE;
    }

    return $this->matchesDomain($request->getHost(), $patterns);
  }

  /**
   * Matches a host name against newline separated literal or wildcard patterns.
   *
   * Ported from the removed \Drupal\shield\ShieldMiddleware::matchPatterns()
   * to preserve its exact semantics: matching is case-sensitive against the
   * pattern as configured, relying on Request::getHost() always returning a
   * lower-cased host (per RFC 952/2181, see
   * \Symfony\Component\HttpFoundation\Request::getHost()) - 1.x never
   * lower-cased its patterns either, so a mixed-case pattern behaves exactly
   * as it did before.
   *
   * @param string $host
   *   The request host name to match.
   * @param string $patterns
   *   Newline separated patterns. An asterisk matches any number of
   *   characters.
   *
   * @return bool
   *   TRUE if one of the patterns matches the host.
   */
  protected function matchesDomain(string $host, string $patterns): bool {
    foreach (preg_split('/\R/u', $patterns) ?: [] as $pattern) {
      $pattern = trim($pattern);
      if ($pattern === '') {
        continue;
      }
      $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
      if (preg_match($regex, $host)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
