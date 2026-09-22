<?php

namespace Drupal\shield\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base form for adding and editing Shield policies.
 */
abstract class ShieldPolicyForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#open' => TRUE,
    ];

    $form['general']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('The human-readable name of this policy. This is only administrative and will not be shown to site visitors.'),
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];

    $form['general']['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => [$this->entity->getEntityType()->getClass(), 'load'],
        'source' => ['general', 'label'],
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['general']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->isNew() ? TRUE : $this->entity->status(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->label()];
    $message = $status == SAVED_NEW
      ? $this->t('The Shield policy %label has been added.', $message_args)
      : $this->t('The Shield policy %label has been updated.', $message_args);
    $this->messenger()->addStatus($message);

    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));

    return $status;
  }

}
