<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the QA Run content entity.
 *
 * @ContentEntityType(
 *   id = "qa_run",
 *   label = @Translation("QA Run"),
 *   label_collection = @Translation("QA Runs"),
 *   label_singular = @Translation("QA run"),
 *   label_plural = @Translation("QA runs"),
 *   label_count = @PluralTranslation(
 *     singular = "@count QA run",
 *     plural = "@count QA runs",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "qa_run",
 *   admin_permission = "administer ai qa gate",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "executed_by",
 *   },
 * )
 */
class QaRun extends ContentEntityBase implements QaRunInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['entity_type_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity type ID'))
      ->setDescription(t('The target entity type ID.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ]);

    $fields['entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity ID'))
      ->setDescription(t('The target entity ID.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 1,
      ]);

    $fields['revision_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The target entity revision ID.'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ]);

    $fields['profile_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Profile ID'))
      ->setDescription(t('The QA profile used.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ]);

    $fields['executed_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Executed by'))
      ->setDescription(t('The user who executed the run.'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 4,
      ]);

    $fields['executed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Executed at'))
      ->setDescription(t('The timestamp when the run was executed.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 5,
      ]);

    $fields['provider_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider ID'))
      ->setDescription(t('The AI provider used.'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 6,
      ]);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model'))
      ->setDescription(t('The AI model used.'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 7,
      ]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setDescription(t('The run status.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_PENDING)
      ->setSetting('allowed_values', [
        self::STATUS_PENDING => t('Pending'),
        self::STATUS_SUCCESS => t('Success'),
        self::STATUS_FAILED => t('Failed'),
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 8,
      ]);

    $fields['input_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Input hash'))
      ->setDescription(t('SHA256 hash of the input for staleness detection.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 9,
      ]);

    $fields['results'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Results'))
      ->setDescription(t('JSON results of the analysis.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 10,
      ]);

    $fields['high_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('High severity count'))
      ->setDescription(t('Number of high severity findings.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 11,
      ]);

    $fields['medium_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Medium severity count'))
      ->setDescription(t('Number of medium severity findings.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 12,
      ]);

    $fields['low_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Low severity count'))
      ->setDescription(t('Number of low severity findings.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 13,
      ]);

    $fields['error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Error message'))
      ->setDescription(t('Error message if the run failed.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 14,
      ]);

    $fields['plugin_results'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Plugin results'))
      ->setDescription(t('JSON per-plugin status and results.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 15,
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Set executed_at if not set.
    if (empty($this->get('executed_at')->value)) {
      $this->set('executed_at', \Drupal::time()->getRequestTime());
    }

    // Compute summary counts if results exist.
    if (!empty($this->get('results')->value)) {
      $this->computeSummaryCounts();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return $this->get('entity_type_id')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityId(): string {
    return $this->get('entity_id')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetRevisionId(): ?string {
    return $this->get('revision_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getProfileId(): string {
    return $this->get('profile_id')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getExecutedAt(): int {
    return (int) $this->get('executed_at')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): ?string {
    return $this->get('provider_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getModel(): ?string {
    return $this->get('model')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return $this->get('status')->value ?? self::STATUS_PENDING;
  }

  /**
   * {@inheritdoc}
   */
  public function getInputHash(): string {
    return $this->get('input_hash')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getResults(): array {
    $raw = $this->get('results')->value;
    if (empty($raw)) {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getResultsRaw(): string {
    return $this->get('results')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getErrorMessage(): ?string {
    return $this->get('error_message')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getHighCount(): int {
    return (int) $this->get('high_count')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMediumCount(): int {
    return (int) $this->get('medium_count')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLowCount(): int {
    return (int) $this->get('low_count')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxSeverity(): string {
    if ($this->getHighCount() > 0) {
      return self::SEVERITY_HIGH;
    }
    if ($this->getMediumCount() > 0) {
      return self::SEVERITY_MEDIUM;
    }
    if ($this->getLowCount() > 0) {
      return self::SEVERITY_LOW;
    }
    return self::SEVERITY_NONE;
  }

  /**
   * {@inheritdoc}
   */
  public function getFindings(): array {
    $results = $this->getResults();
    return $results['findings'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFindingsByCategory(): array {
    $grouped = [];
    foreach ($this->getFindings() as $finding) {
      $category = $finding['category'] ?? 'other';
      $grouped[$category][] = $finding;
    }
    return $grouped;
  }

  /**
   * {@inheritdoc}
   */
  public function getFindingsBySeverity(): array {
    $grouped = [
      self::SEVERITY_HIGH => [],
      self::SEVERITY_MEDIUM => [],
      self::SEVERITY_LOW => [],
    ];
    foreach ($this->getFindings() as $finding) {
      $severity = $finding['severity'] ?? self::SEVERITY_LOW;
      $grouped[$severity][] = $finding;
    }
    return $grouped;
  }

  /**
   * {@inheritdoc}
   */
  public function isStale(string $current_hash): bool {
    return $this->getInputHash() !== $current_hash;
  }

  /**
   * {@inheritdoc}
   */
  public function isSuccessful(): bool {
    return $this->getStatus() === self::STATUS_SUCCESS;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): QaRunInterface {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setResults(array $results): QaRunInterface {
    $this->set('results', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setErrorMessage(string $message): QaRunInterface {
    $this->set('error_message', $message);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function computeSummaryCounts(): QaRunInterface {
    $findings = $this->getFindings();
    $counts = [
      self::SEVERITY_HIGH => 0,
      self::SEVERITY_MEDIUM => 0,
      self::SEVERITY_LOW => 0,
    ];

    foreach ($findings as $finding) {
      $severity = $finding['severity'] ?? self::SEVERITY_LOW;
      if (isset($counts[$severity])) {
        $counts[$severity]++;
      }
    }

    $this->set('high_count', $counts[self::SEVERITY_HIGH]);
    $this->set('medium_count', $counts[self::SEVERITY_MEDIUM]);
    $this->set('low_count', $counts[self::SEVERITY_LOW]);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginResults(): array {
    $raw = $this->get('plugin_results')->value;
    if (empty($raw)) {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginResults(array $plugin_results): QaRunInterface {
    $this->set('plugin_results', json_encode($plugin_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginStatus(string $plugin_id): string {
    $pluginResults = $this->getPluginResults();
    return $pluginResults[$plugin_id]['status'] ?? self::STATUS_PENDING;
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginStatus(string $plugin_id, string $status, ?array $findings = NULL, ?string $error = NULL): QaRunInterface {
    $pluginResults = $this->getPluginResults();
    $pluginResults[$plugin_id] = [
      'status' => $status,
      'executed_at' => \Drupal::time()->getRequestTime(),
    ];
    if ($findings !== NULL) {
      $pluginResults[$plugin_id]['findings'] = $findings;
    }
    if ($error !== NULL) {
      $pluginResults[$plugin_id]['error'] = $error;
    }
    return $this->setPluginResults($pluginResults);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginFindings(string $plugin_id): array {
    $pluginResults = $this->getPluginResults();
    return $pluginResults[$plugin_id]['findings'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginError(string $plugin_id): ?string {
    $pluginResults = $this->getPluginResults();
    return $pluginResults[$plugin_id]['error'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function areAllPluginsComplete(array $expected_plugin_ids): bool {
    $pluginResults = $this->getPluginResults();
    foreach ($expected_plugin_ids as $pluginId) {
      $status = $pluginResults[$pluginId]['status'] ?? self::STATUS_PENDING;
      if ($status === self::STATUS_PENDING) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function aggregatePluginFindings(): QaRunInterface {
    $pluginResults = $this->getPluginResults();
    $allFindings = [];
    
    foreach ($pluginResults as $pluginId => $pluginData) {
      if (!empty($pluginData['findings'])) {
        $allFindings = array_merge($allFindings, $pluginData['findings']);
      }
    }
    
    // Get current results and update findings.
    $results = $this->getResults();
    $results['findings'] = $allFindings;
    
    // Rebuild summary.
    $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
    $maxSeverity = 'none';
    $severityOrder = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    foreach ($allFindings as $finding) {
      $severity = $finding['severity'] ?? 'low';
      if (isset($counts[$severity])) {
        $counts[$severity]++;
      }
      if (($severityOrder[$severity] ?? 0) > ($severityOrder[$maxSeverity] ?? 0)) {
        $maxSeverity = $severity;
      }
    }

    $totalFindings = array_sum($counts);
    if ($totalFindings === 0) {
      $summary = 'No issues found.';
    }
    else {
      $parts = [];
      if ($counts['high'] > 0) {
        $parts[] = $counts['high'] . ' high';
      }
      if ($counts['medium'] > 0) {
        $parts[] = $counts['medium'] . ' medium';
      }
      if ($counts['low'] > 0) {
        $parts[] = $counts['low'] . ' low';
      }
      $summary = 'Found ' . implode(', ', $parts) . ' severity issue(s).';
    }

    $results['overall'] = [
      'max_severity' => $maxSeverity,
      'counts' => $counts,
      'summary' => $summary,
    ];
    $results['generated_at'] = date('c');

    return $this->setResults($results);
  }

  /**
   * Loads QA runs for an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string|null $profile_id
   *   Optional profile ID filter.
   *
   * @return array
   *   Array of QaRun entities.
   */
  public static function loadForEntity(string $entity_type_id, string $entity_id, ?string $profile_id = NULL): array {
    $storage = \Drupal::entityTypeManager()->getStorage('qa_run');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->sort('executed_at', 'DESC');

    if ($profile_id) {
      $query->condition('profile_id', $profile_id);
    }

    $ids = $query->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Loads the latest QA run for an entity and profile.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string $profile_id
   *   The profile ID.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface|null
   *   The latest QA run or NULL.
   */
  public static function loadLatest(string $entity_type_id, string $entity_id, string $profile_id): ?QaRunInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('qa_run');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('profile_id', $profile_id)
      ->sort('executed_at', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return $storage->load(reset($ids));
  }

}

