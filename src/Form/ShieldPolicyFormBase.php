<?php

namespace Drupal\shield\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base form for shield policy add and edit forms.
 */
abstract class ShieldPolicyFormBase extends EntityForm {

  /**
   * The action storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\shield\ShieldPolicyInterface
   */
  protected $entity;

  /**
   * Constructs a new action form.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The action storage.
   */
  public function __construct(EntityStorageInterface $storage) {
    $this->storage = $storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('shield_policy')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Policy name'),
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#machine_name' => [
        'exists' => [$this, 'exists'],
      ],
      '#default_value' => $this->entity->id(),
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status() ?? TRUE,
    ];

    return parent::form($form, $form_state);
  }

  /**
   * Determines if the policy already exists.
   *
   * @param string $id
   *   The policy ID.
   *
   * @return bool
   *   TRUE if the policy exists, FALSE otherwise.
   */
  public function exists($id) {
    $policy = $this->storage->load($id);
    return !empty($policy);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);
    $form_state->setRedirectUrl($this->entity->toUrl('edit-form'));
  }

}
