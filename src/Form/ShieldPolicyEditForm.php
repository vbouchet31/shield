<?php

namespace Drupal\shield\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\shield\ConfigurableShieldConstraintInterface;
use Drupal\shield\ShieldAuthenticatorManager;
use Drupal\shield\ShieldConstraintManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for shield policy edition forms.
 *
 * @internal
 */
class ShieldPolicyEditForm extends ShieldPolicyFormBase {

  /**
   * The shield constraint manager service.
   *
   * @var \Drupal\shield\ShieldConstraintManager
   */
  protected $shieldConstraintManager;

  /**
   * The shield authenticator manager service.
   *
   * @var \Drupal\shield\ShieldAuthenticatorManager
   */
  protected $shieldAuthenticatorManager;

  /**
   * Constructs a ShieldPolicyEditorForm object.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The storage.
   * @param \Drupal\shield\ShieldConstraintManager $shield_constraint_manager
   *   The shield constraint manager service.
   */
  public function __construct(EntityStorageInterface $storage, ShieldConstraintManager $shield_constraint_manager, ShieldAuthenticatorManager $shield_authenticator_manager) {
    parent::__construct($storage);
    $this->shieldConstraintManager = $shield_constraint_manager;
    $this->shieldAuthenticatorManager = $shield_authenticator_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('shield_policy'),
      $container->get('plugin.manager.shield.shield_constraint'),
      $container->get('plugin.manager.shield.shield_authenticator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    $form['#title'] = $this->t('Edit policy %name', ['%name' => $this->entity->label()]);
    $form['#tree'] = TRUE;

    // Build the list of existing constraints for this policy.
    $form['constraints'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Constraints'),
      '#weight' => 5,
    ];

    $form['constraints']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Constraint'),
        $this->t('Summary'),
        $this->t('Weight'),
        $this->t('Operations'),
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'shield-policy-constraint-order-weight',
        ],
      ],
      '#attributes' => [
        'id' => 'shield-policy-constraints',
      ],
      '#empty' => t('There are currently no constraints in this policy. Add one by selecting an option below.'),
    ];

    foreach ($this->entity->getConstraints() as $constraint) {
      $key = $constraint->getUuid();
      $form['constraints']['table'][$key]['#attributes']['class'][] = 'draggable';
      $form['constraints']['table'][$key]['#weight'] = isset($user_input['constraints'][$key]) ? $user_input['constraints'][$key]['weight'] : NULL;
      $form['constraints']['table'][$key]['constraint'] = [
        '#markup' => $constraint->getTitle(),
      ];

      $summary = $constraint->getSummary();
      if (!empty($summary)) {
        $form['constraints']['table'][$key]['summary'] = [
          '#markup' => $summary,
          '#prefix' => ' ',
        ];
      }

      $form['constraints']['table'][$key]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', ['@title' => $constraint->getTitle()]),
        '#title_display' => 'invisible',
        '#default_value' => $constraint->getWeight(),
        '#attributes' => [
          'class' => ['shield-policy-constraint-order-weight'],
        ],
      ];

      $links = [];
      $is_configurable = $constraint instanceof ConfigurableShieldConstraintInterface;
      if ($is_configurable) {
        $links['edit'] = [
          'title' => $this->t('Edit'),
          'url' => Url::fromRoute('shield.constraint_edit_form', [
            'shield_policy' => $this->entity->id(),
            'constraint' => $key,
          ]),
        ];
      }
      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('shield.constraint_delete_form', [
          'shield_policy' => $this->entity->id(),
          'constraint' => $key,
        ]),
      ];
      $form['constraints']['table'][$key]['operations'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];
    }

    // Build the new constraint addition form and add it to the constraint list.
    $new_constraint_options = [];
    $constraints = $this->shieldConstraintManager->getDefinitions();
    uasort($constraints, function ($a, $b) {
      return Unicode::strcasecmp($a['title'], $b['title']);
    });
    foreach ($constraints as $constraint => $definition) {
      $new_constraint_options[$constraint] = $definition['title'];
    }
    $form['constraints']['table']['new_constraint'] = [
      '#tree' => FALSE,
      '#weight' => isset($user_input['weight']) ? $user_input['weight'] : NULL,
      '#attributes' => ['class' => ['draggable']],
    ];
    $form['constraints']['table']['new_constraint']['constraint'] = [
      'data' => [
        'new_constraint' => [
          '#type' => 'select',
          '#title' => $this->t('Constraint'),
          '#title_display' => 'invisible',
          '#options' => $new_constraint_options,
          '#empty_option' => $this->t('Select a new constraint'),
        ],
        [
          'add' => [
            '#type' => 'submit',
            '#value' => $this->t('Add'),
            '#validate' => ['::constraintValidate'],
            '#submit' => ['::submitForm', '::constraintSave'],
          ],
        ],
      ],
      '#prefix' => '<div class="shield-constraint-new">',
      '#suffix' => '</div>',
    ];
    $form['constraints']['table']['new_constraint']['summary'] = [];

    $form['constraints']['table']['new_constraint']['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for new constraint'),
      '#title_display' => 'invisible',
      '#default_value' => count($this->entity->getConstraints()) + 1,
      '#attributes' => ['class' => ['shield-policy-constraint-order-weight']],
    ];
    $form['constraints']['table']['new_constraint']['operations'] = [
      'data' => [],
    ];

    // Build authenticator form for this policy.
    $form['authenticator'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Authentication method'),
      '#weight' => 6,
    ];

    $authenticator = $this->entity->getAuthenticator();
    if (!empty($authenticator)) {
      $form['authenticator']['details'] = [
        '#markup' => 'Some info about the configured authenticator',
      ];
    }
    else {
      // Build the authenticator addition form.
      $authenticator_options = [];
      $authenticators = $this->shieldAuthenticatorManager->getDefinitions();
      uasort($authenticators, function ($a, $b) {
        return Unicode::strcasecmp($a['title'], $b['title']);
      });
      foreach ($authenticators as $authenticator => $definition) {
        $authenticator_options[$authenticator] = $definition['title'];
      }
      $form['authenticator']['new_authenticator'] = [
        'data' => [
          'new_authenticator' => [
            '#type' => 'select',
            '#title' => $this->t('Method'),
            '#title_display' => 'invisible',
            '#options' => $authenticator_options,
            '#empty_option' => $this->t('Select a method'),
          ],
          [
            'add' => [
              '#type' => 'submit',
              '#value' => $this->t('Add'),
              '#validate' => ['::authenticatorValidate'],
              '#submit' => ['::submitForm', '::authenticatorSave'],
            ],
          ],
        ],
        '#prefix' => '<div class="shield-authenticator-new">',
        '#suffix' => '</div>',
        '#tree' => FALSE,
      ];
    }



    return parent::form($form, $form_state);
  }

  /**
   * Validate handler for constraint.
   */
  public function constraintValidate($form, FormStateInterface $form_state) {
    if (!$form_state->getValue('new_constraint')) {
      $form_state->setErrorByName('new_constraint', $this->t('Select a constraint to add.'));
    }
  }

  /**
   * Validate handler for authenticator.
   */
  public function authenticatorValidate($form, FormStateInterface $form_state) {
    if (!$form_state->getValue('new_authenticator')) {
      $form_state->setErrorByName('new_authenticator', $this->t('Select an authentication method to add.'));
    }
  }

  /**
   * Submit handler for constraint.
   */
  public function constraintSave($form, FormStateInterface $form_state) {
    $this->save($form, $form_state);

    // Check if this field has any configuration options.
    $constraint = $this->shieldConstraintManager->getDefinition($form_state->getValue('new_constraint'));

    // Load the configuration form for this option.
    if (is_subclass_of($constraint['class'], '\Drupal\shield\ConfigurableShieldConstraintInterface')) {
      $form_state->setRedirect(
        'shield.constraint_add_form',
        [
          'shield_policy' => $this->entity->id(),
          'shield_constraint' => $form_state->getValue('new_constraint'),
        ],
        ['query' => ['weight' => $form_state->getValue('weight')]]
      );
    }
    // If there's no form, immediately add the constraint.
    else {
      $constraint = [
        'id' => $constraint['id'],
        'data' => [],
        'weight' => $form_state->getValue('weight'),
      ];


      $constraint_id = $this->entity->addConstraint($constraint);
      $this->entity->save();
      if (!empty($constraint_id)) {
        $this->messenger()->addStatus($this->t('The constraint was successfully applied.'));
      }
    }
  }

  /**
   * Submit handler for authenticator.
   */
  public function authenticatorSave($form, FormStateInterface $form_state) {
    $this->save($form, $form_state);

    // Load the configuration form for this option.
    $form_state->setRedirect(
      'shield.authenticator_add_form',
      [
        'shield_policy' => $this->entity->id(),
        'shield_authenticator' => $form_state->getValue('new_authenticator'),
      ],
      ['query' => ['weight' => $form_state->getValue('weight')]]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Update constraints weights.
    if (!$form_state->isValueEmpty('constraints')) {
      $this->updateConstraintWeights($form_state->getValue('constraints'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Changes to the policy have been saved.'));
  }

  /**
   * Updates constraint weights.
   *
   * @param array $contraints
   *   Associative array with constraints having constraint uuid as keys
   *   and array with constraint data as values.
   */
  protected function updateConstraintWeights(array $contraints) {
    foreach ($contraints as $uuid => $constraint_data) {
      if ($this->entity->getConstraints()->has($uuid)) {
        $this->entity->getConstraint($uuid)->setWeight($constraint_data['weight']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  /*public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Update policy');

    return $actions;
  }*/

}
