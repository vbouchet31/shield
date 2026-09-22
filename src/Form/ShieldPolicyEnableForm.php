<?php

namespace Drupal\shield\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides the Shield policy enable form.
 */
class ShieldPolicyEnableForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to enable the %policy policy?', ['%policy' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.shield_policy.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Enable');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Enabled policies are evaluated on every request. This action can be undone from the policies administration page.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->enable()->save();
    $this->messenger()->addStatus($this->t('The %policy policy has been enabled.', ['%policy' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
