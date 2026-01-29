<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the QA Profile config entity.
 *
 * @ConfigEntityType(
 *   id = "qa_profile",
 *   label = @Translation("QA Profile"),
 *   label_collection = @Translation("QA Profiles"),
 *   label_singular = @Translation("QA profile"),
 *   label_plural = @Translation("QA profiles"),
 *   label_count = @PluralTranslation(
 *     singular = "@count QA profile",
 *     plural = "@count QA profiles",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_qa_gate\QaProfileListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_qa_gate\Form\QaProfileForm",
 *       "edit" = "Drupal\ai_qa_gate\Form\QaProfileForm",
 *       "delete" = "Drupal\ai_qa_gate\Form\QaProfileDeleteForm",
 *     },
 *   },
 *   config_prefix = "qa_profile",
 *   admin_permission = "administer ai qa gate",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "enabled",
 *     "target_entity_type_id",
 *     "target_bundle",
 *     "fields_to_analyze",
 *     "include_meta",
 *     "policy_ids",
 *     "reports_enabled",
 *     "ai_settings",
 *     "execution_settings",
 *     "gating_settings",
 *     "access_settings",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai-qa-gate/profiles",
 *     "add-form" = "/admin/config/ai-qa-gate/profiles/add",
 *     "edit-form" = "/admin/config/ai-qa-gate/profiles/{qa_profile}/edit",
 *     "delete-form" = "/admin/config/ai-qa-gate/profiles/{qa_profile}/delete",
 *   },
 * )
 */
class QaProfile extends ConfigEntityBase implements QaProfileInterface {

  /**
   * The profile ID.
   *
   * @var string
   */
  protected string $id;

  /**
   * The profile label.
   *
   * @var string
   */
  protected string $label;

  /**
   * Whether the profile is enabled.
   *
   * @var bool
   */
  protected bool $enabled = TRUE;

  /**
   * The target entity type ID.
   *
   * @var string
   */
  protected string $target_entity_type_id = 'node';

  /**
   * The target bundle (empty for all bundles).
   *
   * @var string
   */
  protected string $target_bundle = '';

  /**
   * Fields to analyze configuration.
   *
   * @var array
   */
  protected array $fields_to_analyze = [];

  /**
   * Include meta configuration.
   *
   * @var array
   */
  protected array $include_meta = [
    'include_entity_label' => TRUE,
    'include_langcode' => TRUE,
    'include_bundle' => TRUE,
    'include_moderation_state' => TRUE,
    'include_taxonomy_labels' => FALSE,
  ];

  /**
   * Policy IDs to inject.
   *
   * @var array
   */
  protected array $policy_ids = [];

  /**
   * Enabled reports configuration.
   *
   * @var array
   */
  protected array $reports_enabled = [];

  /**
   * AI settings.
   *
   * @var array
   */
  protected array $ai_settings = [
    'provider_id' => NULL,
    'model' => NULL,
    'temperature' => NULL,
    'max_tokens' => NULL,
  ];

  /**
   * Execution settings.
   *
   * @var array
   */
  protected array $execution_settings = [
    'run_mode' => 'queue',
    'cache_ttl_seconds' => 0,
  ];

  /**
   * Gating settings.
   *
   * @var array
   */
  protected array $gating_settings = [
    'gating_enabled' => FALSE,
    'severity_threshold' => 'high',
    'apply_to_states' => [],
    'block_transition_ids' => [],
    'require_acknowledgement' => FALSE,
    'acknowledgement_field' => NULL,
  ];

  /**
   * Access settings.
   *
   * @var array
   */
  protected array $access_settings = [
    'roles_allowed_to_run' => [],
  ];

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return $this->enabled;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return $this->target_entity_type_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetBundle(): string {
    return $this->target_bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldsToAnalyze(): array {
    return $this->fields_to_analyze;
  }

  /**
   * {@inheritdoc}
   */
  public function getIncludeMeta(): array {
    return $this->include_meta + [
      'include_entity_label' => TRUE,
      'include_langcode' => TRUE,
      'include_bundle' => TRUE,
      'include_moderation_state' => TRUE,
      'include_taxonomy_labels' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPolicyIds(): array {
    return $this->policy_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function getReportsEnabled(): array {
    return $this->reports_enabled;
  }

  /**
   * {@inheritdoc}
   */
  public function getAiSettings(): array {
    return $this->ai_settings + [
      'provider_id' => NULL,
      'model' => NULL,
      'temperature' => NULL,
      'max_tokens' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getExecutionSettings(): array {
    return $this->execution_settings + [
      'run_mode' => 'queue',
      'cache_ttl_seconds' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getGatingSettings(): array {
    return $this->gating_settings + [
      'gating_enabled' => FALSE,
      'severity_threshold' => 'high',
      'apply_to_states' => [],
      'block_transition_ids' => [],
      'require_acknowledgement' => FALSE,
      'acknowledgement_field' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessSettings(): array {
    return $this->access_settings + [
      'roles_allowed_to_run' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(string $entity_type_id, string $bundle): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }

    if ($this->target_entity_type_id !== $entity_type_id) {
      return FALSE;
    }

    // Empty target_bundle means all bundles (wildcard).
    if (empty($this->target_bundle)) {
      return TRUE;
    }

    return $this->target_bundle === $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getRunMode(): string {
    $settings = $this->getExecutionSettings();
    return $settings['run_mode'] ?? 'queue';
  }

  /**
   * {@inheritdoc}
   */
  public function isGatingEnabled(): bool {
    $settings = $this->getGatingSettings();
    return !empty($settings['gating_enabled']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSeverityThreshold(): string {
    $settings = $this->getGatingSettings();
    return $settings['severity_threshold'] ?? 'high';
  }

  /**
   * {@inheritdoc}
   */
  public function getApplyToStates(): array {
    $settings = $this->getGatingSettings();
    return $settings['apply_to_states'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getBlockedTransitionIds(): array {
    $settings = $this->getGatingSettings();
    return $settings['block_transition_ids'] ?? [];
  }

  /**
   * Gets enabled report plugin IDs.
   *
   * @return array
   *   Array of enabled plugin IDs.
   */
  public function getEnabledReportPluginIds(): array {
    $enabled = [];
    foreach ($this->reports_enabled as $report) {
      if (!empty($report['enabled'])) {
        $enabled[] = $report['plugin_id'];
      }
    }
    return $enabled;
  }

  /**
   * Gets configuration for a specific report plugin.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return array
   *   The plugin configuration.
   */
  public function getReportPluginConfiguration(string $plugin_id): array {
    foreach ($this->reports_enabled as $report) {
      if ($report['plugin_id'] === $plugin_id) {
        return $report['configuration'] ?? [];
      }
    }
    return [];
  }

}

