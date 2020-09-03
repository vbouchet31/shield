<?php

namespace Drupal\shield\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\shield\ShieldConstraintManager;
use Drupal\shield\ShieldPolicyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an add form for constraints.
 *
 * @internal
 */
class ShieldConstraintAddForm extends ShieldConstraintFormBase {

  /**
   * The constraint manager.
   *
   * @var \Drupal\shield\ShieldConstraintManager
   */
  protected $constraintManager;

  /**
   * Constructs a new ShieldConstraintAddForm.
   *
   * @param \Drupal\shield\ShieldConstraintManager $constraint_manager
   *   The constraint manager.
   */
  public function __construct(ShieldConstraintManager $constraint_manager) {
    $this->constraintManager = $constraint_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.shield.shield_constraint')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ShieldPolicyInterface $policy = NULL, $constraint = NULL) {
    $form = parent::buildForm($form, $form_state, $policy, $constraint);

    $form['#title'] = $this->t('Add %label constraint', ['%label' => $this->constraint->getTitle()]);
    $form['actions']['submit']['#value'] = $this->t('Add constraint');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareConstraint($constraint) {
    $constraint = $this->constraintManager->createInstance($constraint);
    // Set the initial weight so this constraint comes last.
    $constraint->setWeight(count($this->policy->getConstraints()));
    return $constraint;
  }

}
