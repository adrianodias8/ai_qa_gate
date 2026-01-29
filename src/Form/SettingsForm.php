<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for AI QA Gate.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_qa_gate_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_qa_gate.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_qa_gate.settings');

    $form['default_run_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Default run mode'),
      '#options' => [
        'queue' => $this->t('Queue (background processing)'),
        'sync' => $this->t('Synchronous (immediate, for development)'),
      ],
      '#default_value' => $config->get('default_run_mode') ?? 'queue',
      '#description' => $this->t('The default execution mode for QA analysis. Profiles can override this setting.'),
    ];

    $form['log_ai_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log AI requests'),
      '#default_value' => $config->get('log_ai_requests') ?? FALSE,
      '#description' => $this->t('Log all AI requests for debugging purposes. Not recommended for production.'),
    ];

    $form['rate_limiting'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate Limiting & Backoff'),
      '#open' => TRUE,
    ];

    $form['rate_limiting']['plugin_backoff_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Backoff seconds between plugin runs'),
      '#default_value' => $config->get('plugin_backoff_seconds') ?? 5,
      '#min' => 0,
      '#max' => 120,
      '#description' => $this->t('Number of seconds to wait between each plugin execution. Helps avoid API rate limits. Set to 0 for no delay (not recommended).'),
    ];

    $form['rate_limiting']['retry_on_rate_limit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Retry on rate limit errors'),
      '#default_value' => $config->get('retry_on_rate_limit') ?? TRUE,
      '#description' => $this->t('Automatically retry failed requests due to rate limiting.'),
    ];

    $form['rate_limiting']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum retry attempts'),
      '#default_value' => $config->get('max_retries') ?? 3,
      '#min' => 0,
      '#max' => 10,
      '#description' => $this->t('Maximum number of retry attempts for rate-limited requests.'),
      '#states' => [
        'visible' => [
          ':input[name="retry_on_rate_limit"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['retry_backoff_multiplier'] = [
      '#type' => 'number',
      '#title' => $this->t('Retry backoff multiplier'),
      '#default_value' => $config->get('retry_backoff_multiplier') ?? 2.0,
      '#min' => 1,
      '#max' => 5,
      '#step' => 0.5,
      '#description' => $this->t('Multiplier for exponential backoff on retries. E.g., with 2.0: 5s, 10s, 20s delays.'),
      '#states' => [
        'visible' => [
          ':input[name="retry_on_rate_limit"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_qa_gate.settings')
      ->set('default_run_mode', $form_state->getValue('default_run_mode'))
      ->set('log_ai_requests', $form_state->getValue('log_ai_requests'))
      ->set('plugin_backoff_seconds', (int) $form_state->getValue('plugin_backoff_seconds'))
      ->set('retry_on_rate_limit', (bool) $form_state->getValue('retry_on_rate_limit'))
      ->set('max_retries', (int) $form_state->getValue('max_retries'))
      ->set('retry_backoff_multiplier', (float) $form_state->getValue('retry_backoff_multiplier'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

