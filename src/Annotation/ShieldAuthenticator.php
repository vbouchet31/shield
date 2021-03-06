<?php

namespace Drupal\shield\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a shield authenticator annotation object.
 *
 * @Annotation
 */
class ShieldAuthenticator extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the authenticator type.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $title;

  /**
   * The description shown to users.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

}
