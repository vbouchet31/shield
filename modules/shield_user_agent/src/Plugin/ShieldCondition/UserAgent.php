<?php

namespace Drupal\shield_user_agent\Plugin\ShieldCondition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCondition;
use Drupal\shield\ShieldConditionBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Matches the request's User-Agent header against a list of substrings.
 */
#[ShieldCondition(
  id: 'user_agent',
  title: new TranslatableMarkup('User agent'),
  // The User-Agent header is not part of PageCache::getCacheId(), so this
  // condition must never gate the kill-switch sweep - see
  // ShieldCondition::$cacheKeyAware.
  cacheKeyAware: FALSE,
  description: new TranslatableMarkup('Match the request against a list of user agent strings.'),
)]
class UserAgent extends ShieldConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['user_agents' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['user_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User agents'),
      '#description' => $this->t("Enter one user agent string per line. The condition matches when the request's User-Agent header contains one of these strings, matched case-insensitively.<br><em>Warning: the user agent is sent by the client and can be set to any value. @strong_style_tag Anyone can bypass this condition by claiming one of these user agents, so only use it for convenience, never as a security measure.</strong></em>", [
        '@strong_style_tag' => Markup::create("<strong style='color:red'>"),
      ]),
      '#default_value' => $this->configuration['user_agents'],
      '#placeholder' => $this->t("Example:\nfacebookexternalhit\nTwitterbot"),
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['user_agents'] = $form_state->getValue('user_agents');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $user_agents = array_filter(array_map('trim', preg_split('/\R/u', (string) $this->configuration['user_agents']) ?: []));
    return $this->t('User agents: @user_agents', ['@user_agents' => implode(', ', $user_agents)]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    $user_agent = $request->headers->get('User-Agent');
    $patterns = (string) $this->configuration['user_agents'];

    if ($user_agent === NULL || $user_agent === '' || trim($patterns) === '') {
      return FALSE;
    }

    $substrings = array_filter(array_map('trim', explode("\n", $patterns)), 'strlen');
    foreach ($substrings as $substring) {
      if (stripos($user_agent, $substring) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
