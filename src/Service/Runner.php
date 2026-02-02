<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaProfile;
use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\ai_qa_gate\Entity\QaRun;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\QaReportPluginManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for running QA analysis.
 */
class Runner implements RunnerInterface {

  /**
   * Constructs a Runner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai_qa_gate\Service\ContextBuilderInterface $contextBuilder
   *   The context builder.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $agentManager
   *   The AI agent plugin manager.
   * @param \Drupal\ai_qa_gate\QaReportPluginManager $reportPluginManager
   *   The report plugin manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ContextBuilderInterface $contextBuilder,
    protected readonly AiAgentManager $agentManager,
    protected readonly QaReportPluginManager $reportPluginManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AiProviderPluginManager $aiProviderManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(EntityInterface $entity, string $profile_id, bool $force = FALSE): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    // Check for recent non-stale run if not forcing.
    if (!$force) {
      $latestRun = $this->getLatestRun($entity, $profile_id);
      if ($latestRun && $latestRun->isSuccessful()) {
        $currentHash = $this->contextBuilder->computeInputHash($entity, $profile);
        if (!$latestRun->isStale($currentHash)) {
          // Check cache TTL.
          $executionSettings = $profile->getExecutionSettings();
          $cacheTtl = $executionSettings['cache_ttl_seconds'] ?? 0;
          if ($cacheTtl > 0) {
            $age = $this->time->getRequestTime() - $latestRun->getExecutedAt();
            if ($age < $cacheTtl) {
              return $latestRun;
            }
          }
        }
      }
    }

    // Determine run mode.
    $runMode = $profile->getRunMode();
    $settings = $this->configFactory->get('ai_qa_gate.settings');
    $defaultRunMode = $settings->get('default_run_mode') ?? 'queue';

    if ($runMode === 'queue' && $defaultRunMode !== 'sync') {
      return $this->queueAllAgents($entity, $profile_id);
    }

    // Sync execution with backoff.
    $qaRun = $this->createPendingRun($entity, $profile);
    $qaRun->save();
    return $this->executeRunWithBackoff($qaRun);
  }

  /**
   * {@inheritdoc}
   */
  public function queue(EntityInterface $entity, string $profile_id): QaRunInterface {
    return $this->queueAllAgents($entity, $profile_id);
  }

  /**
   * {@inheritdoc}
   */
  public function queueAllAgents(EntityInterface $entity, string $profile_id): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    $qaRun = $this->createPendingRun($entity, $profile);

    // Initialize plugin results with pending status for all enabled agents.
    $agentIds = $profile->getAgentsEnabled();
    $pluginResults = [];
    foreach ($agentIds as $agentId) {
      $pluginResults[$agentId] = [
        'status' => QaRunInterface::STATUS_PENDING,
      ];
    }
    $qaRun->setPluginResults($pluginResults);
    $qaRun->save();

    // Get backoff settings.
    $settings = $this->configFactory->get('ai_qa_gate.settings');
    $backoffSeconds = $settings->get('plugin_backoff_seconds') ?? 5;

    // Queue each agent separately with delay.
    $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
    $index = 0;
    $baseTime = $this->time->getRequestTime();

    foreach ($agentIds as $agentId) {
      $delayUntil = $baseTime + ($index * $backoffSeconds);

      $queue->createItem([
        'qa_run_id' => $qaRun->id(),
        'agent_id' => $agentId,
        'entity_type_id' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
        'revision_id' => $entity instanceof RevisionableInterface ? $entity->getRevisionId() : NULL,
        'profile_id' => $profile_id,
        'requested_by' => $this->currentUser->id(),
        'delay_until' => $delayUntil,
        'retry_count' => 0,
      ]);

      $index++;
    }

