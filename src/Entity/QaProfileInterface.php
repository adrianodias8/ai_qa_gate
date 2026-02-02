<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for QA Profile entities.
 */
interface QaProfileInterface extends ConfigEntityInterface {

  /**
   * Gets whether the profile is enabled.
   *
   * @return bool
   *   TRUE if enabled, FALSE otherwise.
   */
  public function isEnabled(): bool;

  /**
   * Gets the target entity type ID.
   *
   * @return string
   *   The entity type ID.
   */
  public function getTargetEntityTypeId(): string;

  /**
   * Gets the target bundle.
   *
   * @return string
   *   The bundle name, or empty string for all bundles.
   */
  public function getTargetBundle(): string;

  /**
   * Gets the fields to analyze configuration.
   *
   * @return array
   *   Array of field configurations.
   */
  public function getFieldsToAnalyze(): array;

  /**
   * Gets the include meta configuration.
   *
   * @return array
   *   Array of meta inclusion settings.
   */
  public function getIncludeMeta(): array;

  /**
   * Gets the list of enabled agent IDs.
   *
   * @return array
   *   Array of agent IDs.
   */
  public function getAgentsEnabled(): array;

  /**
   * Gets the execution settings.
   *
   * @return array
   *   Array of execution settings.
   */
  public function getExecutionSettings(): array;

  /**
   * Gets the gating settings.
   *
   * @return array
   *   Array of gating settings.
   */
  public function getGatingSettings(): array;

  /**
   * Gets the access settings.
   *
   * @return array
   *   Array of access settings.
   */
  public function getAccessSettings(): array;

  /**
   * Checks if this profile applies to the given entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   *
   * @return bool
   *   TRUE if profile applies, FALSE otherwise.
   */
  public function appliesTo(string $entity_type_id, string $bundle): bool;

  /**
   * Gets the run mode (queue or sync).
   *
   * @return string
   *   The run mode.
   */
  public function getRunMode(): string;

  /**
   * Gets whether gating is enabled.
   *
   * @return bool
   *   TRUE if gating is enabled.
   */
  public function isGatingEnabled(): bool;

  /**
   * Gets the severity threshold for gating.
   *
   * @return string
   *   The severity threshold (low, medium, high).
   */
  public function getSeverityThreshold(): string;

  /**
   * Gets the states where gating should apply.
   *
   * @return array
   *   Array of state IDs.
   */
  public function getApplyToStates(): array;

  /**
   * Gets the blocked transition IDs.
   *
   * @return array
   *   Array of transition IDs.
   */
  public function getBlockedTransitionIds(): array;

}
