<?php

namespace Drupal\shield_path\Plugin\ShieldCondition;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\shield\Attribute\ShieldCondition;
use Drupal\shield\ShieldConditionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Matches the request against a list of path patterns.
 */
#[ShieldCondition(
  id: 'path',
  title: new TranslatableMarkup('Path'),
  description: new TranslatableMarkup('Match the request against a list of path patterns.'),
)]
class Path extends ShieldConditionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new Path condition object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin
   *   instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Path\PathMatcherInterface $pathMatcher
   *   The path matcher service.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read the configured language prefixes.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected PathMatcherInterface $pathMatcher,
    protected AliasManagerInterface $aliasManager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('path.matcher'),
      $container->get('path_alias.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function affectsCacheability() {
    // A path match narrows a policy to a subset of requests, so a response
    // served under it can never be safe for the page cache to reuse for a
    // request outside that subset - see ShieldConditionInterface.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['paths' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Paths'),
      '#description' => $this->t('Enter one path per line. The %front token matches the front page and an asterisk (*) matches any number of characters, e.g. "/admin/*". Enter the alias a visitor sees; the internal system path is also matched.', ['%front' => '<front>']),
      '#default_value' => $this->configuration['paths'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['paths'] = $form_state->getValue('paths');
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $paths = array_filter(array_map('trim', preg_split('/\R/u', (string) $this->configuration['paths']) ?: []));
    return $this->t('Paths: @paths', ['@paths' => implode(', ', $paths)]);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request) {
    $patterns = (string) $this->configuration['paths'];
    if (trim($patterns) === '') {
      return FALSE;
    }

    $path = $this->normalizePath($request->getPathInfo());
    if ($this->pathMatcher->matchPath($path, $patterns)) {
      return TRUE;
    }

    $system_path = $this->normalizePath($this->aliasManager->getPathByAlias($path));
    if ($system_path !== $path && $this->pathMatcher->matchPath($system_path, $patterns)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Normalizes a request path for matching.
   *
   * Shield's subscriber runs before routing (see ShieldPolicySubscriber), so
   * path_alias.manager and path.matcher are the only safe, post-boot way to
   * resolve and match the path - path.current is not populated yet. Stripping
   * the language prefix still has to be done by hand, from a plain config
   * read, since the language manager itself loads configurable language
   * entities.
   *
   * @param string $path
   *   The path to normalize.
   *
   * @return string
   *   The normalized path.
   */
  protected function normalizePath(string $path): string {
    $path = $this->stripLanguagePrefix($path);
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
  }

  /**
   * Removes a configured language prefix from the start of a path.
   *
   * @param string $path
   *   The incoming request path.
   *
   * @return string
   *   The path without its language prefix, if it had one.
   */
  protected function stripLanguagePrefix(string $path): string {
    $prefixes = $this->configFactory->get('language.negotiation')->get('url.prefixes');
    if (empty($prefixes) || !is_array($prefixes)) {
      return $path;
    }

    foreach ($prefixes as $prefix) {
      if ($prefix === '' || !is_string($prefix)) {
        continue;
      }
      if ($path === '/' . $prefix) {
        return '/';
      }
      if (str_starts_with($path, '/' . $prefix . '/')) {
        return substr($path, strlen($prefix) + 1);
      }
    }

    return $path;
  }

}
