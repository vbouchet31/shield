<?php

namespace Drupal\shield\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines a ShieldCredentialProvider attribute for plugin discovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ShieldCredentialProvider extends Plugin {

  /**
   * Constructs a ShieldCredentialProvider attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The human-readable title of the credential provider.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A short description of the credential provider.
   * @param string|null $deriver
   *   (optional) The deriver class for this plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly \Drupal\Core\StringTranslation\TranslatableMarkup $title,
    public readonly ?\Drupal\Core\StringTranslation\TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
