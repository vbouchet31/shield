<?php

namespace Drupal\shield\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\ConfirmFormHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes a condition from a Shield policy, via an AJAX modal.
 */
class ShieldPolicyConditionDeleteForm extends ConfirmFormBase {

  /**
   * The Shield policy being edited.
   *
   * @var \Drupal\shield\ShieldPolicyInterface
   */
  protected $shieldPolicy;

  /**
   * The condition delta being deleted.
   *
   * @var int
   */
  protected $key;

  /**
   * Constructs a new ShieldPolicyConditionDeleteForm object.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $currentRouteMatch
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected RouteMatchInterface $currentRouteMatch,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_policy_condition_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $machine_name = NULL, $id = NULL) {
    $policy_id = $this->currentRouteMatch->getParameter('shield_policy_id');
    $this->shieldPolicy = $this->entityTypeManager->getStorage('shield_policy')->load($policy_id);
    $this->key = $id;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $condition = $this->shieldPolicy->getCondition($this->key);
    return $this->t('Are you sure you want to delete the %id condition?', ['%id' => $condition['id'] ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('entity.shield_policy.edit_form', ['shield_policy' => $this->shieldPolicy->id()]);
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $conditions = $this->shieldPolicy->getConditions();
    unset($conditions[$this->key]);
    $this->shieldPolicy->set('conditions', $conditions);
    $this->shieldPolicy->save();

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    return [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->getConfirmText(),
        '#submit' => [[$this, 'submitForm']],
      ],
      'cancel' => ConfirmFormHelper::buildCancelLink($this, $this->getRequest()),
    ];
  }

}
