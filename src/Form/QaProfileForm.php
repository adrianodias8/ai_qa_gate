<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\ai_qa_gate\QaReportPluginManager;
use Drupal\ai\AiProviderPluginManager;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for QA Profile entities.
 */
class QaProfileForm extends EntityForm {

  /**
   * Constructs a QaProfileForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\ai_qa_gate\QaReportPluginManager $reportPluginManager
   *   The report plugin manager.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   */
  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The report plugin manager.
   *
   * @var \Drupal\ai_qa_gate\QaReportPluginManager
   */
  protected QaReportPluginManager $reportPluginManager;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface|null
   */
  protected ?ModerationInformationInterface $moderationInformation = NULL;

  /**
   * Constructs a QaProfileForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\ai_qa_gate\QaReportPluginManager $reportPluginManager
   *   The report plugin manager.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler (inherited from EntityForm).
   * @param \Drupal\content_moderation\ModerationInformationInterface|null $moderationInformation
   *   The moderation information service (optional).
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $bundleInfo,
    EntityFieldManagerInterface $entityFieldManager,
    QaReportPluginManager $reportPluginManager,
    AiProviderPluginManager $aiProviderManager,
    ModuleHandlerInterface $moduleHandler,
    ?ModerationInformationInterface $moderationInformation = NULL,
  ) {
    $this->setEntityTypeManager($entityTypeManager);
    $this->bundleInfo = $bundleInfo;
    $this->entityFieldManager = $entityFieldManager;
    $this->reportPluginManager = $reportPluginManager;
    $this->aiProviderManager = $aiProviderManager;
    // EntityForm already has $moduleHandler property, so we can set it directly.
    $this->moduleHandler = $moduleHandler;
    $this->moderationInformation = $moderationInformation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $moderationInformation = NULL;
    if ($container->has('content_moderation.moderation_information')) {
      $moderationInformation = $container->get('content_moderation.moderation_information');
    }
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.qa_report'),
      $container->get('ai.provider'),
      $container->get('module_handler'),
      $moderationInformation,
    );
  }

  /**
   * Gets workflow transitions for an entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID (empty string for all bundles).
   *
   * @return array
   *   Array of transition IDs keyed by transition ID, with labels as values.
   */
  protected function getWorkflowTransitions(string $entity_type_id, string $bundle): array {
    $workflow = $this->getWorkflowForBundle($entity_type_id, $bundle);
    if (!$workflow) {
      return [];
    }
    return $this->extractTransitions($workflow);
  }

  /**
   * Extracts transitions from a workflow.
   *
   * @param \Drupal\workflows\WorkflowInterface $workflow
   *   The workflow entity.
   *
   * @return array
   *   Array of transition IDs keyed by transition ID, with labels as values.
   */
  protected function extractTransitions($workflow): array {
    $transitions = [];
    $typePlugin = $workflow->getTypePlugin();
    
    foreach ($typePlugin->getTransitions() as $transition) {
      $transitionId = $transition->id();
      $fromStates = $transition->from();
      $toState = $transition->to();
      
      // Build a descriptive label.
      $fromLabels = [];
      foreach ($fromStates as $state) {
        $fromLabels[] = $state->label();
      }
      $label = $transition->label();
      if (count($fromLabels) > 0) {
        $label .= ' (' . implode(', ', $fromLabels) . ' → ' . $toState->label() . ')';
      }
      
      $transitions[$transitionId] = $label;
    }
    
    return $transitions;
  }

  /**
   * Gets the workflow for an entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID (empty string for all bundles).
   *
   * @return \Drupal\workflows\WorkflowInterface|null
   *   The workflow or NULL if not found.
   */
  protected function getWorkflowForBundle(string $entity_type_id, string $bundle) {
    if (!$this->moduleHandler->moduleExists('content_moderation') || !$this->moderationInformation) {
      return NULL;
    }

    if (empty($bundle)) {
      // If bundle is empty, find any bundle with a workflow.
      $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
      $entityType = $this->entityTypeManager->getDefinition($entity_type_id);
      
      foreach (array_keys($bundles) as $bundleId) {
        if ($this->moderationInformation->shouldModerateEntitiesOfBundle($entityType, $bundleId)) {
          $workflow = $this->moderationInformation->getWorkflowForEntityTypeAndBundle($entity_type_id, $bundleId);
          if ($workflow) {
            return $workflow;
          }
        }
      }
      return NULL;
    }

    $entityType = $this->entityTypeManager->getDefinition($entity_type_id);
    if ($this->moderationInformation->shouldModerateEntitiesOfBundle($entityType, $bundle)) {
      return $this->moderationInformation->getWorkflowForEntityTypeAndBundle($entity_type_id, $bundle);
    }

    return NULL;
  }

