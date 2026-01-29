<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the context builder service.
 */
interface ContextBuilderInterface {

  /**
   * Builds the context for an entity based on a QA profile.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to build context for.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The QA profile.
   *
   * @return array
   *   The context array containing:
   *   - meta: Entity metadata
   *   - fragments: Field fragments
   *   - combined_text: Combined text content
   *   - policies: Injected policy content.
   */
  public function buildContext(EntityInterface $entity, QaProfileInterface $profile): array;

  /**
   * Computes the input hash for an entity and profile.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The QA profile.
   *
   * @return string
   *   The SHA256 hash.
   */
  public function computeInputHash(EntityInterface $entity, QaProfileInterface $profile): string;

}