    return $qaRun;
  }

  /**
   * {@inheritdoc}
   */
  public function queueAgent(EntityInterface $entity, string $profile_id, string $agent_id): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    // Get or create QA run.
    $qaRun = $this->getLatestRun($entity, $profile_id);
    if (!$qaRun) {
      $qaRun = $this->createPendingRun($entity, $profile);
      $pluginResults = [$agent_id => ['status' => QaRunInterface::STATUS_PENDING]];
      $qaRun->setPluginResults($pluginResults);
      $qaRun->save();
    }
    else {
      $qaRun->setPluginStatus($agent_id, QaRunInterface::STATUS_PENDING);
      if ($qaRun->getStatus() === QaRunInterface::STATUS_SUCCESS) {
        $qaRun->setStatus(QaRunInterface::STATUS_PENDING);
      }
      $qaRun->save();
    }

    $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
    $queue->createItem([
      'qa_run_id' => $qaRun->id(),
      'agent_id' => $agent_id,
      'entity_type_id' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'revision_id' => $entity instanceof RevisionableInterface ? $entity->getRevisionId() : NULL,
      'profile_id' => $profile_id,
      'requested_by' => $this->currentUser->id(),
      'delay_until' => $this->time->getRequestTime(),
      'retry_count' => 0,
    ]);

    return $qaRun;
  }

  /**
   * {@inheritdoc}
   */
  public function runAgent(EntityInterface $entity, string $profile_id, string $agent_id, bool $force = FALSE): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    // Check if we should use queue.
    $runMode = $profile->getRunMode();
    $settings = $this->configFactory->get('ai_qa_gate.settings');
    $defaultRunMode = $settings->get('default_run_mode') ?? 'queue';

    if ($runMode === 'queue' && $defaultRunMode !== 'sync') {
      return $this->queueAgent($entity, $profile_id, $agent_id);
    }

    // Sync execution for single agent.
    $qaRun = $this->getLatestRun($entity, $profile_id);
    if (!$qaRun || $force) {
      $qaRun = $this->createPendingRun($entity, $profile);
      $qaRun->setPluginResults([$agent_id => ['status' => QaRunInterface::STATUS_PENDING]]);
      $qaRun->save();
    }
    else {
      $qaRun->setPluginStatus($agent_id, QaRunInterface::STATUS_PENDING);
      if ($qaRun->getStatus() === QaRunInterface::STATUS_SUCCESS) {
        $qaRun->setStatus(QaRunInterface::STATUS_PENDING);
      }
      $qaRun->save();
    }

    $result = $this->executeAgent($qaRun, $agent_id);

    $qaRun->setPluginStatus($agent_id, $result['status'], $result['findings'] ?? NULL, $result['error'] ?? NULL);

    // Persist findings to dedicated database table for reliable storage.
    if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
      $this->persistFindings($qaRun, $agent_id, $result['findings']);
    }

    if (!empty($result['provider_id'])) {
      $qaRun->set('provider_id', $result['provider_id']);
    }
    if (!empty($result['model'])) {
      $qaRun->set('model', $result['model']);
    }

    // Aggregate and finalize if all agents complete.
    $expectedAgents = $profile->getAgentsEnabled();
    if ($qaRun->areAllPluginsComplete($expectedAgents)) {
      $qaRun->aggregatePluginFindings();
      $qaRun->computeSummaryCounts();
      $qaRun->setStatus(QaRunInterface::STATUS_SUCCESS);
    }

    $qaRun->save();
    return $qaRun;
  }

  /**
   * {@inheritdoc}
   */
  public function getLatestRun(EntityInterface $entity, string $profile_id): ?QaRunInterface {
    return QaRun::loadLatest(
      $entity->getEntityTypeId(),
      (string) $entity->id(),
      $profile_id,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeRun(QaRunInterface $qa_run): QaRunInterface {
    return $this->executeRunWithBackoff($qa_run);
  }

  /**
   * Executes a QA run with backoff between agents.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaRunInterface $qa_run
   *   The QA run.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The updated QA run.
   */
  protected function executeRunWithBackoff(QaRunInterface $qa_run): QaRunInterface {
    $logger = $this->loggerFactory->get('ai_qa_gate');

    try {
      // Load the entity.
      $entity = $this->loadEntity(
        $qa_run->getTargetEntityTypeId(),
        $qa_run->getTargetEntityId(),
        $qa_run->getTargetRevisionId(),
      );

      if (!$entity) {
        throw new \RuntimeException('Entity not found.');
      }

      $profile = $this->loadProfile($qa_run->getProfileId());
      if (!$profile) {
        throw new \RuntimeException('Profile not found.');
      }

      // Get backoff settings.
      $settings = $this->configFactory->get('ai_qa_gate.settings');
      $backoffSeconds = $settings->get('plugin_backoff_seconds') ?? 5;

      // Run each enabled agent with backoff.
      $agentIds = $profile->getAgentsEnabled();
      $providerUsed = NULL;
      $modelUsed = NULL;
      $index = 0;

      foreach ($agentIds as $agentId) {
        // Apply backoff delay (skip for first agent).
        if ($index > 0 && $backoffSeconds > 0) {
          $logger->info('Waiting @seconds seconds before running agent @agent', [
            '@seconds' => $backoffSeconds,
            '@agent' => $agentId,
          ]);
          sleep($backoffSeconds);
        }

        $result = $this->executeAgent($qa_run, $agentId);

        $qa_run->setPluginStatus($agentId, $result['status'], $result['findings'] ?? NULL, $result['error'] ?? NULL);

        // Persist findings to dedicated database table for reliable storage.
        if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
          $this->persistFindings($qa_run, $agentId, $result['findings']);
        }

        if (!empty($result['provider_id'])) {
          $providerUsed = $result['provider_id'];
        }
        if (!empty($result['model'])) {
          $modelUsed = $result['model'];
        }

        $index++;
      }

      // Aggregate findings from all agents.
      $qa_run->aggregatePluginFindings();

      // Update QA run.
      $qa_run->set('status', QaRunInterface::STATUS_SUCCESS);
      $qa_run->set('provider_id', $providerUsed);
      $qa_run->set('model', $modelUsed);
      $qa_run->computeSummaryCounts();
      $qa_run->save();

      $logger->info('QA run @id completed successfully for @entity_type @entity_id', [
        '@id' => $qa_run->id(),
        '@entity_type' => $qa_run->getTargetEntityTypeId(),
        '@entity_id' => $qa_run->getTargetEntityId(),
      ]);
    }
    catch (\Exception $e) {
      $qa_run->setStatus(QaRunInterface::STATUS_FAILED);
      $qa_run->setErrorMessage($e->getMessage());
      $qa_run->save();

      $logger->error('QA run @id failed: @message', [
        '@id' => $qa_run->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return $qa_run;
  }

  /**
   * {@inheritdoc}
   */
  public function executeAgent(QaRunInterface $qa_run, string $agent_id, int $retry_count = 0): array {
    $logger = $this->loggerFactory->get('ai_qa_gate');

    try {
      // Load the entity.
      $entity = $this->loadEntity(
        $qa_run->getTargetEntityTypeId(),
        $qa_run->getTargetEntityId(),
        $qa_run->getTargetRevisionId(),
      );

      if (!$entity) {
        throw new \RuntimeException('Entity not found.');
      }

      $profile = $this->loadProfile($qa_run->getProfileId());
      if (!$profile) {
        throw new \RuntimeException('Profile not found.');
      }

      // Load the agent config entity to read plugin from third_party_settings.
      $agentEntity = $this->entityTypeManager->getStorage('ai_agent')->load($agent_id);
      if (!$agentEntity) {
        throw new \RuntimeException("Agent '{$agent_id}' not found.");
      }

      $pluginId = $agentEntity->getThirdPartySetting('ai_qa_gate', 'qa_report_plugin_id', '');
      if (empty($pluginId)) {
        throw new \RuntimeException("Agent '{$agent_id}' has no QA Report plugin configured.");
      }

      $pluginConfig = $agentEntity->getThirdPartySetting('ai_qa_gate', 'qa_report_configuration', []);

      // Build context.
      $context = $this->contextBuilder->buildContext($entity, $profile);

      // Create plugin instance.
      $plugin = $this->reportPluginManager->createInstance($pluginId, $pluginConfig);

      // Check if plugin supports this entity type.
      if (!$plugin->supportsEntityType($entity->getEntityTypeId())) {
        return [
          'status' => QaRunInterface::STATUS_SUCCESS,
          'findings' => [],
          'error' => NULL,
          'provider_id' => NULL,
          'model' => NULL,
          'skipped' => TRUE,
        ];
      }

      // Build user message from plugin.
      $userMessage = $plugin->buildUserMessage($context, $pluginConfig);

      // Create agent instance — DO NOT override system_prompt.
      /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
      $agent = $this->agentManager->createInstance($agent_id);

      // Resolve and set the AI provider.
      $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat_with_tools');
      if (empty($defaults['provider_id'])) {
        $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
      }
      if (empty($defaults['provider_id'])) {
        throw new \RuntimeException('No default AI provider configured. Please configure a default chat provider at /admin/config/ai/settings.');
      }
      $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);
      $agent->setAiProvider($provider);
      $agent->setModelName($defaults['model_id']);

      // Set the task (user message) and execute.
      $task = new Task($userMessage);
      $agent->setTask($task);

      // determineSolvability() fires BuildSystemPromptEvent which triggers
      // ai_context's SystemPromptSubscriber to inject per-agent policies.
      $agent->determineSolvability();

      // Get the AI response.
      $response = $agent->answerQuestion();

      // Parse response.
      $findings = $plugin->parseResponse($response, $pluginConfig);

      $logger->info('Agent @agent completed successfully for QA run @id', [
        '@agent' => $agent_id,
        '@id' => $qa_run->id(),
      ]);

      return [
        'status' => QaRunInterface::STATUS_SUCCESS,
        'findings' => $findings,
        'error' => NULL,
        'provider_id' => $defaults['provider_id'] ?? NULL,
        'model' => $defaults['model_id'] ?? NULL,
      ];
    }
    catch (\Exception $e) {
      $errorMessage = $e->getMessage();

      $logger->error('Agent @agent failed for QA run @id: @message', [
        '@agent' => $agent_id,
        '@id' => $qa_run->id(),
        '@message' => $errorMessage,
      ]);

      return [
        'status' => QaRunInterface::STATUS_FAILED,
        'findings' => [[
          'category' => 'system',
          'severity' => 'low',
          'title' => "Agent '{$agent_id}' failed",
          'explanation' => $errorMessage,
          'evidence' => [
            'field' => '_system',
            'excerpt' => '',
            'start' => NULL,
            'end' => NULL,
          ],
          'suggested_fix' => NULL,
          'confidence' => 0.0,
        ]],
        'error' => $errorMessage,
        'provider_id' => NULL,
        'model' => NULL,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicableProfile(EntityInterface $entity): ?QaProfileInterface {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    $storage = $this->entityTypeManager->getStorage('qa_profile');
    /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface[] $profiles */
    $profiles = $storage->loadMultiple();

    // First, look for a bundle-specific profile.
    foreach ($profiles as $profile) {
      if ($profile->appliesTo($entityTypeId, $bundle) && !empty($profile->getTargetBundle())) {
        return $profile;
      }
    }

    // Then, look for a wildcard profile.
    foreach ($profiles as $profile) {
      if ($profile->appliesTo($entityTypeId, $bundle)) {
        return $profile;
      }
    }

    return NULL;
  }

  /**
   * Creates a pending QA run.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The profile.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaRunInterface
   *   The pending QA run.
   */
  protected function createPendingRun(EntityInterface $entity, QaProfileInterface $profile): QaRunInterface {
    $inputHash = $this->contextBuilder->computeInputHash($entity, $profile);

    $qaRun = QaRun::create([
      'entity_type_id' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'revision_id' => $entity instanceof RevisionableInterface ? $entity->getRevisionId() : NULL,
      'profile_id' => $profile->id(),
      'executed_by' => $this->currentUser->id(),
      'executed_at' => $this->time->getRequestTime(),
      'status' => QaRunInterface::STATUS_PENDING,
      'input_hash' => $inputHash,
    ]);

    return $qaRun;
  }

  /**
   * Loads a profile by ID.
   *
   * @param string $profile_id
   *   The profile ID.
   *
   * @return \Drupal\ai_qa_gate\Entity\QaProfileInterface|null
   *   The profile or NULL.
   */
  protected function loadProfile(string $profile_id): ?QaProfileInterface {
    return $this->entityTypeManager->getStorage('qa_profile')->load($profile_id);
  }

  /**
   * Loads an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string|null $revision_id
   *   The revision ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or NULL.
   */
  protected function loadEntity(string $entity_type_id, string $entity_id, ?string $revision_id = NULL): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);

    if ($revision_id && $storage instanceof \Drupal\Core\Entity\RevisionableStorageInterface) {
      return $storage->loadRevision($revision_id);
    }

    return $storage->load($entity_id);
  }

  /**
   * Persists plugin findings to the database as QaFinding entities.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaRunInterface $qa_run
   *   The QA run.
   * @param string $agent_id
   *   The agent ID.
   * @param array $findings
   *   Array of finding data from the AI response.
   */
  protected function persistFindings(QaRunInterface $qa_run, string $agent_id, array $findings): void {
    $logger = $this->loggerFactory->get('ai_qa_gate');
    $qaRunId = (int) $qa_run->id();

    // Delete any existing findings for this agent+run combination.
    QaFinding::deleteForPlugin($qaRunId, $agent_id);

    // Create new findings.
    if (!empty($findings)) {
      QaFinding::createFromArray($qaRunId, $agent_id, $findings);
      $logger->info('Persisted @count findings for agent @agent on QA run @id.', [
        '@count' => count($findings),
        '@agent' => $agent_id,
        '@id' => $qaRunId,
      ]);
    }
  }

}
