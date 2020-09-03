<?php

namespace Drupal\shield\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\shield\ShieldPolicyInterface;

/**
 * Form for deleting a constraint.
 *
 * @internal
 */
class ShieldConstraintDeleteForm extends ConfirmFormBase {

  /**
   * The policy containing the constraint to be deleted.
   *
   * @var \Drupal\shield\ShieldPolicyInterface
   */
  protected $policy;

  /**
   * The constraint to be deleted.
   *
   * @var \Drupal\shield\ShieldConstraintInterface
   */
  protected $constraint;

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the @constraint constraint from the %policy policy?', ['%policy' => $this->policy->label(), '@constraint' => $this->constraint->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->policy->toUrl('edit-form');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_constraint_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ShieldPolicyInterface $policy = NULL, $constraint = NULL) {
    $this->policy = $policy;
    $this->constraint = $this->policy->getConstraint($constraint);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->policy->deleteConstraint($this->constraint);
    $this->messenger()->addStatus($this->t('The constraint %name has been deleted.', ['%name' => $this->constraint->getTitle()]));
    $form_state->setRedirectUrl($this->policy->toUrl('edit-form'));
  }

}
