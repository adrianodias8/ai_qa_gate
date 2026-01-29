<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\AiClient\AiClientInterface;
use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaProfile;
use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\ai_qa_gate\Entity\QaRun;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\Exception\AiClientException;
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
   * @param \Drupal\ai_qa_gate\AiClient\AiClientInterface $aiClient
   *   The AI client.
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
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ContextBuilderInterface $contextBuilder,
    protected readonly AiClientInterface $aiClient,
    protected readonly QaReportPluginManager $reportPluginManager,
    protected readonly QueueFactory $queueFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ConfigFactoryInterface $configFactory,
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
      return $this->queueAllPlugins($entity, $profile_id);
    }

    // Sync execution with backoff.
    $qaRun = $this->createPendingRun($entity, $profile);
    $qaRun->save();  // Save to get an ID before executing plugins.
    return $this->executeRunWithBackoff($qaRun);
  }

  /**
   * {@inheritdoc}
   */
  public function queue(EntityInterface $entity, string $profile_id): QaRunInterface {
    // Delegate to queueAllPlugins for per-plugin queueing.
    return $this->queueAllPlugins($entity, $profile_id);
  }

  /**
   * {@inheritdoc}
   */
  public function queueAllPlugins(EntityInterface $entity, string $profile_id): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    $qaRun = $this->createPendingRun($entity, $profile);
    
    // Initialize plugin results with pending status for all enabled plugins.
    $enabledReports = $profile->getReportsEnabled();
    $pluginResults = [];
    foreach ($enabledReports as $reportConfig) {
      if (!empty($reportConfig['enabled'])) {
        $pluginResults[$reportConfig['plugin_id']] = [
          'status' => QaRunInterface::STATUS_PENDING,
        ];
      }
    }
    $qaRun->setPluginResults($pluginResults);
    $qaRun->save();

    // Get backoff settings.
    $settings = $this->configFactory->get('ai_qa_gate.settings');
    $backoffSeconds = $settings->get('plugin_backoff_seconds') ?? 5;

    // Queue each plugin separately with delay.
    $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
    $index = 0;
    $baseTime = $this->time->getRequestTime();

    foreach ($enabledReports as $reportConfig) {
      if (empty($reportConfig['enabled'])) {
        continue;
      }

      $delayUntil = $baseTime + ($index * $backoffSeconds);
      
      $queue->createItem([
        'qa_run_id' => $qaRun->id(),
        'plugin_id' => $reportConfig['plugin_id'],
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
  public function queuePlugin(EntityInterface $entity, string $profile_id, string $plugin_id): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    // Get or create QA run.
    $qaRun = $this->getLatestRun($entity, $profile_id);
    if (!$qaRun) {
      // No existing run - create new one.
      $qaRun = $this->createPendingRun($entity, $profile);
      $pluginResults = [$plugin_id => ['status' => QaRunInterface::STATUS_PENDING]];
      $qaRun->setPluginResults($pluginResults);
      $qaRun->save();
    }
    else {
      // Reuse existing run - preserve results from other plugins.
      // Just mark this plugin as pending before re-running.
      $qaRun->setPluginStatus($plugin_id, QaRunInterface::STATUS_PENDING);
      // Reset overall status to pending since we're re-running a plugin.
      if ($qaRun->getStatus() === QaRunInterface::STATUS_SUCCESS) {
        $qaRun->setStatus(QaRunInterface::STATUS_PENDING);
      }
      $qaRun->save();
    }

    $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
    $queue->createItem([
      'qa_run_id' => $qaRun->id(),
      'plugin_id' => $plugin_id,
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
  public function runPlugin(EntityInterface $entity, string $profile_id, string $plugin_id, bool $force = FALSE): QaRunInterface {
    $profile = $this->loadProfile($profile_id);
    if (!$profile) {
      throw new \InvalidArgumentException("Profile '{$profile_id}' not found.");
    }

    // Check if we should use queue.
    $runMode = $profile->getRunMode();
    $settings = $this->configFactory->get('ai_qa_gate.settings');
    $defaultRunMode = $settings->get('default_run_mode') ?? 'queue';

    if ($runMode === 'queue' && $defaultRunMode !== 'sync') {
      return $this->queuePlugin($entity, $profile_id, $plugin_id);
    }

    // Sync execution for single plugin.
    $qaRun = $this->getLatestRun($entity, $profile_id);
    if (!$qaRun || $force) {
      // No existing run or force flag - create new run.
      $qaRun = $this->createPendingRun($entity, $profile);
      $qaRun->setPluginResults([$plugin_id => ['status' => QaRunInterface::STATUS_PENDING]]);
      $qaRun->save();
    }
    else {
      // Reuse existing run - preserve results from other plugins.
      // Just mark this plugin as pending before re-running.
      $qaRun->setPluginStatus($plugin_id, QaRunInterface::STATUS_PENDING);
      // Reset overall status to pending since we're re-running a plugin.
      if ($qaRun->getStatus() === QaRunInterface::STATUS_SUCCESS) {
        $qaRun->setStatus(QaRunInterface::STATUS_PENDING);
      }
      $qaRun->save();
    }

    $result = $this->executePlugin($qaRun, $plugin_id);
    
    $qaRun->setPluginStatus($plugin_id, $result['status'], $result['findings'] ?? NULL, $result['error'] ?? NULL);
    
    // Persist findings to dedicated database table for reliable storage.
    if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
      $this->persistFindings($qaRun, $plugin_id, $result['findings']);
    }
    
    if (!empty($result['provider_id'])) {
      $qaRun->set('provider_id', $result['provider_id']);
    }
    if (!empty($result['model'])) {
      $qaRun->set('model', $result['model']);
    }
    
    // Aggregate and finalize if all plugins complete.
    $expectedPlugins = $profile->getEnabledReportPluginIds();
    if ($qaRun->areAllPluginsComplete($expectedPlugins)) {
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
   * Executes a QA run with backoff between plugins.
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

      // Check AI client availability.
      if (!$this->aiClient->isAvailable()) {
        throw new AiClientException($this->aiClient->getUnavailableMessage());
      }

      // Get backoff settings.
      $settings = $this->configFactory->get('ai_qa_gate.settings');
      $backoffSeconds = $settings->get('plugin_backoff_seconds') ?? 5;

      // Run each enabled report plugin with backoff.
      $enabledReports = $profile->getReportsEnabled();
      $providerUsed = NULL;
      $modelUsed = NULL;
      $index = 0;

      foreach ($enabledReports as $reportConfig) {
        if (empty($reportConfig['enabled'])) {
          continue;
        }

        $pluginId = $reportConfig['plugin_id'];
        
        // Apply backoff delay (skip for first plugin).
        if ($index > 0 && $backoffSeconds > 0) {
          $logger->info('Waiting @seconds seconds before running plugin @plugin', [
            '@seconds' => $backoffSeconds,
            '@plugin' => $pluginId,
          ]);
          sleep($backoffSeconds);
        }

        $result = $this->executePlugin($qa_run, $pluginId);
        
        $qa_run->setPluginStatus($pluginId, $result['status'], $result['findings'] ?? NULL, $result['error'] ?? NULL);
        
        // Persist findings to dedicated database table for reliable storage.
        if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
          $this->persistFindings($qa_run, $pluginId, $result['findings']);
        }
        
        if (!empty($result['provider_id'])) {
          $providerUsed = $result['provider_id'];
        }
        if (!empty($result['model'])) {
          $modelUsed = $result['model'];
        }

        $index++;
      }

      // Aggregate findings from all plugins.
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
  public function executePlugin(QaRunInterface $qa_run, string $plugin_id, int $retry_count = 0): array {
    $logger = $this->loggerFactory->get('ai_qa_gate');
    $settings = $this->configFactory->get('ai_qa_gate.settings');

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

      // Check AI client availability.
      if (!$this->aiClient->isAvailable()) {
        throw new AiClientException($this->aiClient->getUnavailableMessage());
      }

      // Build context.
      $context = $this->contextBuilder->buildContext($entity, $profile);

      // Get plugin configuration from profile.
      $pluginConfig = [];
      foreach ($profile->getReportsEnabled() as $reportConfig) {
        if ($reportConfig['plugin_id'] === $plugin_id) {
          $pluginConfig = $reportConfig['configuration'] ?? [];
          break;
        }
      }

      $plugin = $this->reportPluginManager->createInstance($plugin_id, $pluginConfig);

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

      // Build prompt.
      $prompt = $plugin->buildPrompt($context, $pluginConfig);

      // Get AI settings.
      $aiSettings = $profile->getAiSettings();

      // Call AI.
      $response = $this->aiClient->chat(
        $prompt['system_message'] ?? '',
        $prompt['user_message'] ?? '',
        [
          'provider_id' => $aiSettings['provider_id'] ?? NULL,
          'model' => $aiSettings['model'] ?? NULL,
          'temperature' => $aiSettings['temperature'] ?? NULL,
          'max_tokens' => $aiSettings['max_tokens'] ?? NULL,
        ],
      );

      // Parse response.
      $findings = $plugin->parseResponse($response->getContent(), $pluginConfig);

      $logger->info('Plugin @plugin completed successfully for QA run @id', [
        '@plugin' => $plugin_id,
        '@id' => $qa_run->id(),
      ]);

      return [
        'status' => QaRunInterface::STATUS_SUCCESS,
        'findings' => $findings,
        'error' => NULL,
        'provider_id' => $response->getProviderId(),
        'model' => $response->getModel(),
      ];
    }
    catch (\Exception $e) {
      $errorMessage = $e->getMessage();
      $isRateLimitError = $this->isRateLimitError($errorMessage);
      
      // Check if we should retry.
      $retryOnRateLimit = $settings->get('retry_on_rate_limit') ?? TRUE;
      $maxRetries = $settings->get('max_retries') ?? 3;

      if ($isRateLimitError && $retryOnRateLimit && $retry_count < $maxRetries) {
        $backoffMultiplier = $settings->get('retry_backoff_multiplier') ?? 2.0;
        $baseBackoff = $settings->get('plugin_backoff_seconds') ?? 5;
        $retryDelay = (int) ($baseBackoff * pow($backoffMultiplier, $retry_count));
        
        $logger->warning('Rate limit hit for plugin @plugin (attempt @attempt/@max). Retrying in @delay seconds.', [
          '@plugin' => $plugin_id,
          '@attempt' => $retry_count + 1,
          '@max' => $maxRetries,
          '@delay' => $retryDelay,
        ]);

        sleep($retryDelay);
        return $this->executePlugin($qa_run, $plugin_id, $retry_count + 1);
      }

      $logger->error('Plugin @plugin failed for QA run @id: @message', [
        '@plugin' => $plugin_id,
        '@id' => $qa_run->id(),
        '@message' => $errorMessage,
      ]);

      return [
        'status' => QaRunInterface::STATUS_FAILED,
        'findings' => [[
          'category' => 'system',
          'severity' => 'low',
          'title' => "Report plugin '{$plugin_id}' failed",
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
   * Checks if an error message indicates a rate limit error.
   *
   * @param string $message
   *   The error message.
   *
   * @return bool
   *   TRUE if it's a rate limit error.
   */
  protected function isRateLimitError(string $message): bool {
    $rateLimitPatterns = [
      'rate limit',
      'rate_limit',
      'too many requests',
      '429',
      'quota exceeded',
      'throttle',
      'exceeded.*limit',
    ];

    $messageLower = strtolower($message);
    foreach ($rateLimitPatterns as $pattern) {
      if (str_contains($messageLower, $pattern) || preg_match('/' . $pattern . '/i', $message)) {
        return TRUE;
      }
    }

    return FALSE;
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
   * Builds the results structure.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The profile.
   * @param array $findings
   *   The findings.
   *
   * @return array
   *   The results structure.
   */
  protected function buildResults(EntityInterface $entity, QaProfileInterface $profile, array $findings): array {
    // Count by severity.
    $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
    $maxSeverity = 'none';
    $severityOrder = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    foreach ($findings as $finding) {
      $severity = $finding['severity'] ?? 'low';
      if (isset($counts[$severity])) {
        $counts[$severity]++;
      }
      if (($severityOrder[$severity] ?? 0) > ($severityOrder[$maxSeverity] ?? 0)) {
        $maxSeverity = $severity;
      }
    }

    // Build summary.
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

    return [
      'schema_version' => '1.0',
      'entity' => [
        'type' => $entity->getEntityTypeId(),
        'id' => (string) $entity->id(),
        'revision' => $entity instanceof RevisionableInterface ? (string) $entity->getRevisionId() : NULL,
        'bundle' => $entity->bundle(),
        'langcode' => method_exists($entity, 'language') ? $entity->language()->getId() : 'en',
      ],
      'profile_id' => $profile->id(),
      'generated_at' => date('c'),
      'overall' => [
        'max_severity' => $maxSeverity,
        'counts' => $counts,
        'summary' => $summary,
      ],
      'findings' => $findings,
    ];
  }

  /**
   * Persists plugin findings to the database as QaFinding entities.
   *
   * This ensures findings are stored in a dedicated table for reliable
   * persistence, independent of JSON field caching issues.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaRunInterface $qa_run
   *   The QA run.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $findings
   *   Array of finding data from the AI response.
   */
  protected function persistFindings(QaRunInterface $qa_run, string $plugin_id, array $findings): void {
    $logger = $this->loggerFactory->get('ai_qa_gate');
    $qaRunId = (int) $qa_run->id();

    // Delete any existing findings for this plugin+run combination.
    QaFinding::deleteForPlugin($qaRunId, $plugin_id);

    // Create new findings.
    if (!empty($findings)) {
      QaFinding::createFromArray($qaRunId, $plugin_id, $findings);
      $logger->info('Persisted @count findings for plugin @plugin on QA run @id.', [
        '@count' => count($findings),
        '@plugin' => $plugin_id,
        '@id' => $qaRunId,
      ]);
    }
  }

}

