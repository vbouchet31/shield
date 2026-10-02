<?php

namespace Drupal\shield\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the Shield module's site-wide settings.
 *
 * Access scoping and credentials moved to ShieldPolicy config entities; only
 * the handful of settings that are legitimately site-wide (not per-policy)
 * remain here. See the implementation plan §5 for why each one stayed.
 */
class ShieldSettingsForm extends ConfigFormBase {

  /**
   * Constructs a new ShieldSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typedConfigManager, protected ModuleHandlerInterface $moduleHandler) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['shield.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $shield_config = $this->config('shield.settings');

    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Shield settings'),
      '#description' => $this->t('Access scoping and credentials are configured per <a href=":url">Shield policy</a>. The settings below apply to every policy.', [
        ':url' => Url::fromRoute('entity.shield_policy.collection')->toString(),
      ]),
    ];

    $form['shield_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Shield'),
      '#description' => $this->t('Enable or disable Shield entirely. When disabled, no policy is evaluated regardless of its own status.'),
      '#default_value' => $shield_config->get('shield_enable'),
    ];

    $form['allow_cli'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow command line access'),
      '#description' => $this->t('When the site is accessed from command line (e.g. from Drush, cron), Shield policies are not evaluated.'),
      '#default_value' => $shield_config->get('allow_cli'),
    ];

    $form['debug_header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug header'),
      '#description' => $this->t('Add X-Shield-Status and X-Shield-Policy-Name headers in response to indicate the outcome of Shield evaluation and, when a policy governed the request, its machine name, to ease debugging.'),
      '#default_value' => $shield_config->get('debug_header'),
    ];

    $form['log_authentication'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log successful authentications'),
      '#description' => $this->t('Record a log entry with the user name, client IP address and requested path every time a visitor passes a Shield policy credentials prompt.'),
      '#default_value' => $shield_config->get('log_authentication'),
    ];

    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $form['basic_auth_bypass'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow HTTP basic authentication'),
        '#description' => $this->t('Let a request pass every Shield policy when it carries the user name and password of an active Drupal account, instead of the policy credentials. Intended for REST and other web service clients, which can only send one set of credentials. The credentials are verified by the HTTP Basic Authentication module, so the usual flood control and blocked account rules apply. <strong>Every account on the site can open the shield when this is enabled, so it is only as strong as the weakest user password.</strong>'),
        '#default_value' => $shield_config->get('basic_auth_bypass'),
      ];

      $form['unset_basic_auth_headers'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Unset basic_auth headers'),
        '#description' => $this->t('Remove the PHP_AUTH_USER and PHP_AUTH_PW headers from requests that pass Shield, to avoid conflicts with the HTTP Basic Authentication module.'),
        '#default_value' => $shield_config->get('unset_basic_auth_headers'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $shield_config = $this->config('shield.settings');

    $shield_config
      ->set('shield_enable', $form_state->getValue('shield_enable'))
      ->set('allow_cli', $form_state->getValue('allow_cli'))
      ->set('debug_header', $form_state->getValue('debug_header'))
      ->set('log_authentication', $form_state->getValue('log_authentication'));

    if ($this->moduleHandler->moduleExists('basic_auth')) {
      $shield_config
        ->set('basic_auth_bypass', $form_state->getValue('basic_auth_bypass'))
        ->set('unset_basic_auth_headers', $form_state->getValue('unset_basic_auth_headers'));
    }

    $shield_config->save();

    parent::submitForm($form, $form_state);
  }

}
