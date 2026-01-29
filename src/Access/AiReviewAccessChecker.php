<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Access;

use Drupal\ai_qa_gate\Service\ProfileMatcher;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access checker for AI Review routes.
 */
class AiReviewAccessChecker implements AccessInterface {

  /**
   * Constructs an AiReviewAccessChecker.
   *
   * @param \Drupal\ai_qa_gate\Service\ProfileMatcher $profileMatcher
   *   The profile matcher.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected readonly ProfileMatcher $profileMatcher,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Checks access for the AI Review tab.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    // Check view permission.
    $hasPermission = $account->hasPermission('view ai qa results');

    // Check if profile exists for this entity.
    $profile = $this->profileMatcher->getApplicableProfile($entity);
    $hasProfile = $profile !== NULL;

    // Check entity view access.
    $canView = $entity->access('view', $account);

    $result = AccessResult::allowedIf($hasPermission && $hasProfile && $canView);
    $result->addCacheableDependency($entity);

    if ($profile) {
      $result->addCacheableDependency($profile);
    }

    $result->cachePerPermissions();

    return $result;
  }

}

