<?php

namespace Drupal\shield\Controller;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Shield policies.
 */
class ShieldPolicyListBuilder extends DraggableListBuilder {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shield_policy_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Policy name');
    $header['id'] = $this->t('Machine name');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['id'] = [
      '#markup' => $entity->id(),
    ];
    $row['status'] = [
      '#markup' => $entity->status() ? $this->t('Enabled') : $this->t('Disabled'),
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity) {
    $operations = parent::getOperations($entity);

    $operations['edit']['url'] = new Url('entity.shield_policy.edit_form', ['shield_policy' => $entity->id()]);

    unset($operations['translate']);

    return $operations;
  }

}
