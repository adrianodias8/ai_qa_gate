<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for converting a QA finding into an ai_context example or exclusion.
 */
class ConvertToExampleForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The finding being converted.
   *
   * @var \Drupal\ai_qa_gate\Entity\QaFindingInterface|null
   */
  protected ?QaFindingInterface $finding = NULL;

  /**
   * The agent ID associated with this finding's plugin.
   *
   * @var string
   */
  protected string $agentId = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_qa_gate_convert_to_example_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, QaFindingInterface $qa_finding = NULL): array {
    $this->finding = $qa_finding;

    if (!$this->finding) {
      $this->messenger()->addError($this->t('The requested finding could not be loaded.'));
      return $form;
    }

    // Determine the agent ID. In the agent-centric model, the finding's
    // plugin_id field stores the agent_id (since agents are the primary key).
    // Fall back to looking up agents by plugin_id in third_party_settings.
    $findingPluginId = $this->finding->getPluginId();
    $agentStorage = $this->entityTypeManager->getStorage('ai_agent');

    // First, try to load directly as an agent ID.
    $agentEntity = $agentStorage->load($findingPluginId);
    if ($agentEntity) {
      $this->agentId = (string) $agentEntity->id();
    }
    else {
      // Fallback: search agents for matching plugin_id in third_party_settings.
      $allAgents = $agentStorage->loadMultiple();
      foreach ($allAgents as $agent) {
        $agentPluginId = $agent->getThirdPartySetting('ai_qa_gate', 'qa_report_plugin_id', '');
        if ($agentPluginId === $findingPluginId) {
          $this->agentId = (string) $agent->id();
          break;
        }
      }
    }

    // Load ai_context entities from the agent's pool.
    $poolContextIds = $this->getPoolContextIds($this->agentId);
    $contextStorage = $this->entityTypeManager->getStorage('ai_context');
    $contexts = !empty($poolContextIds) ? $contextStorage->loadMultiple($poolContextIds) : [];

    // Separate pool contexts into policy and exclusion contexts.
    $policyOptions = [];
    $exclusionContextId = '';
    foreach ($contexts as $ctx) {
      $ctxId = $ctx->id();
      if (str_ends_with($ctxId, '_exclusions')) {
        $exclusionContextId = $ctxId;
      }
      else {
        $policyOptions[$ctxId] = $ctx->label();
      }
    }

    if (empty($policyOptions) && empty($exclusionContextId)) {
      $form['message'] = [
        '#markup' => $this->t('No AI context entities are configured for this agent. Please configure the context pool first.'),
      ];
      return $form;
    }

    // Finding summary.
    $form['finding'] = [
      '#type' => 'details',
      '#title' => $this->t('Finding'),
      '#open' => TRUE,
    ];

    $form['finding']['title'] = [
      '#type' => 'item',
      '#title' => $this->t('Title'),
      '#markup' => $this->finding->getTitle(),
    ];

    $form['finding']['severity'] = [
      '#type' => 'item',
      '#title' => $this->t('Severity'),
      '#markup' => $this->finding->getSeverity(),
    ];

    if ($this->finding->getEvidenceExcerpt()) {
      $form['finding']['evidence'] = [
        '#type' => 'item',
        '#title' => $this->t('Evidence excerpt'),
        '#markup' => '<blockquote>' . $this->t('@excerpt', ['@excerpt' => $this->finding->getEvidenceExcerpt()]) . '</blockquote>',
        '#allowed_tags' => ['blockquote'],
      ];
    }

    if ($this->finding->getExplanation()) {
      $form['finding']['explanation'] = [
        '#type' => 'item',
        '#title' => $this->t('Explanation'),
        '#markup' => $this->finding->getExplanation(),
      ];
    }

    if ($this->finding->getSuggestedFix()) {
      $form['finding']['suggested_fix'] = [
        '#type' => 'item',
        '#title' => $this->t('Suggested fix'),
        '#markup' => $this->finding->getSuggestedFix(),
      ];
    }

    // Action: correct finding or false positive.
    $form['action'] = [
      '#type' => 'radios',
      '#title' => $this->t('Action'),
      '#required' => TRUE,
      '#options' => [
        'correct' => $this->t('Correct finding — add as policy example'),
        'incorrect' => $this->t('Incorrect finding — mark as false positive'),
      ],
      '#default_value' => 'correct',
    ];

    // Target context for correct findings.
    if (!empty($policyOptions)) {
      $form['target_context'] = [
        '#type' => 'select',
        '#title' => $this->t('Target policy context'),
        '#options' => $policyOptions,
        '#default_value' => key($policyOptions),
        '#description' => $this->t('Select which policy context to append this example to.'),
        '#states' => [
          'visible' => [
            ':input[name="action"]' => ['value' => 'correct'],
          ],
        ],
      ];
    }

    // Pre-filled example text.
    $defaultText = $this->buildDefaultExampleText();
    $form['example_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Example text'),
      '#default_value' => $defaultText,
      '#rows' => 8,
      '#description' => $this->t('Edit the example text before saving. This will be appended to the context content.'),
    ];

    // Optional note.
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Editorial note'),
      '#rows' => 3,
      '#description' => $this->t('Optional note for context (e.g., why this is acceptable or an issue).'),
    ];

    $form['agent_id'] = [
      '#type' => 'value',
      '#value' => $this->agentId,
    ];

    $form['exclusion_context_id'] = [
      '#type' => 'value',
      '#value' => $exclusionContextId,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save to context'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $action = (string) $form_state->getValue('action');
    $exampleText = trim((string) $form_state->getValue('example_text'));
    $note = trim((string) $form_state->getValue('note'));

    if (!$this->finding) {
      $this->messenger()->addError($this->t('The requested finding could not be loaded.'));
      return;
    }

    $contextStorage = $this->entityTypeManager->getStorage('ai_context');

    if ($action === 'correct') {
      // Append to the selected policy ai_context entity.
      $targetContextId = (string) $form_state->getValue('target_context');
      $context = $contextStorage->load($targetContextId);

      if (!$context) {
        $this->messenger()->addError($this->t('The selected context could not be loaded.'));
        return;
      }

      $currentContent = trim((string) $context->get('content'));
      $appendText = "\n\n### Example: " . $this->finding->getTitle() . "\n" . $exampleText;
      if ($note) {
        $appendText .= "\n**Note:** " . $note;
      }
      $context->set('content', $currentContent . $appendText);
      $context->save();

      $this->messenger()->addStatus($this->t('Example added to the "@context" context.', [
        '@context' => $context->label(),
      ]));
    }
    else {
      // Append to the exclusions ai_context entity.
      $exclusionContextId = (string) $form_state->getValue('exclusion_context_id');

      if (empty($exclusionContextId)) {
        $this->messenger()->addError($this->t('No exclusions context is configured for this agent.'));
        return;
      }

      $context = $contextStorage->load($exclusionContextId);
      if (!$context) {
        $this->messenger()->addError($this->t('The exclusions context could not be loaded.'));
        return;
      }

      $currentContent = trim((string) $context->get('content'));
      $appendText = "\n\n### Not an issue: " . $this->finding->getTitle() . "\n";
      $appendText .= "**Pattern:** \"" . ($this->finding->getEvidenceExcerpt() ?: 'N/A') . "\"\n";
      $appendText .= "**Why this is acceptable:** " . ($note ?: $this->finding->getExplanation());
      $context->set('content', $currentContent . $appendText);
      $context->save();

      $this->messenger()->addStatus($this->t('False positive exclusion added to the "@context" context.', [
        '@context' => $context->label(),
      ]));
    }

    // Redirect back to the finding's QA review page.
    $qa_run_id = $this->finding->getQaRunId();
    if ($qa_run_id) {
      $qa_run = $this->entityTypeManager->getStorage('qa_run')->load($qa_run_id);
      if ($qa_run) {
        $form_state->setRedirect('ai_qa_gate.entity_review', [
          'entity_type_id' => $qa_run->getTargetEntityTypeId(),
          'entity' => $qa_run->getTargetEntityId(),
        ]);
      }
    }
  }

  /**
   * Gets pool context IDs for an agent from ai_context.agent_pools config.
   *
   * @param string $agentId
   *   The agent ID.
   *
   * @return array
   *   Array of context IDs.
   */
  protected function getPoolContextIds(string $agentId): array {
    if (empty($agentId)) {
      return [];
    }

    $agents = (array) ($this->configFactory->get('ai_context.agent_pools')->get('agents') ?? []);
    foreach ($agents as $map) {
      if (($map['id'] ?? '') === $agentId) {
        return array_values(array_filter((array) ($map['contexts'] ?? [])));
      }
    }

    return [];
  }

  /**
   * Builds default example text from the finding.
   *
   * @return string
   *   The default example text.
   */
  protected function buildDefaultExampleText(): string {
    $parts = [];

    if ($this->finding->getEvidenceExcerpt()) {
      $parts[] = '**Evidence:** "' . $this->finding->getEvidenceExcerpt() . '"';
    }

    if ($this->finding->getExplanation()) {
      $parts[] = '**Why this is an issue:** ' . $this->finding->getExplanation();
    }

    if ($this->finding->getSuggestedFix()) {
      $parts[] = '**Suggested fix:** ' . $this->finding->getSuggestedFix();
    }

    return implode("\n", $parts);
  }

}
