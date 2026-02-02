<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for QA Profile entities.
 */
class QaProfileListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'label' => $this->t('Label'),
      'target' => $this->t('Target'),
      'reports' => $this->t('Reports'),
      'status' => $this->t('Status'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface $entity */
    $row = [
      'label' => $entity->label(),
      'target' => $entity->getTargetEntityTypeId() . ' / ' . ($entity->getTargetBundle() ?: $this->t('All bundles')),
      'reports' => implode(', ', $entity->getAgentsEnabled()) ?: $this->t('None'),
      'status' => $entity->isEnabled() ? $this->t('Enabled') : $this->t('Disabled'),
    ];
    return $row + parent::buildRow($entity);
  }

}

