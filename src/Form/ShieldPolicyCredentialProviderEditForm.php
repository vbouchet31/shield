<?php

namespace Drupal\shield\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a credential provider in a Shield policy, via AJAX modal.
 */
class ShieldPolicyCredentialProviderEditForm extends FormBase {

  /**
   * The Shield policy being edited.
   *
   * @var \Drupal\shield\ShieldPolicyInterface
   */
  protected $shieldPolicy;

  /**
   * Constructs a new ShieldPolicyCredentialProviderEditForm object.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The Shield credential provider plugin manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $currentRouteMatch
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected PluginManagerInterface $manager,
    protected RouteMatchInterface $currentRouteMatch,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.shield.credential_provider'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_policy_credential_provider_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $credential_provider_id = NULL, $machine_name = NULL, $shield_policy_id = NULL) {
    if (!isset($shield_policy_id)) {
      // Adding: {machine_name} in the route is the policy ID.
      $shield_policy_id = $machine_name;
    }
    else {
      // Editing: {machine_name} in the route is the provider's plugin ID.
      $shield_policy_id = $this->currentRouteMatch->getParameter('shield_policy_id');
    }
    $this->shieldPolicy = $this->entityTypeManager->getStorage('shield_policy')->load($shield_policy_id);

    if (is_numeric($credential_provider_id)) {
      $key = $credential_provider_id;
      $provider_config = $this->shieldPolicy->getCredentialProviderConfig($key);
      $instance = $this->manager->createInstance($provider_config['id'], $provider_config);
    }
    else {
      $instance = $this->manager->createInstance($credential_provider_id, []);
    }

    if (isset($key)) {
      $form['key'] = ['#type' => 'value', '#value' => $key];
    }
    $form['instance'] = ['#type' => 'value', '#value' => $instance];

    $form = $instance->buildConfigurationForm($form, $form_state);

    $form['submit'] = [
      '#type' => 'submit',
      '#weight' => 9999,
      '#value' => $this->t('Save'),
      '#ajax' => [
        'callback' => [$this, 'ajaxSave'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $instance = $form_state->getValue('instance');
    $instance->validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $providers = $this->shieldPolicy->getCredentialProviders();

    /** @var \Drupal\shield\ShieldCredentialProviderInterface $instance */
    $instance = $form_state->getValue('instance');
    $instance->submitConfigurationForm($form, $form_state);

    if ($form_state->hasValue('key')) {
      $providers[$form_state->getValue('key')] = $instance->getConfiguration();
    }
    else {
      $providers[] = $instance->getConfiguration();
    }
    $this->shieldPolicy->set('credential_providers', $providers);
    $this->shieldPolicy->save();
  }

  /**
   * AJAX callback: closes the modal and reloads the policy edit form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function ajaxSave(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $url = Url::fromRoute('entity.shield_policy.edit_form', ['shield_policy' => $this->shieldPolicy->id()]);
    $response->addCommand(new RedirectCommand($url->toString()));
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
