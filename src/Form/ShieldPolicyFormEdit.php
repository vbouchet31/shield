<?php

namespace Drupal\shield\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\shield\ShieldPolicyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edit form for a Shield policy: conditions and credential providers tables.
 */
class ShieldPolicyFormEdit extends ShieldPolicyForm {

  /**
   * Constructs a new ShieldPolicyFormEdit object.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $conditionManager
   *   The Shield condition plugin manager.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $credentialProviderManager
   *   The Shield credential provider plugin manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   */
  public function __construct(
    protected PluginManagerInterface $conditionManager,
    protected PluginManagerInterface $credentialProviderManager,
    protected FormBuilderInterface $formBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.shield.condition'),
      $container->get('plugin.manager.shield.credential_provider'),
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    // Push General to the bottom, below Conditions and Credentials.
    $form['general']['#weight'] = 10;

    $this->buildConditionsTable($form, $form_state);
    $this->buildCredentialProvidersTable($form, $form_state);

    return $form;
  }

  /**
   * Builds the conditions fieldset: add-condition select and existing table.
   */
  protected function buildConditionsTable(array &$form, FormStateInterface $form_state) {
    $conditions = [];
    foreach ($this->conditionManager->getDefinitions() as $plugin_id => $definition) {
      $conditions[$plugin_id] = (string) $definition['title'];
    }
    asort($conditions);

    $form['conditions_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Conditions'),
      '#description' => $this->t('All conditions must match (AND) for this policy to govern a request. A policy with no conditions always applies.'),
    ];

    if (empty($conditions)) {
      $this->messenger()->addWarning($this->t('No condition plugin available. Enable a module providing one, such as shield_path or shield_http_method.'));
    }
    else {
      $form['conditions_fieldset']['add_condition'] = [
        '#type' => 'select',
        '#options' => $conditions,
        '#prefix' => '<table style="width:100%"><tr><td>',
        '#suffix' => '</td>',
      ];
      $form['conditions_fieldset']['add'] = [
        '#type' => 'submit',
        '#name' => 'add_condition_submit',
        '#value' => $this->t('Add condition'),
        '#ajax' => [
          'callback' => [$this, 'addCondition'],
          'event' => 'click',
        ],
        '#prefix' => '<td>',
        '#suffix' => '</td></tr></table>',
      ];
    }

    $form['conditions_fieldset']['items'] = [
      '#type' => 'markup',
      '#prefix' => '<div id="shield-policy-conditions">',
      '#suffix' => '</div>',
      '#theme' => 'table',
      '#header' => [
        'plugin' => $this->t('Plugin'),
        'summary' => $this->t('Summary'),
        'negated' => $this->t('Negated'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $this->renderConditionRows($this->entity),
      '#empty' => $this->t('No conditions configured. This policy applies to every request.'),
    ];
  }

  /**
   * Renders the existing condition rows for the policy.
   *
   * @param \Drupal\shield\ShieldPolicyInterface $policy
   *   The policy.
   *
   * @return array
   *   Table rows.
   */
  protected function renderConditionRows(ShieldPolicyInterface $policy) {
    $rows = [];
    foreach ($policy->getConditions() as $key => $condition) {
      /** @var \Drupal\shield\ShieldConditionInterface $instance */
      $instance = $this->conditionManager->createInstance($condition['id'], $condition);

      $edit_url = Url::fromRoute('entity.shield_policy.condition.edit', [
        'shield_policy_id' => $policy->id(),
        'machine_name' => $condition['id'],
        'condition_id' => $key,
      ]);
      $delete_url = Url::fromRoute('entity.shield_policy.condition.delete', [
        'shield_policy_id' => $policy->id(),
        'machine_name' => $condition['id'],
        'id' => $key,
      ]);
      $dialog_attributes = [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => 700]),
      ];

      $operations = [
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => $edit_url,
          'attributes' => $dialog_attributes,
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => $delete_url,
          'weight' => 100,
          'attributes' => $dialog_attributes,
        ],
      ];

