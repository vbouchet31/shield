<?php

namespace Drupal\shield_password\Plugin\ShieldCredentialProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shield\Attribute\ShieldCredentialProvider;
use Drupal\shield\ShieldCredentialProviderBase;

/**
 * Checks credentials against one or more username/password pairs.
 */
#[ShieldCredentialProvider(
  id: 'password',
  title: new TranslatableMarkup('Username and password'),
  description: new TranslatableMarkup('Check credentials against one or more username/password pairs configured directly on the policy.'),
)]
class Password extends ShieldCredentialProviderBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['users' => [], 'passwords' => []] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#description' => $this->t('To accept more than one set of credentials, separate the usernames with a pipe (|), for example <em>user1|user2</em>, and list the matching passwords in the same order below. A username or password cannot itself contain a pipe.'),
      '#default_value' => implode('|', $this->configuration['users']),
      '#required' => TRUE,
    ];
    $form['pass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('One password, or a pipe separated list holding one password for each username above, in the same order.'),
      '#default_value' => implode('|', $this->configuration['passwords']),
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $users = explode('|', (string) $form_state->getValue('user'));
    $passwords = explode('|', (string) $form_state->getValue('pass'));

    if (count($users) !== count($passwords)) {
      $form_state->setErrorByName('pass', $this->t('@user_count usernames were given but @pass_count passwords. Each username needs exactly one password, in the same order.', [
        '@user_count' => count($users),
        '@pass_count' => count($passwords),
      ]));
      return;
    }

    // Only meaningful once more than one pair is configured; a single pair
    // is the common case and stays this provider's own concern.
    if (count($users) === 1) {
      return;
    }

    if (in_array('', $users, TRUE)) {
      $form_state->setErrorByName('user', $this->t('Usernames cannot be empty.'));
    }
    elseif (count(array_unique($users)) !== count($users)) {
      $form_state->setErrorByName('user', $this->t('Each username can only be listed once.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['users'] = explode('|', (string) $form_state->getValue('user'));
    $this->configuration['passwords'] = explode('|', (string) $form_state->getValue('pass'));
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return $this->t('Users: @users', ['@users' => implode(', ', $this->configuration['users'])]);
  }

  /**
   * {@inheritdoc}
   */
  public function checkCredentials($user, $pass) {
    $users = $this->configuration['users'];
    $passwords = $this->configuration['passwords'];

    // A mismatched pair count means the configuration is ambiguous. Refusing
    // every credential is the safe reading; the plugin form rejects this up
    // front, so it can only happen to configuration written directly.
    if (!$users || count($users) !== count($passwords)) {
      return FALSE;
    }

    $credentials = array_combine($users, $passwords);
    if (!array_key_exists($user, $credentials)) {
      return FALSE;
    }

    return hash_equals((string) $credentials[$user], (string) $pass);
  }

}
