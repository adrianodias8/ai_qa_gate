<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the runner service.
 */
interface RunnerInterface {

  /**
   * Runs QA analysis on an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   * @param string $profile_id
   *   The profile ID to use.
   * @param bool $force
   *   Whether to force a new run even if a recent one exists.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The QA run result.
   */
  public function run(EntityInterface $entity, string $profile_id, bool $force = FALSE): QaRunInterface;

  /**
   * Queues a QA analysis for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   * @param string $profile_id
   *   The profile ID to use.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The pending QA run.
   */
  public function queue(EntityInterface $entity, string $profile_id): QaRunInterface;

  /**
   * Gets the latest QA run for an entity and profile.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $profile_id
   *   The profile ID.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface|null
   *   The latest QA run or NULL.
   */
  public function getLatestRun(EntityInterface $entity, string $profile_id): ?QaRunInterface;

  /**
   * Executes the actual analysis (called by queue worker or sync mode).
   *
   * @param \Drupal\ai_qa_gate\Entity\QaRunInterface $qa_run
   *   The QA run to execute.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The updated QA run.
   */
  public function executeRun(QaRunInterface $qa_run): QaRunInterface;

  /**
   * Gets the applicable profile for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface|null
   *   The applicable profile or NULL.
   */
  public function getApplicableProfile(EntityInterface $entity): ?QaProfileInterface;

  /**
   * Runs a single agent for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   * @param string $profile_id
   *   The profile ID to use.
   * @param string $agent_id
   *   The agent ID to run.
   * @param bool $force
   *   Whether to force a new run even if a recent one exists.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The QA run result.
   */
  public function runAgent(EntityInterface $entity, string $profile_id, string $agent_id, bool $force = FALSE): QaRunInterface;

  /**
   * Queues a single agent for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   * @param string $profile_id
   *   The profile ID to use.
   * @param string $agent_id
   *   The agent ID to run.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The pending QA run.
   */
  public function queueAgent(EntityInterface $entity, string $profile_id, string $agent_id): QaRunInterface;

  /**
   * Executes a single agent for a QA run.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaRunInterface $qa_run
   *   The QA run.
   * @param string $agent_id
   *   The agent ID to execute.
   * @param int $retry_count
   *   Current retry count for rate limit handling.
   *
   * @return array
   *   Array with keys 'status', 'findings', 'error', 'provider_id', 'model'.
   */
  public function executeAgent(QaRunInterface $qa_run, string $agent_id, int $retry_count = 0): array;

  /**
   * Queues all agents for an entity (per-agent queue items).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to analyze.
   * @param string $profile_id
   *   The profile ID to use.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The pending QA run.
   */
  public function queueAllAgents(EntityInterface $entity, string $profile_id): QaRunInterface;

}
