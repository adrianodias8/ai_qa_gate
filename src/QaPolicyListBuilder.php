<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for QA Policy entities.
 */
class QaPolicyListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'label' => $this->t('Label'),
      'jurisdiction' => $this->t('Jurisdiction'),
      'audience' => $this->t('Audience'),
      'version' => $this->t('Version'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface $entity */
    $metadata = $entity->getMetadata();
    $row = [
      'label' => $entity->label(),
      'jurisdiction' => $metadata['jurisdiction'] ?? '-',
      'audience' => $metadata['audience'] ?? '-',
      'version' => $metadata['version'] ?? '-',
    ];
    return $row + parent::buildRow($entity);
  }

}

