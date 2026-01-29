<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for QA Run entities.
 */
interface QaRunInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Status constants.
   */
  const STATUS_PENDING = 'pending';
  const STATUS_SUCCESS = 'success';
  const STATUS_FAILED = 'failed';

  /**
   * Severity constants.
   */
  const SEVERITY_LOW = 'low';
  const SEVERITY_MEDIUM = 'medium';
  const SEVERITY_HIGH = 'high';
  const SEVERITY_NONE = 'none';

  /**
   * Gets the target entity type ID.
   *
   * @return string
   *   The entity type ID.
   */
  public function getTargetEntityTypeId(): string;

  /**
   * Gets the target entity ID.
   *
   * @return string
   *   The entity ID.
   */
  public function getTargetEntityId(): string;

  /**
   * Gets the target revision ID.
   *
   * @return string|null
   *   The revision ID or NULL.
   */
  public function getTargetRevisionId(): ?string;

  /**
   * Gets the profile ID.
   *
   * @return string
   *   The profile ID.
   */
  public function getProfileId(): string;

  /**
   * Gets the execution timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getExecutedAt(): int;

  /**
   * Gets the provider ID used.
   *
   * @return string|null
   *   The provider ID or NULL.
   */
  public function getProviderId(): ?string;

  /**
   * Gets the model used.
   *
   * @return string|null
   *   The model or NULL.
   */
  public function getModel(): ?string;

  /**
   * Gets the status.
   *
   * @return string
   *   The status (pending, success, failed).
   */
  public function getStatus(): string;

  /**
   * Gets the input hash.
   *
   * @return string
   *   The input hash.
   */
  public function getInputHash(): string;

  /**
   * Gets the results JSON.
   *
   * @return array
   *   The decoded results.
   */
  public function getResults(): array;

  /**
   * Gets the raw results JSON string.
   *
   * @return string
   *   The raw JSON string.
   */
  public function getResultsRaw(): string;

  /**
   * Gets the error message.
   *
   * @return string|null
   *   The error message or NULL.
   */
  public function getErrorMessage(): ?string;

  /**
   * Gets the high severity count.
   *
   * @return int
   *   The count.
   */
  public function getHighCount(): int;

  /**
   * Gets the medium severity count.
   *
   * @return int
   *   The count.
   */
  public function getMediumCount(): int;

  /**
   * Gets the low severity count.
   *
   * @return int
   *   The count.
   */
  public function getLowCount(): int;

  /**
   * Gets the maximum severity from findings.
   *
   * @return string
   *   The maximum severity (none, low, medium, high).
   */
  public function getMaxSeverity(): string;

  /**
   * Gets all findings from results.
   *
   * @return array
   *   Array of findings.
   */
  public function getFindings(): array;

  /**
   * Gets findings grouped by category.
   *
   * @return array
   *   Findings grouped by category.
   */
  public function getFindingsByCategory(): array;

  /**
   * Gets findings grouped by severity.
   *
   * @return array
   *   Findings grouped by severity.
   */
  public function getFindingsBySeverity(): array;

  /**
   * Checks if the run is stale based on input hash.
   *
   * @param string $current_hash
   *   The current input hash.
   *
   * @return bool
   *   TRUE if stale, FALSE otherwise.
   */
  public function isStale(string $current_hash): bool;

  /**
   * Checks if the run was successful.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function isSuccessful(): bool;

  /**
   * Sets the status.
   *
   * @param string $status
   *   The status.
   *
   * @return $this
   */
  public function setStatus(string $status): self;

  /**
   * Sets the results.
   *
   * @param array $results
   *   The results array.
   *
   * @return $this
   */
  public function setResults(array $results): self;

  /**
   * Sets the error message.
   *
   * @param string $message
   *   The error message.
   *
   * @return $this
   */
  public function setErrorMessage(string $message): self;

  /**
   * Computes summary counts from results.
   *
   * @return $this
   */
  public function computeSummaryCounts(): self;

  /**
   * Gets per-plugin results.
   *
   * @return array
   *   Array of plugin results keyed by plugin ID.
   */
  public function getPluginResults(): array;

  /**
   * Sets per-plugin results.
   *
   * @param array $plugin_results
   *   The plugin results array.
   *
   * @return $this
   */
  public function setPluginResults(array $plugin_results): self;

  /**
   * Gets the status for a specific plugin.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return string
   *   The status (pending, success, failed).
   */
  public function getPluginStatus(string $plugin_id): string;

  /**
   * Sets the status for a specific plugin.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param string $status
   *   The status.
   * @param array|null $findings
   *   Optional findings array.
   * @param string|null $error
   *   Optional error message.
   *
   * @return $this
   */
  public function setPluginStatus(string $plugin_id, string $status, ?array $findings = NULL, ?string $error = NULL): self;

  /**
   * Gets the findings for a specific plugin.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return array
   *   The findings array.
   */
  public function getPluginFindings(string $plugin_id): array;

  /**
   * Gets the error message for a specific plugin.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return string|null
   *   The error message or NULL.
   */
  public function getPluginError(string $plugin_id): ?string;

  /**
   * Checks if all expected plugins have completed.
   *
   * @param array $expected_plugin_ids
   *   Array of expected plugin IDs.
   *
   * @return bool
   *   TRUE if all plugins are complete (success or failed), FALSE otherwise.
   */
  public function areAllPluginsComplete(array $expected_plugin_ids): bool;

  /**
   * Aggregates findings from all plugins into the main results.
   *
   * @return $this
   */
  public function aggregatePluginFindings(): self;

}

