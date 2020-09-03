<?php

namespace Drupal\shield\Plugin\ShieldConstraint;

use Drupal\shield\ShieldConstraintBase;

/**
 * Always prompt Shield.
 *
 * @ShieldConstraint(
 *   id = "always_true",
 *   title = @Translation("Always True"),
 *   description = @Translation("Default constraint to enable shield without any specific setting.")
 * )
 */
class AlwaysTrue extends ShieldConstraintBase {

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('Always prompt Shield modal.');
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    return TRUE;
  }

}
