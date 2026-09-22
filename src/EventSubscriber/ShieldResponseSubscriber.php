<?php

namespace Drupal\shield\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Copies the debug headers set by ShieldPolicySubscriber onto the response.
 */
class ShieldResponseSubscriber implements EventSubscriberInterface {

  /**
   * The shield.settings configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Constructs a new ShieldResponseSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('shield.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 10]];
  }

  /**
   * Adds the debug headers and cache tag to the response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    $response = $event->getResponse();

    // ShieldPolicySubscriber stashes these on the request's attribute bag,
    // since it runs before routing and has no response to write to yet.
    if ($this->config->get('debug_header')) {
      $attributes = $event->getRequest()->attributes;
      if ($attributes->has('_shield_status')) {
        $response->headers->set('X-Shield-Status', $attributes->get('_shield_status'));
      }
      if ($attributes->has('_shield_policy')) {
        $response->headers->set('X-Shield-Policy-Name', $attributes->get('_shield_policy'));
      }
    }

    if (!$response instanceof CacheableResponseInterface) {
      return;
    }
    $response->addCacheableDependency($this->config);
  }

}
