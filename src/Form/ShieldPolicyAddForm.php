<?php

namespace Drupal\shield\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Controller for shield policy addition forms.
 *
 * @internal
 */
class ShieldPolicyAddForm extends ShieldPolicyFormBase {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Policy %name was created.', ['%name' => $this->entity->label()]));
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Create new policy');

    return $actions;
  }

}
