<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\UserInterface;

/**
 * Defines the QA Finding content entity.
 *
 * Stores individual findings from QA report plugins in a dedicated table
 * for reliable persistence and querying.
 *
 * @ContentEntityType(
 *   id = "qa_finding",
 *   label = @Translation("QA Finding"),
 *   label_collection = @Translation("QA Findings"),
 *   label_singular = @Translation("QA finding"),
 *   label_plural = @Translation("QA findings"),
 *   label_count = @PluralTranslation(
 *     singular = "@count QA finding",
 *     plural = "@count QA findings",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "acknowledge" = "Drupal\ai_qa_gate\Form\AcknowledgeFindingForm",
 *     },
 *   },
 *   base_table = "qa_finding",
 *   admin_permission = "administer ai qa gate",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class QaFinding extends ContentEntityBase implements QaFindingInterface {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['qa_run_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('QA Run'))
      ->setDescription(t('The QA run this finding belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'qa_run')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ]);

    $fields['plugin_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Plugin ID'))
      ->setDescription(t('The plugin that generated this finding.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 1,
      ]);

    $fields['category'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Category'))
      ->setDescription(t('The finding category.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ]);

    $fields['severity'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Severity'))
      ->setDescription(t('The severity level (low, medium, high).'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->setDefaultValue(self::SEVERITY_LOW)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ]);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The finding title.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 512)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 4,
      ]);

    $fields['explanation'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Explanation'))
      ->setDescription(t('Detailed explanation of the finding.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 5,
      ]);

    $fields['evidence_excerpt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Evidence Excerpt'))
      ->setDescription(t('The content excerpt that triggered this finding.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 6,
      ]);

    $fields['evidence_field'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Evidence Field'))
      ->setDescription(t('The field name where the evidence was found.'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 7,
      ]);

    $fields['suggested_fix'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Suggested Fix'))
      ->setDescription(t('The suggested fix for this finding.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 8,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time when the finding was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 9,
      ]);

    $fields['acknowledged'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Acknowledged'))
      ->setDescription(t('Whether this finding has been acknowledged.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 10,
      ]);

    $fields['acknowledged_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Acknowledged by'))
      ->setDescription(t('The user who acknowledged this finding.'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => 11,
      ]);

    $fields['acknowledged_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Acknowledged at'))
      ->setDescription(t('The time when the finding was acknowledged.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 12,
      ]);

    $fields['acknowledgement_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Acknowledgement note'))
      ->setDescription(t('Optional note explaining why this finding was acknowledged.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 13,
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getQaRunId(): int {
    return (int) $this->get('qa_run_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->get('plugin_id')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string {
    return $this->get('category')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getSeverity(): string {
    return $this->get('severity')->value ?? self::SEVERITY_LOW;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return $this->get('title')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getExplanation(): string {
    return $this->get('explanation')->value ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getEvidenceExcerpt(): ?string {
    return $this->get('evidence_excerpt')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEvidenceField(): ?string {
    return $this->get('evidence_field')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSuggestedFix(): ?string {
    return $this->get('suggested_fix')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'id' => $this->id(),
      'plugin_id' => $this->getPluginId(),
      'category' => $this->getCategory(),
      'severity' => $this->getSeverity(),
      'title' => $this->getTitle(),
      'explanation' => $this->getExplanation(),
      'evidence' => [
        'excerpt' => $this->getEvidenceExcerpt(),
        'field' => $this->getEvidenceField(),
      ],
      'suggested_fix' => $this->getSuggestedFix(),
      'acknowledged' => $this->isAcknowledged(),
      'acknowledged_by' => $this->getAcknowledgedBy() ? $this->getAcknowledgedBy()->id() : NULL,
      'acknowledged_at' => $this->getAcknowledgedAt(),
      'acknowledgement_note' => $this->getAcknowledgementNote(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function loadForRun(int $qa_run_id, ?string $plugin_id = NULL): array {
    $storage = \Drupal::entityTypeManager()->getStorage('qa_finding');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('qa_run_id', $qa_run_id)
      ->sort('created', 'ASC');

    if ($plugin_id !== NULL) {
      $query->condition('plugin_id', $plugin_id);
    }

    $ids = $query->execute();
    
    \Drupal::logger('ai_qa_gate')->debug('loadForRun: qa_run_id=@run_id, plugin_id=@plugin, found_ids=@ids', [
      '@run_id' => $qa_run_id,
      '@plugin' => $plugin_id ?? 'NULL',
      '@ids' => implode(',', $ids),
    ]);
    
    return $storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public static function deleteForPlugin(int $qa_run_id, string $plugin_id): void {
    $storage = \Drupal::entityTypeManager()->getStorage('qa_finding');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('qa_run_id', $qa_run_id)
      ->condition('plugin_id', $plugin_id)
      ->execute();

    if (!empty($ids)) {
      $entities = $storage->loadMultiple($ids);
      $storage->delete($entities);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isAcknowledged(): bool {
    return (bool) $this->get('acknowledged')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAcknowledgedBy(): ?UserInterface {
    if (!$this->isAcknowledged()) {
      return NULL;
    }
    $user = $this->get('acknowledged_by')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAcknowledgedAt(): ?int {
    if (!$this->isAcknowledged()) {
      return NULL;
    }
    $value = $this->get('acknowledged_at')->value;
    return $value ? (int) $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAcknowledgementNote(): ?string {
    return $this->get('acknowledgement_note')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function acknowledge(UserInterface $user, ?string $note = NULL): self {
    $this->set('acknowledged', TRUE);
    $this->set('acknowledged_by', $user->id());
    $this->set('acknowledged_at', \Drupal::time()->getRequestTime());
    if ($note !== NULL) {
      $this->set('acknowledgement_note', $note);
    }
    return $this;
  }

  /**
   * Creates findings from an array of finding data.
   *
   * @param int $qa_run_id
   *   The QA run ID.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $findings
   *   Array of finding data from the AI response.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaFindingInterface[]
   *   Array of created findings.
   */
  public static function createFromArray(int $qa_run_id, string $plugin_id, array $findings): array {
    $storage = \Drupal::entityTypeManager()->getStorage('qa_finding');
    $created = [];

    \Drupal::logger('ai_qa_gate')->debug('createFromArray called with qa_run_id=@id, plugin=@plugin, findings_count=@count', [
      '@id' => $qa_run_id,
      '@plugin' => $plugin_id,
      '@count' => count($findings),
    ]);

    foreach ($findings as $findingData) {
      /** @var \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding */
      $finding = $storage->create([
        'qa_run_id' => ['target_id' => $qa_run_id],
        'plugin_id' => $plugin_id,
        'category' => $findingData['category'] ?? 'other',
        'severity' => $findingData['severity'] ?? self::SEVERITY_LOW,
        'title' => $findingData['title'] ?? 'Untitled',
        'explanation' => $findingData['explanation'] ?? '',
        'evidence_excerpt' => $findingData['evidence']['excerpt'] ?? NULL,
        'evidence_field' => $findingData['evidence']['field'] ?? NULL,
        'suggested_fix' => $findingData['suggested_fix'] ?? NULL,
      ]);
      $finding->save();
      $created[] = $finding;
    }

    return $created;
  }

}