      $rows[] = [
        'plugin' => Markup::create('<span title="' . $this->t('Plugin ID:') . ' ' . $instance->getPluginId() . '">' . $instance->getTitle() . '</span>'),
        'summary' => $instance->getSummary(),
        'negated' => $instance->isNegated() ? $this->t('Yes') : $this->t('No'),
        'operations' => ['data' => ['#type' => 'operations', '#links' => $operations]],
      ];
    }
    return $rows;
  }

  /**
   * AJAX callback: opens the add-condition modal.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function addCondition(array &$form, FormStateInterface $form_state) {
    $condition_id = $form_state->getValue('add_condition');

    $content = $this->formBuilder->getForm(ShieldPolicyConditionEditForm::class, $condition_id, $this->entity->id());
    $content['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $url = Url::fromRoute('entity.shield_policy.condition.add', [
      'machine_name' => $this->entity->id(),
      'condition_id' => $condition_id,
    ], ['query' => [FormBuilderInterface::AJAX_FORM_REQUEST => TRUE]]);
    $content['submit']['#attached']['drupalSettings']['ajax'][$content['submit']['#id']]['url'] = $url->toString();

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand($this->t('Add condition'), $content, ['width' => 700]));
    return $response;
  }

  /**
   * Builds the credential providers fieldset: add-select and existing table.
   */
  protected function buildCredentialProvidersTable(array &$form, FormStateInterface $form_state) {
    $providers = [];
    foreach ($this->credentialProviderManager->getDefinitions() as $plugin_id => $definition) {
      $providers[$plugin_id] = (string) $definition['title'];
    }
    asort($providers);

    $form['credential_providers_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Credentials'),
      '#description' => $this->t('Any one of these credential providers accepting the submitted credentials (OR) is enough to authenticate. A policy with none configured is never applied.'),
    ];

    if (empty($providers)) {
      $this->messenger()->addWarning($this->t('No credential provider plugin available. Enable a module providing one, such as shield_password or shield_key.'));
    }
    else {
      $form['credential_providers_fieldset']['add_credential_provider'] = [
        '#type' => 'select',
        '#options' => $providers,
        '#prefix' => '<table style="width:100%"><tr><td>',
        '#suffix' => '</td>',
      ];
      $form['credential_providers_fieldset']['add'] = [
        '#type' => 'submit',
        '#name' => 'add_credential_provider_submit',
        '#value' => $this->t('Add credential provider'),
        '#ajax' => [
          'callback' => [$this, 'addCredentialProvider'],
          'event' => 'click',
        ],
        '#prefix' => '<td>',
        '#suffix' => '</td></tr></table>',
      ];
    }

    $form['credential_providers_fieldset']['items'] = [
      '#type' => 'markup',
      '#prefix' => '<div id="shield-policy-credential-providers">',
      '#suffix' => '</div>',
      '#theme' => 'table',
      '#header' => [
        'plugin' => $this->t('Plugin'),
        'summary' => $this->t('Summary'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $this->renderCredentialProviderRows($this->entity),
      '#empty' => $this->t('No credential providers configured. This policy is never applied.'),
    ];
  }

  /**
   * Renders the existing credential provider rows for the policy.
   *
   * @param \Drupal\shield\ShieldPolicyInterface $policy
   *   The policy.
   *
   * @return array
   *   Table rows.
   */
  protected function renderCredentialProviderRows(ShieldPolicyInterface $policy) {
    $rows = [];
    foreach ($policy->getCredentialProviders() as $key => $provider) {
      /** @var \Drupal\shield\ShieldCredentialProviderInterface $instance */
      $instance = $this->credentialProviderManager->createInstance($provider['id'], $provider);

      $edit_url = Url::fromRoute('entity.shield_policy.credential_provider.edit', [
        'shield_policy_id' => $policy->id(),
        'machine_name' => $provider['id'],
        'credential_provider_id' => $key,
      ]);
      $delete_url = Url::fromRoute('entity.shield_policy.credential_provider.delete', [
        'shield_policy_id' => $policy->id(),
        'machine_name' => $provider['id'],
        'id' => $key,
      ]);
      $dialog_attributes = [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => 700]),
      ];

      $operations = [
        'edit' => [
          'title' => $this->t('Edit'),
          'url' => $edit_url,
          'attributes' => $dialog_attributes,
        ],
        'delete' => [
          'title' => $this->t('Delete'),
          'url' => $delete_url,
          'weight' => 100,
          'attributes' => $dialog_attributes,
        ],
      ];

      $rows[] = [
        'plugin' => Markup::create('<span title="' . $this->t('Plugin ID:') . ' ' . $instance->getPluginId() . '">' . $instance->getTitle() . '</span>'),
        'summary' => $instance->getSummary(),
        'operations' => ['data' => ['#type' => 'operations', '#links' => $operations]],
      ];
    }
    return $rows;
  }

  /**
   * AJAX callback: opens the add-credential-provider modal.
   *
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function addCredentialProvider(array &$form, FormStateInterface $form_state) {
    $credential_provider_id = $form_state->getValue('add_credential_provider');

    $content = $this->formBuilder->getForm(ShieldPolicyCredentialProviderEditForm::class, $credential_provider_id, $this->entity->id());
    $content['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $url = Url::fromRoute('entity.shield_policy.credential_provider.add', [
      'machine_name' => $this->entity->id(),
      'credential_provider_id' => $credential_provider_id,
    ], ['query' => [FormBuilderInterface::AJAX_FORM_REQUEST => TRUE]]);
    $content['submit']['#attached']['drupalSettings']['ajax'][$content['submit']['#id']]['url'] = $url->toString();

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand($this->t('Add credential provider'), $content, ['width' => 700]));
    return $response;
  }

}
