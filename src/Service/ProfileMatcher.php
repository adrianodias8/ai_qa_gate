<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for matching entities to QA profiles.
 */
class ProfileMatcher {

  /**
   * Constructs a ProfileMatcher.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the applicable profile for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface|null
   *   The applicable profile or NULL.
   */
  public function getApplicableProfile(EntityInterface $entity): ?QaProfileInterface {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    return $this->getProfileForBundle($entityTypeId, $bundle);
  }

  /**
   * Gets the profile for a specific entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface|null
   *   The profile or NULL.
   */
  public function getProfileForBundle(string $entity_type_id, string $bundle): ?QaProfileInterface {
    $profiles = $this->getAllProfiles();

    // First, look for a bundle-specific profile.
    foreach ($profiles as $profile) {
      if ($profile->appliesTo($entity_type_id, $bundle) && !empty($profile->getTargetBundle())) {
        return $profile;
      }
    }

    // Then, look for a wildcard profile.
    foreach ($profiles as $profile) {
      if ($profile->appliesTo($entity_type_id, $bundle)) {
        return $profile;
      }
    }

    return NULL;
  }

  /**
   * Checks if any profile exists for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return bool
   *   TRUE if a profile exists, FALSE otherwise.
   */
  public function hasProfileForEntityType(string $entity_type_id): bool {
    $profiles = $this->getAllProfiles();

    foreach ($profiles as $profile) {
      if ($profile->isEnabled() && $profile->getTargetEntityTypeId() === $entity_type_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets all profiles for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface[]
   *   Array of profiles.
   */
  public function getProfilesForEntityType(string $entity_type_id): array {
    $profiles = $this->getAllProfiles();
    $matching = [];

    foreach ($profiles as $profile) {
      if ($profile->isEnabled() && $profile->getTargetEntityTypeId() === $entity_type_id) {
        $matching[$profile->id()] = $profile;
      }
    }

    return $matching;
  }

  /**
   * Gets all enabled profiles.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface[]
   *   Array of profiles.
   */
  protected function getAllProfiles(): array {
    $storage = $this->entityTypeManager->getStorage('qa_profile');
    return $storage->loadMultiple();
  }

}