  /**
   * Gets workflow states for an entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID (empty string for all bundles).
   *
   * @return array
   *   Array of state IDs keyed by state ID, with labels as values.
   */
  protected function getWorkflowStates(string $entity_type_id, string $bundle): array {
    $workflow = $this->getWorkflowForBundle($entity_type_id, $bundle);
    if (!$workflow) {
      return [];
    }
    return $this->extractStates($workflow);
  }

  /**
   * Extracts states from a workflow.
   *
   * @param \Drupal\workflows\WorkflowInterface $workflow
   *   The workflow entity.
   *
   * @return array
   *   Array of state IDs keyed by state ID, with labels as values.
   */
  protected function extractStates($workflow): array {
    $states = [];
    $typePlugin = $workflow->getTypePlugin();
    
    foreach ($typePlugin->getStates() as $state) {
      $states[$state->id()] = $state->label();
    }
    
    return $states;
  }

  /**
   * Checks if a workflow exists for the given entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID (empty string for all bundles).
   *
   * @return bool
   *   TRUE if a workflow exists, FALSE otherwise.
   */
  protected function hasWorkflow(string $entity_type_id, string $bundle): bool {
    return $this->getWorkflowForBundle($entity_type_id, $bundle) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile */
    $profile = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $profile->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $profile->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_qa_gate\Entity\QaProfile::load',
      ],
      '#disabled' => !$profile->isNew(),
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $profile->isEnabled(),
    ];

    // Target entity type.
    $entityTypes = $this->getContentEntityTypes();
    $form['target_entity_type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Target entity type'),
      '#options' => $entityTypes,
      '#default_value' => $profile->getTargetEntityTypeId(),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateBundleAndGatingOptions',
        'wrapper' => 'bundle-wrapper',
        'event' => 'change',
      ],
    ];

    // Target bundle.
    $entityTypeId = $form_state->getValue('target_entity_type_id') ?? $profile->getTargetEntityTypeId();
    $bundles = $this->getBundleOptions($entityTypeId);

    $form['bundle_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'bundle-wrapper'],
    ];

    $form['bundle_wrapper']['target_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Target bundle'),
      '#options' => ['' => $this->t('- All bundles -')] + $bundles,
      '#default_value' => $profile->getTargetBundle(),
      '#ajax' => [
        'callback' => '::updateFieldAndGatingOptions',
        'wrapper' => 'fields-wrapper',
        'event' => 'change',
      ],
    ];

    // Fields to analyze.
    // Bundle is nested inside bundle_wrapper in the form.
    $bundle = $form_state->getValue(['bundle_wrapper', 'target_bundle']) ?? $profile->getTargetBundle();
    $fields = $this->getFieldOptions($entityTypeId, $bundle);
    $fieldsConfig = $profile->getFieldsToAnalyze();

    $form['fields_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'fields-wrapper'],
    ];

    $form['fields_wrapper']['fields_to_analyze'] = [
      '#type' => 'details',
      '#title' => $this->t('Fields to analyze'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $selectedFields = array_column($fieldsConfig, 'field_name');

    foreach ($fields as $fieldName => $fieldLabel) {
      $fieldConfig = $this->getFieldConfig($fieldsConfig, $fieldName);

      $form['fields_wrapper']['fields_to_analyze'][$fieldName] = [
        '#type' => 'details',
        '#title' => $fieldLabel,
        '#open' => in_array($fieldName, $selectedFields),
      ];

      $form['fields_wrapper']['fields_to_analyze'][$fieldName]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include this field'),
        '#default_value' => in_array($fieldName, $selectedFields),
      ];

      $form['fields_wrapper']['fields_to_analyze'][$fieldName]['weight'] = [
        '#type' => 'number',
        '#title' => $this->t('Weight'),
        '#default_value' => $fieldConfig['weight'] ?? 0,
        '#min' => -100,
        '#max' => 100,
      ];

      $form['fields_wrapper']['fields_to_analyze'][$fieldName]['include_label'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include field label'),
        '#default_value' => $fieldConfig['include_label'] ?? TRUE,
      ];

      $form['fields_wrapper']['fields_to_analyze'][$fieldName]['strip_html'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Strip HTML'),
        '#default_value' => $fieldConfig['strip_html'] ?? TRUE,
      ];

      $form['fields_wrapper']['fields_to_analyze'][$fieldName]['include_referenced_labels'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include referenced entity labels'),
        '#default_value' => $fieldConfig['include_referenced_labels'] ?? FALSE,
      ];
    }

    // Include meta.
    $includeMeta = $profile->getIncludeMeta();
    $form['include_meta'] = [
      '#type' => 'details',
      '#title' => $this->t('Include metadata'),
      '#tree' => TRUE,
    ];

    $form['include_meta']['include_entity_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Entity label'),
      '#default_value' => $includeMeta['include_entity_label'] ?? TRUE,
    ];

    $form['include_meta']['include_langcode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Language code'),
      '#default_value' => $includeMeta['include_langcode'] ?? TRUE,
    ];

    $form['include_meta']['include_bundle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bundle'),
      '#default_value' => $includeMeta['include_bundle'] ?? TRUE,
    ];

    $form['include_meta']['include_moderation_state'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Moderation state'),
      '#default_value' => $includeMeta['include_moderation_state'] ?? TRUE,
    ];

    $form['include_meta']['include_taxonomy_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy labels'),
      '#default_value' => $includeMeta['include_taxonomy_labels'] ?? FALSE,
    ];

    // Policies.
    $policies = $this->entityTypeManager->getStorage('qa_policy')->loadMultiple();
    $policyOptions = [];
    foreach ($policies as $policy) {
      $policyOptions[$policy->id()] = $policy->label();
    }

    $form['policy_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Policies to inject'),
      '#options' => $policyOptions,
      '#default_value' => $profile->getPolicyIds(),
      '#description' => $this->t('Select policies to inject into the analysis prompt.'),
    ];

    // Reports.
    $reportPlugins = $this->reportPluginManager->getDefinitions();
    $reportsEnabled = $profile->getReportsEnabled();

    $form['reports_enabled'] = [
      '#type' => 'details',
      '#title' => $this->t('Report plugins'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($reportPlugins as $pluginId => $definition) {
      $reportConfig = $this->getReportConfig($reportsEnabled, (string) $pluginId);
      $pluginConfiguration = $reportConfig['configuration'] ?? [];

      $form['reports_enabled'][$pluginId] = [
        '#type' => 'details',
        '#title' => $definition['label'] ?? $pluginId,
        '#description' => $definition['description'] ?? '',
        '#open' => !empty($reportConfig['enabled']),
      ];

      $form['reports_enabled'][$pluginId]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable this report'),
        '#default_value' => !empty($reportConfig['enabled']),
      ];

      // Create plugin instance to get configuration form.
      try {
        /** @var \Drupal\ai_qa_gate\Plugin\QaReport\QaReportPluginInterface $plugin */
        $plugin = $this->reportPluginManager->createInstance($pluginId, $pluginConfiguration);
        $configForm = $plugin->buildConfigurationForm([], $form_state);

        if (!empty($configForm)) {
          $form['reports_enabled'][$pluginId]['configuration'] = [
            '#type' => 'container',
            '#tree' => TRUE,
            '#states' => [
              'visible' => [
                ':input[name="reports_enabled[' . $pluginId . '][enabled]"]' => ['checked' => TRUE],
              ],
            ],
          ];

          foreach ($configForm as $key => $element) {
            // Skip render elements that start with #.
            if (str_starts_with($key, '#')) {
              continue;
            }
            $form['reports_enabled'][$pluginId]['configuration'][$key] = $element;
          }
        }
      }
      catch (\Exception $e) {
        // If plugin instantiation fails, just skip the configuration form.
        $this->messenger()->addWarning($this->t('Could not load configuration for plugin @plugin: @error', [
          '@plugin' => $pluginId,
          '@error' => $e->getMessage(),
        ]));
      }
    }

    // AI settings.
    $aiSettings = $profile->getAiSettings();
    $form['ai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI settings'),
      '#tree' => TRUE,
      '#description' => $this->t('Leave empty to use the default AI provider settings.'),
    ];

    // Get available providers for chat operation.
    $providers = $this->aiProviderManager->getProvidersForOperationType('chat', TRUE);
    $providerOptions = ['' => $this->t('- Use default -')];
    foreach ($providers as $id => $definition) {
      $providerOptions[$id] = $definition['label'];
    }

    $selectedProvider = $form_state->getValue(['ai_settings', 'provider_id']) ?? $aiSettings['provider_id'] ?? '';

    $form['ai_settings']['provider_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => $providerOptions,
      '#default_value' => $selectedProvider,
      '#description' => $this->t('Select an AI provider to use for this profile. Leave empty to use the default.'),
      '#ajax' => [
        'callback' => '::updateModelOptions',
        'wrapper' => 'ai-model-wrapper',
        'event' => 'change',
      ],
    ];

    // Model selection container.
    $form['ai_settings']['model_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-model-wrapper'],
    ];

    // Get models for selected provider.
    $modelOptions = ['' => $this->t('- Use default -')];
    if ($selectedProvider && !empty($providers[$selectedProvider])) {
      try {
        $provider = $this->aiProviderManager->createInstance($selectedProvider);
        $models = $provider->getConfiguredModels('chat');
        if (!empty($models)) {
          $modelOptions = ['' => $this->t('- Use default -')] + $models;
        }
        elseif (!$provider->isUsable('chat')) {
          $this->messenger()->addWarning($this->t('Provider %provider is not properly configured for chat operations.', [
            '%provider' => $selectedProvider,
          ]));
        }
      }
      catch (\Exception $e) {
        // Provider not fully configured, show error message.
        $this->messenger()->addWarning($this->t('Error loading models for provider %provider: %error', [
          '%provider' => $selectedProvider,
          '%error' => $e->getMessage(),
        ]));
      }
    }

    $form['ai_settings']['model_wrapper']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => $modelOptions,
      '#empty_option' => $this->t('- Use default -'),
      '#default_value' => $aiSettings['model'] ?? '',
      '#description' => $this->t('Select a model to use. Leave empty to use the default model for the selected provider.'),
      '#disabled' => empty($selectedProvider),
    ];

    $form['ai_settings']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#default_value' => $aiSettings['temperature'] ?? 0.2,
      '#min' => 0,
      '#max' => 2,
      '#step' => 0.1,
      '#description' => $this->t('Controls randomness in AI responses. Lower values (0.0-0.3) produce more focused, deterministic outputs ideal for structured analysis. Higher values (0.7-1.0) increase creativity but reduce consistency. Recommended: 0.2 for QA analysis.'),
    ];

    $form['ai_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tokens'),
      '#default_value' => $aiSettings['max_tokens'] ?? 3000,
      '#min' => 100,
      '#max' => 100000,
      '#step' => 100,
      '#description' => $this->t('Maximum number of tokens in the AI response. For QA analysis with structured JSON output, 2000-4000 tokens is typically sufficient. Lower values may truncate findings. Higher values increase cost but allow more detailed analysis. Recommended: 3000 tokens.'),
    ];

    // Execution settings.
    $executionSettings = $profile->getExecutionSettings();
    $form['execution_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution settings'),
      '#tree' => TRUE,
    ];

    $form['execution_settings']['run_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Run mode'),
      '#options' => [
        'queue' => $this->t('Queue (background)'),
        'sync' => $this->t('Synchronous (immediate)'),
      ],
      '#default_value' => $executionSettings['run_mode'] ?? 'queue',
    ];

    $form['execution_settings']['cache_ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $executionSettings['cache_ttl_seconds'] ?? 0,
      '#min' => 0,
      '#description' => $this->t('How long to cache results. 0 means always run fresh analysis.'),
    ];

    // Gating settings.
    $form['gating_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Gating settings'),
      '#tree' => TRUE,
      '#description' => $this->t('Configure content moderation gating. Only applies if Content Moderation module is enabled and a workflow is configured for the selected entity type/bundle.'),
    ];

    // Gating settings wrapper for AJAX updates.
    $form['gating_settings']['gating_wrapper'] = $this->buildGatingSettings($form, $form_state);

    return $form;
  }

  /**
   * Ajax callback to update bundle options.
   */
  public function updateBundleOptions(array &$form, FormStateInterface $form_state): array {
    return $form['bundle_wrapper'];
  }

  /**
   * Ajax callback to update bundle and gating options.
   */
  public function updateBundleAndGatingOptions(array &$form, FormStateInterface $form_state) {
    // Rebuild gating settings when entity type changes.
    $form['gating_settings']['gating_wrapper'] = $this->buildGatingSettings($form, $form_state);
    
    // Add command to update gating wrapper.
    $response = new AjaxResponse();
    $gatingWrapper = $form['gating_settings']['gating_wrapper'];
    $gatingWrapper['#printed'] = FALSE;
    $renderedGating = \Drupal::service('renderer')->render($gatingWrapper);
    $response->addCommand(new ReplaceCommand('#gating-wrapper', $renderedGating));
    
    $bundleWrapper = $form['bundle_wrapper'];
    $bundleWrapper['#printed'] = FALSE;
    $renderedBundle = \Drupal::service('renderer')->render($bundleWrapper);
    $response->addCommand(new ReplaceCommand('#bundle-wrapper', $renderedBundle));
    
    return $response;
  }

  /**
   * Ajax callback to update field options.
   */
  public function updateFieldOptions(array &$form, FormStateInterface $form_state): array {
    return $form['fields_wrapper'];
  }

  /**
   * Ajax callback to update field and gating options.
   */
  public function updateFieldAndGatingOptions(array &$form, FormStateInterface $form_state) {
    // Rebuild gating settings when bundle changes.
    $form['gating_settings']['gating_wrapper'] = $this->buildGatingSettings($form, $form_state);
    
    // Add command to update gating wrapper.
    $response = new AjaxResponse();
    $gatingWrapper = $form['gating_settings']['gating_wrapper'];
    $gatingWrapper['#printed'] = FALSE;
    $renderedGating = \Drupal::service('renderer')->render($gatingWrapper);
    $response->addCommand(new ReplaceCommand('#gating-wrapper', $renderedGating));
    
    $fieldsWrapper = $form['fields_wrapper'];
    $fieldsWrapper['#printed'] = FALSE;
    $renderedFields = \Drupal::service('renderer')->render($fieldsWrapper);
    $response->addCommand(new ReplaceCommand('#fields-wrapper', $renderedFields));
    
    return $response;
  }

  /**
   * Ajax callback to update gating options.
   */
  public function updateGatingOptions(array &$form, FormStateInterface $form_state): array {
    return $form['gating_settings']['gating_wrapper'];
  }

  /**
   * Builds the gating settings wrapper.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The gating wrapper form element.
   */
  protected function buildGatingSettings(array &$form, FormStateInterface $form_state): array {
    /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile */
    $profile = $this->entity;

    // Use getUserInput for AJAX callbacks since getValue may not have the
    // dynamically rebuilt form section values yet.
    $userInput = $form_state->getUserInput();

    $entityTypeId = $userInput['target_entity_type_id'] ?? $form_state->getValue('target_entity_type_id') ?? $profile->getTargetEntityTypeId();
    // Bundle is nested inside bundle_wrapper in the form.
    $bundle = $userInput['bundle_wrapper']['target_bundle'] ?? $form_state->getValue(['bundle_wrapper', 'target_bundle']) ?? $profile->getTargetBundle();
    $gatingSettings = $profile->getGatingSettings();
    
    $wrapper = [
      '#type' => 'container',
      '#attributes' => ['id' => 'gating-wrapper'],
    ];

    // Debug info - always visible.
    $workflow = $this->getWorkflowForBundle($entityTypeId, $bundle);
    $debugInfo = $this->t('Entity type: @entity_type, Bundle: @bundle, Workflow: @workflow', [
      '@entity_type' => $entityTypeId,
      '@bundle' => $bundle ?: '(all)',
      '@workflow' => $workflow ? $workflow->label() : 'NOT FOUND',
    ]);
    $wrapper['debug_info'] = [
      '#type' => 'markup',
      '#markup' => '<p><small><em>' . $debugInfo . '</em></small></p>',
    ];

    $hasWorkflow = $workflow !== NULL;

    if (!$hasWorkflow) {
      $wrapper['no_workflow_message'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('Gating is only available when a workflow is enabled for the selected entity type and bundle. Please configure a workflow for this content type first.') . '</div>',
      ];
      $wrapper['gating_enabled'] = [
        '#type' => 'value',
        '#value' => FALSE,
      ];
    }
    else {
      // Get gating_enabled from user input (for AJAX), then form state, then saved settings.
      $gatingEnabled = NULL;
      if (isset($userInput['gating_settings']['gating_wrapper']['gating_enabled'])) {
        $gatingEnabled = !empty($userInput['gating_settings']['gating_wrapper']['gating_enabled']);
      }
      elseif ($form_state->hasValue(['gating_settings', 'gating_wrapper', 'gating_enabled'])) {
        $gatingEnabled = !empty($form_state->getValue(['gating_settings', 'gating_wrapper', 'gating_enabled']));
      }
      else {
        $gatingEnabled = $gatingSettings['gating_enabled'] ?? FALSE;
      }

      $wrapper['gating_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable gating'),
        '#default_value' => $gatingEnabled,
        '#description' => $this->t('Block moderation transitions based on QA findings.'),
        '#ajax' => [
          'callback' => '::updateGatingOptions',
          'wrapper' => 'gating-wrapper',
          'event' => 'change',
        ],
      ];

      // Get severity_threshold from user input first for AJAX callbacks.
      $severityThreshold = $userInput['gating_settings']['gating_wrapper']['severity_threshold']
        ?? $gatingSettings['severity_threshold']
        ?? 'high';

      $wrapper['severity_threshold'] = [
        '#type' => 'select',
        '#title' => $this->t('Severity threshold'),
        '#options' => [
          'high' => $this->t('High (block only high severity)'),
          'medium' => $this->t('Medium (block medium and high)'),
          'low' => $this->t('Low (block all severities)'),
        ],
        '#default_value' => $severityThreshold,
        '#states' => [
          'visible' => [
            ':input[name="gating_settings[gating_wrapper][gating_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      // Get available transitions from workflow (workflow already retrieved above).
      if ($workflow) {
        $transitions = $this->extractTransitions($workflow);
        
        // Debug: show transitions found.
        $transitionDebug = !empty($transitions) ? implode(', ', array_keys($transitions)) : 'NONE';
        $wrapper['transitions_debug'] = [
          '#type' => 'markup',
          '#markup' => '<p><em>Transitions found: ' . $transitionDebug . '</em></p>',
        ];
        
        // Show workflow info.
        $wrapper['workflow_info'] = [
          '#type' => 'markup',
          '#markup' => '<p class="description">' . $this->t('Using workflow: <strong>@workflow</strong>', ['@workflow' => $workflow->label()]) . '</p>',
          '#states' => [
            'visible' => [
              ':input[name="gating_settings[gating_wrapper][gating_enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
      else {
        $transitions = [];
      }

      // Get block_transition_ids from user input first for AJAX callbacks.
      $blockTransitionIds = $gatingSettings['block_transition_ids'] ?? [];
      if (!is_array($blockTransitionIds)) {
        $blockTransitionIds = !empty($blockTransitionIds) ? explode("\n", $blockTransitionIds) : [];
      }

      // Check user input for transition checkboxes (for AJAX rebuilds).
      $userTransitions = $userInput['gating_settings']['gating_wrapper']['transitions_fieldset'] ?? NULL;
      if ($userTransitions !== NULL && is_array($userTransitions)) {
        // Rebuild blockTransitionIds from user input.
        $blockTransitionIds = [];
        foreach ($userTransitions as $key => $value) {
          if ($key !== 'description' && !empty($value) && $value !== '0') {
            $blockTransitionIds[] = $key;
          }
        }
      }

      if (!empty($transitions)) {
        $defaultValue = [];
        if (!empty($blockTransitionIds)) {
          $defaultValue = array_combine($blockTransitionIds, $blockTransitionIds);
        }
        
        // Use a fieldset to wrap the checkboxes for better rendering.
        $wrapper['transitions_fieldset'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Apply gating to these transitions'),
        ];
        
        foreach ($transitions as $transitionId => $transitionLabel) {
          $wrapper['transitions_fieldset'][$transitionId] = [
            '#type' => 'checkbox',
            '#title' => $transitionLabel,
            '#default_value' => in_array($transitionId, $blockTransitionIds, TRUE),
            '#return_value' => $transitionId,
          ];
        }
        
        $wrapper['transitions_fieldset']['description'] = [
          '#type' => 'markup',
          '#markup' => '<p class="description">' . $this->t('Select which workflow transitions require a passing QA review. For example, select "Publish" to require QA review before content can be published.') . '</p>',
        ];
      }
      else {
        $wrapper['no_transitions_message'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--warning">' . $this->t('No transitions found in the workflow.') . '</div>',
          '#states' => [
            'visible' => [
              ':input[name="gating_settings[gating_wrapper][gating_enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
        $wrapper['block_transition_ids'] = [
          '#type' => 'value',
          '#value' => [],
        ];
      }

      // Get require_acknowledgement from user input first for AJAX callbacks.
      $requireAcknowledgement = NULL;
      if (isset($userInput['gating_settings']['gating_wrapper']['require_acknowledgement'])) {
        $requireAcknowledgement = !empty($userInput['gating_settings']['gating_wrapper']['require_acknowledgement']);
      }
      else {
        $requireAcknowledgement = $gatingSettings['require_acknowledgement'] ?? FALSE;
      }

      $wrapper['require_acknowledgement'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Require acknowledgement'),
        '#default_value' => $requireAcknowledgement,
        '#states' => [
          'visible' => [
            ':input[name="gating_settings[gating_wrapper][gating_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return $wrapper;
  }

  /**
   * Ajax callback to update model options based on selected provider.
   */
  public function updateModelOptions(array &$form, FormStateInterface $form_state): array {
    // Get the selected provider from user input (for AJAX, use getUserInput).
    $userInput = $form_state->getUserInput();
    $selectedProvider = $userInput['ai_settings']['provider_id'] ?? '';

    // Get the current model value from user input.
    $currentModel = $userInput['ai_settings']['model_wrapper']['model'] ?? $userInput['ai_settings']['model'] ?? '';

    // If no provider is selected, return empty model container.
    if (empty($selectedProvider)) {
      $form['ai_settings']['model_wrapper']['model']['#options'] = ['' => $this->t('- Use default -')];
      $form['ai_settings']['model_wrapper']['model']['#empty_option'] = $this->t('- Use default -');
      $form['ai_settings']['model_wrapper']['model']['#value'] = '';
      $form['ai_settings']['model_wrapper']['model']['#disabled'] = TRUE;
      $form_state->setValue(['ai_settings', 'model_wrapper', 'model'], '');
      return $form['ai_settings']['model_wrapper'];
    }

    // Get the models for this provider.
    $models = [];
    try {
      $provider = $this->aiProviderManager->createInstance($selectedProvider);
      $models = $provider->getConfiguredModels('chat');
      if (empty($models) && !$provider->isUsable('chat')) {
        $this->messenger()->addWarning($this->t('Provider %provider is not properly configured for chat operations.', [
          '%provider' => $selectedProvider,
        ]));
      }
      elseif (empty($models)) {
        $this->messenger()->addWarning($this->t('No models available for provider %provider. Please configure enabled models in the provider settings.', [
          '%provider' => $selectedProvider,
        ]));
      }
    }
    catch (\Exception $e) {
      // Provider not fully configured or error getting models.
      $this->messenger()->addError($this->t('Error loading models for provider %provider: %error', [
        '%provider' => $selectedProvider,
        '%error' => $e->getMessage(),
      ]));
    }

    // Build model options with empty option first.
    $modelOptions = ['' => $this->t('- Use default -')] + $models;

    // If we have a current model value, check if it's still valid for the new provider.
    if ($currentModel && !isset($modelOptions[$currentModel])) {
      // If the current model is not valid for the new provider, clear it.
      $currentModel = '';
      unset($userInput['ai_settings']['model_wrapper']['model']);
      $form_state->setUserInput($userInput);
    }

    // Update the model select element - ensure it exists first.
    if (!isset($form['ai_settings']['model_wrapper']['model'])) {
      $form['ai_settings']['model_wrapper']['model'] = [
        '#type' => 'select',
        '#title' => $this->t('Model'),
      ];
    }

    $form['ai_settings']['model_wrapper']['model']['#options'] = $modelOptions;
    $form['ai_settings']['model_wrapper']['model']['#empty_option'] = $this->t('- Use default -');
    $form['ai_settings']['model_wrapper']['model']['#value'] = $currentModel;
    $form['ai_settings']['model_wrapper']['model']['#disabled'] = FALSE;

    // Ensure the form state maintains the model value.
    $form_state->setValue(['ai_settings', 'model_wrapper', 'model'], $currentModel);

    return $form['ai_settings']['model_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate that at least one field is selected.
    $fields = $form_state->getValue('fields_to_analyze') ?? [];
    $hasField = FALSE;
    foreach ($fields as $config) {
      if (!empty($config['enabled'])) {
        $hasField = TRUE;
        break;
      }
    }

    if (!$hasField) {
      $form_state->setErrorByName('fields_to_analyze', $this->t('Please select at least one field to analyze.'));
    }

    // Validate that at least one report is enabled.
    $reports = $form_state->getValue('reports_enabled') ?? [];
    $hasReport = FALSE;
    foreach ($reports as $config) {
      if (!empty($config['enabled'])) {
        $hasReport = TRUE;
        break;
      }
    }

    if (!$hasReport) {
      $form_state->setErrorByName('reports_enabled', $this->t('Please enable at least one report plugin.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile */
    $profile = $this->entity;

    // Process fields_to_analyze.
    $fieldsRaw = $form_state->getValue('fields_to_analyze') ?? [];
    $fields = [];
    foreach ($fieldsRaw as $fieldName => $config) {
      if (!empty($config['enabled'])) {
        $fields[] = [
          'field_name' => $fieldName,
          'weight' => (int) ($config['weight'] ?? 0),
          'include_label' => !empty($config['include_label']),
          'include_referenced_labels' => !empty($config['include_referenced_labels']),
          'strip_html' => !empty($config['strip_html']),
        ];
      }
    }
    $profile->set('fields_to_analyze', $fields);

    // Process policy_ids.
    $policyIds = array_filter($form_state->getValue('policy_ids') ?? []);
    $profile->set('policy_ids', array_values($policyIds));

    // Process reports_enabled.
    $reportsRaw = $form_state->getValue('reports_enabled') ?? [];
    $reports = [];
    foreach ($reportsRaw as $pluginId => $config) {
      // Extract plugin-specific configuration.
      $pluginConfig = [];
      if (!empty($config['configuration']) && is_array($config['configuration'])) {
        $pluginConfig = $config['configuration'];
      }

      $reports[] = [
        'plugin_id' => $pluginId,
        'enabled' => !empty($config['enabled']),
        'configuration' => $pluginConfig,
      ];
    }
    $profile->set('reports_enabled', $reports);

    // Process AI settings.
    $aiSettings = $form_state->getValue('ai_settings') ?? [];
    // Model may be nested under model_wrapper or directly under ai_settings.
    $model = $aiSettings['model_wrapper']['model'] ?? $aiSettings['model'] ?? NULL;
    $profile->set('ai_settings', [
      'provider_id' => !empty($aiSettings['provider_id']) ? $aiSettings['provider_id'] : NULL,
      'model' => !empty($model) ? $model : NULL,
      'temperature' => isset($aiSettings['temperature']) && $aiSettings['temperature'] !== '' ? (float) $aiSettings['temperature'] : 0.2,
      'max_tokens' => isset($aiSettings['max_tokens']) && $aiSettings['max_tokens'] !== '' ? (int) $aiSettings['max_tokens'] : 3000,
    ]);

    // Process gating settings.
    $gatingSettings = $form_state->getValue('gating_settings') ?? [];
    $gatingWrapper = $gatingSettings['gating_wrapper'] ?? [];
    
    // Handle transition IDs from individual checkboxes inside fieldset.
    $transitionIds = [];
    if (isset($gatingWrapper['transitions_fieldset']) && is_array($gatingWrapper['transitions_fieldset'])) {
      foreach ($gatingWrapper['transitions_fieldset'] as $key => $value) {
        // Skip non-transition keys like 'description'.
        if ($key === 'description') {
          continue;
        }
        // If checkbox is checked, the value will be the transition ID.
        if (!empty($value) && $value !== '0') {
          $transitionIds[] = $key;
        }
      }
    }
    
    $profile->set('gating_settings', [
      'gating_enabled' => !empty($gatingWrapper['gating_enabled']),
      'severity_threshold' => $gatingWrapper['severity_threshold'] ?? 'high',
      'block_transition_ids' => array_values($transitionIds),
      'require_acknowledgement' => !empty($gatingWrapper['require_acknowledgement']),
      'acknowledgement_field' => NULL,
    ]);

    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('QA Profile %label has been created.', [
        '%label' => $profile->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('QA Profile %label has been updated.', [
        '%label' => $profile->label(),
      ]));
    }

    $form_state->setRedirectUrl($profile->toUrl('collection'));

    return $status;
  }

  /**
   * Gets content entity types.
   *
   * @return array
   *   Array of entity type labels.
   */
  protected function getContentEntityTypes(): array {
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->getGroup() === 'content') {
        $types[$id] = $definition->getLabel();
      }
    }
    asort($types);
    return $types;
  }

  /**
   * Gets bundle options for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Array of bundle labels.
   */
  protected function getBundleOptions(string $entity_type_id): array {
    $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
    $options = [];
    foreach ($bundles as $bundle => $info) {
      $options[$bundle] = $info['label'];
    }
    asort($options);
    return $options;
  }

  /**
   * Gets field options for an entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string|null $bundle
   *   The bundle.
   *
   * @return array
   *   Array of field labels.
   */
  protected function getFieldOptions(string $entity_type_id, ?string $bundle): array {
    $options = [];

    if (empty($bundle)) {
      // Get fields common to all bundles or just pick the first bundle.
      $bundles = array_keys($this->bundleInfo->getBundleInfo($entity_type_id));
      $bundle = reset($bundles) ?: $entity_type_id;
    }

    try {
      $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
      foreach ($definitions as $fieldName => $definition) {
        // Skip base fields that aren't useful for content analysis.
        $fieldType = $definition->getType();
        $analyzableTypes = [
          'text',
          'text_long',
          'text_with_summary',
          'string',
          'string_long',
          'entity_reference',
          'entity_reference_revisions',
          'link',
          'list_string',
        ];

        if (in_array($fieldType, $analyzableTypes, TRUE)) {
          $options[$fieldName] = $definition->getLabel() . ' (' . $fieldName . ')';
        }
      }
    }
    catch (\Exception $e) {
      // Ignore errors for non-existent bundles.
    }

    asort($options);
    return $options;
  }

  /**
   * Gets field config from array.
   */
  protected function getFieldConfig(array $fieldsConfig, string $fieldName): array {
    foreach ($fieldsConfig as $config) {
      if (($config['field_name'] ?? '') === $fieldName) {
        return $config;
      }
    }
    return [];
  }

  /**
   * Gets report config from array.
   */
  protected function getReportConfig(array $reportsEnabled, string $pluginId): array {
    foreach ($reportsEnabled as $config) {
      if (($config['plugin_id'] ?? '') === $pluginId) {
        return $config;
      }
    }
    return [];
  }

}

