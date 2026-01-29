<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides an interface for QA Report plugins.
 */
interface QaReportPluginInterface extends PluginInspectionInterface, ContainerFactoryPluginInterface {

  /**
   * Gets the plugin label.
   *
   * @return string
   *   The label.
   */
  public function label(): string;

  /**
   * Gets the plugin description.
   *
   * @return string
   *   The description.
   */
  public function description(): string;

  /**
   * Gets the report category.
   *
   * @return string
   *   The category (claims, tone, accessibility, pii, policy, etc.).
   */
  public function getCategory(): string;

  /**
   * Gets the default configuration.
   *
   * @return array
   *   The default configuration.
   */
  public function defaultConfiguration(): array;

  /**
   * Gets the current configuration.
   *
   * @return array
   *   The configuration.
   */
  public function getConfiguration(): array;

  /**
   * Sets the configuration.
   *
   * @param array $configuration
   *   The configuration.
   */
  public function setConfiguration(array $configuration): void;

  /**
   * Builds the prompt for the AI model.
   *
   * @param array $context
   *   The context from ContextBuilder containing:
   *   - meta: Entity metadata
   *   - fragments: Field fragments
   *   - combined_text: Combined text content
   *   - policies: Injected policy content.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return array
   *   Structured prompt with keys:
   *   - system_message: The system prompt
   *   - user_message: The user message
   *   - output_schema_description: Description of expected output format
   */
  public function buildPrompt(array $context, array $configuration): array;

  /**
   * Parses the raw AI response into normalized findings.
   *
   * @param string $raw
   *   The raw AI response.
   * @param array $configuration
   *   The plugin configuration.
   *
   * @return array
   *   Normalized findings array where each finding has:
   *   - category: string
   *   - severity: low|medium|high
   *   - title: string
   *   - explanation: string
   *   - evidence: array with field, excerpt, start, end
   *   - suggested_fix: string|null
   *   - confidence: float 0..1
   */
  public function parseResponse(string $raw, array $configuration): array;

  /**
   * Checks if this plugin supports the given entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if supported, FALSE otherwise.
   */
  public function supportsEntityType(string $entity_type_id): bool;

  /**
   * Gets the JSON schema for the expected output.
   *
   * @return array
   *   The JSON schema as an associative array.
   */
  public function getOutputSchema(): array;

  /**
   * Builds the configuration form for the plugin.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The configuration form elements.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array;

}

