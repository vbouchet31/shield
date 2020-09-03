<?php

namespace Drupal\shield\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\shield\ShieldPolicyInterface;

/**
 * Provides an edit form for constraints.
 *
 * @internal
 */
class ShieldConstraintEditForm extends ShieldConstraintFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ShieldPolicyInterface $policy = NULL, $constraint = NULL) {
    $form = parent::buildForm($form, $form_state, $policy, $constraint);

    $form['#title'] = $this->t('Edit %label constraint', ['%label' => $this->constraint->getTitle()]);
    $form['actions']['submit']['#value'] = $this->t('Update constraint');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareConstraint($constraint) {
    return $this->policy->getConstraint($constraint);
  }

}
