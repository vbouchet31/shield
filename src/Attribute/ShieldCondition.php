<?php

namespace Drupal\shield\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines a ShieldCondition attribute for plugin discovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ShieldCondition extends Plugin {

  /**
   * Constructs a ShieldCondition attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The human-readable title of the condition.
   * @param bool $cacheKeyAware
   *   Whether the request property this condition inspects is itself part
   *   of the page cache key built by
   *   \Drupal\page_cache\StackMiddleware\PageCache::getCacheId() (scheme,
   *   host, URI and format). Set TRUE only for a condition on one of those
   *   properties (e.g. path inspects the URI, domain inspects the host): a
   *   request that fails such a condition already gets a distinct cache
   *   entry, so excluding it from
   *   \Drupal\shield\Entity\ShieldPolicy::matchesCacheableConditions()'s
   *   sweep cannot leak a response across that boundary. Set FALSE for
   *   anything else (e.g. the User-Agent header, the client IP, the HTTP
   *   method) - two requests differing only on such a property share one
   *   cache entry, so a condition on it must never be allowed to exclude a
   *   policy from the sweep. Required, with no default: a new condition
   *   plugin must declare this explicitly rather than silently inherit a
   *   value that could poison the page cache.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A short description of the condition.
   * @param string|null $deriver
   *   (optional) The deriver class for this plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly \Drupal\Core\StringTranslation\TranslatableMarkup $title,
    public readonly bool $cacheKeyAware,
    public readonly ?\Drupal\Core\StringTranslation\TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
