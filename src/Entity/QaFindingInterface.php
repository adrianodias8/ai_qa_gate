<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\UserInterface;

/**
 * Provides an interface for QA Finding entities.
 */
interface QaFindingInterface extends ContentEntityInterface {

  /**
   * Severity constants.
   */
  const SEVERITY_LOW = 'low';
  const SEVERITY_MEDIUM = 'medium';
  const SEVERITY_HIGH = 'high';

  /**
   * Gets the QA run ID.
   *
   * @return int
   *   The QA run ID.
   */
  public function getQaRunId(): int;

  /**
   * Gets the plugin ID that generated this finding.
   *
   * @return string
   *   The plugin ID.
   */
  public function getPluginId(): string;

  /**
   * Gets the finding category.
   *
   * @return string
   *   The category.
   */
  public function getCategory(): string;

  /**
   * Gets the severity level.
   *
   * @return string
   *   The severity (low, medium, high).
   */
  public function getSeverity(): string;

  /**
   * Gets the finding title.
   *
   * @return string
   *   The title.
   */
  public function getTitle(): string;

  /**
   * Gets the explanation.
   *
   * @return string
   *   The explanation text.
   */
  public function getExplanation(): string;

  /**
   * Gets the evidence excerpt.
   *
   * @return string|null
   *   The evidence excerpt or NULL.
   */
  public function getEvidenceExcerpt(): ?string;

  /**
   * Gets the evidence field name.
   *
   * @return string|null
   *   The field name or NULL.
   */
  public function getEvidenceField(): ?string;

  /**
   * Gets the suggested fix.
   *
   * @return string|null
   *   The suggested fix or NULL.
   */
  public function getSuggestedFix(): ?string;

  /**
   * Gets the created timestamp.
   *
   * @return int
   *   The timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Converts the finding to an array format.
   *
   * @return array
   *   The finding as an array with keys: category, severity, title,
   *   explanation, evidence, suggested_fix.
   */
  public function toArray(): array;

  /**
   * Loads findings for a QA run.
   *
   * @param int $qa_run_id
   *   The QA run ID.
   * @param string|null $plugin_id
   *   Optional plugin ID filter.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaFindingInterface[]
   *   Array of findings.
   */
  public static function loadForRun(int $qa_run_id, ?string $plugin_id = NULL): array;

  /**
   * Deletes findings for a QA run and plugin.
   *
   * @param int $qa_run_id
   *   The QA run ID.
   * @param string $plugin_id
   *   The plugin ID.
   */
  public static function deleteForPlugin(int $qa_run_id, string $plugin_id): void;

  /**
   * Checks if the finding has been acknowledged.
   *
   * @return bool
   *   TRUE if acknowledged, FALSE otherwise.
   */
  public function isAcknowledged(): bool;

  /**
   * Gets the user who acknowledged this finding.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user who acknowledged it, or NULL if not acknowledged.
   */
  public function getAcknowledgedBy(): ?UserInterface;

  /**
   * Gets the timestamp when the finding was acknowledged.
   *
   * @return int|null
   *   The timestamp, or NULL if not acknowledged.
   */
  public function getAcknowledgedAt(): ?int;

  /**
   * Gets the acknowledgement note.
   *
   * @return string|null
   *   The note, or NULL if no note was provided.
   */
  public function getAcknowledgementNote(): ?string;

  /**
   * Acknowledges this finding.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user acknowledging the finding.
   * @param string|null $note
   *   Optional note explaining the acknowledgement.
   *
   * @return $this
   *   The finding entity for method chaining.
   */
  public function acknowledge(UserInterface $user, ?string $note = NULL): self;

}

