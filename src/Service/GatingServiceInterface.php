<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for the gating service.
 */
interface GatingServiceInterface {

  /**
   * Checks if a moderation transition should be blocked.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return string|null
   *   The violation message if blocked, NULL if allowed.
   */
  public function checkGating(EntityInterface $entity): ?string;

  /**
   * Checks if the current user can override gating.
   *
   * @return bool
   *   TRUE if user can override, FALSE otherwise.
   */
  public function userCanOverride(): bool;

}

