<?php

namespace Drupal\shield\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\shield\ConfigurableShieldConstraintInterface;
use Drupal\shield\ShieldPolicyInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a base form for constraints.
 */
abstract class ShieldConstraintFormBase extends FormBase {

  /**
   * The policy.
   *
   * @var \Drupal\shield\ShieldPolicyInterface
   */
  protected $policy;

  /**
   * The constraint.
   *
   * @var \Drupal\shield\ShieldConstraintInterface|\Drupal\shield\ConfigurableShieldConstraintInterface
   */
  protected $constraint;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_constraint_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\shield\ShieldPolicyInterface $policy
   *   The policy.
   * @param string $constraint
   *   The constraint ID.
   *
   * @return array
   *   The form structure.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function buildForm(array $form, FormStateInterface $form_state, ShieldPolicyInterface $policy = NULL, $constraint = NULL) {
    $this->policy = $policy;
    try {
      $this->constraint = $this->prepareConstraint($constraint);
    }
    catch (PluginNotFoundException $e) {
      throw new NotFoundHttpException("Invalid constraint id: '$constraint'.");
    }
    $request = $this->getRequest();

    if (!($this->constraint instanceof ConfigurableShieldConstraintInterface)) {
      throw new NotFoundHttpException();
    }

    $form['uuid'] = [
      '#type' => 'value',
      '#value' => $this->constraint->getUuid(),
    ];
    $form['id'] = [
      '#type' => 'value',
      '#value' => $this->constraint->getPluginId(),
    ];

    $form['data'] = [];
    $subform_state = SubformState::createForSubform($form['data'], $form, $form_state);
    $form['data'] = $this->constraint->buildConfigurationForm($form['data'], $subform_state);
    $form['data']['#tree'] = TRUE;

    // Check the URL for a weight, then the constraint, otherwise use default.
    $form['weight'] = [
      '#type' => 'hidden',
      '#value' => $request->query->has('weight') ? (int) $request->query->get('weight') : $this->constraint->getWeight(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->policy->toUrl('edit-form'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // The constraint configuration is stored in the 'data' key in the form,
    // pass that through for validation.
    $this->constraint->validateConfigurationForm($form['data'], SubformState::createForSubform($form['data'], $form, $form_state));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();

    // The constraint configuration is stored in the 'data' key in the form,
    // pass that through for submission.
    $this->constraint->submitConfigurationForm($form['data'], SubformState::createForSubform($form['data'], $form, $form_state));

    $this->constraint->setWeight($form_state->getValue('weight'));
    if (!$this->constraint->getUuid()) {
      $this->policy->addConstraint($this->constraint->getConfiguration());
    }
    $this->policy->save();

    $this->messenger()->addStatus($this->t('The constraint was successfully applied.'));
    $form_state->setRedirectUrl($this->policy->toUrl('edit-form'));
  }

  /**
   * Converts a constraint ID into an object.
   *
   * @param string $constraint
   *   The constraint ID.
   *
   * @return \Drupal\shield\ShieldConstraintInterface
   *   The constraint object.
   */
  abstract protected function prepareConstraint($constraint);

}
