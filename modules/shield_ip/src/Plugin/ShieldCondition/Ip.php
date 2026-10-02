<?php

namespace Drupal\shield_ip\Plugin\ShieldCondition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCondition;
use Drupal\shield\ShieldConditionBase;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Matches the request against a list of IP addresses or CIDR ranges.
 */
#[ShieldCondition(
  id: 'ip',
  title: new TranslatableMarkup('IP'),
  // The client IP is not part of PageCache::getCacheId(), so this condition
  // must never gate the kill-switch sweep - see
  // ShieldCondition::$cacheKeyAware.
  cacheKeyAware: FALSE,
  description: new TranslatableMarkup('Match the request against a list of IP addresses or CIDR ranges.'),
)]
class Ip extends ShieldConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['ips' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('IPs'),
      '#description' => $this->t("Enter one IP address or CIDR range per line. IPv4 and IPv6 are both supported, for example '192.168.0.1/24' or '2001:db8::8a2e:370:7334'.<br><strong>Warning:</strong> IP-based conditions may not be compatible with reverse proxy caching. If reverse proxy caching is in use, ensure that it correctly respects Cache-Control headers set by the origin server."),
      '#default_value' => $this->configuration['ips'],
      '#placeholder' => $this->t("Example:\n192.168.0.1/24\n127.0.0.1\n2001:0db8:0000:0000:0000:8a2e:0370:7334\n2001:db8::8a2e:370:7334"),
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['ips'] = $form_state->getValue('ips');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $ips = $this->getIps();
    return $this->t('IPs: @ips', ['@ips' => implode(', ', $ips)]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    $ips = $this->getIps();
    if (!$ips) {
      return FALSE;
    }

    $client_ip = $request->getClientIp();
    if ($client_ip === NULL) {
      return FALSE;
    }

    return IpUtils::checkIp($client_ip, $ips);
  }

  /**
   * Returns the configured list of IPs/CIDR ranges as a plain array.
   *
   * @return string[]
   *   The configured IPs and CIDR ranges, trimmed and with blank lines
   *   removed.
   */
  protected function getIps(): array {
    return array_filter(array_map('trim', preg_split('/\R/u', (string) $this->configuration['ips']) ?: []), 'strlen');
  }

}
