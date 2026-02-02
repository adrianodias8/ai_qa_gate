<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QueueWorker;

use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\Service\RunnerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for processing individual QA agents.
 *
 * @QueueWorker(
 *   id = "ai_qa_gate_plugin_worker",
 *   title = @Translation("AI QA Gate Agent Worker"),
 *   cron = {"time" = 120}
 * )
 */
class QaPluginWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a QaPluginWorker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\ai_qa_gate\Service\RunnerInterface $runner
   *   The runner service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly RunnerInterface $runner,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly TimeInterface $time,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_qa_gate.runner'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $logger = $this->loggerFactory->get('ai_qa_gate');

    // Support both agent_id (new) and plugin_id (legacy) queue items.
    $agentId = $data['agent_id'] ?? $data['plugin_id'] ?? '';

    // Validate data.
    if (empty($data['qa_run_id']) || empty($agentId)) {
      $logger->error('Queue item missing required fields (qa_run_id or agent_id).');
      return;
    }

    $qaRunId = $data['qa_run_id'];
    $retryCount = $data['retry_count'] ?? 0;
    $delayUntil = $data['delay_until'] ?? 0;

    // Check if we need to delay this item.
    $currentTime = $this->time->getRequestTime();
    if ($delayUntil > $currentTime) {
      $delay = $delayUntil - $currentTime;
      $logger->info('Agent @agent for QA run @id is delayed by @delay seconds.', [
        '@agent' => $agentId,
        '@id' => $qaRunId,
        '@delay' => $delay,
      ]);

      // Re-queue with delay exception if supported, otherwise just sleep.
      if (class_exists(DelayedRequeueException::class)) {
        throw new DelayedRequeueException($delay);
      }
      else {
        // Fallback: re-queue the item.
        $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
        $queue->createItem($data);
        return;
      }
    }

    // Load the QA run.
    $qaRun = $this->entityTypeManager->getStorage('qa_run')->load($qaRunId);

    if (!$qaRun instanceof QaRunInterface) {
      $logger->error('QA run @id not found.', ['@id' => $qaRunId]);
      return;
    }

    // Check if this agent was already processed.
    $agentStatus = $qaRun->getPluginStatus($agentId);
    if ($agentStatus !== QaRunInterface::STATUS_PENDING) {
      $logger->notice('Agent @agent for QA run @id already processed with status @status.', [
        '@agent' => $agentId,
        '@id' => $qaRunId,
        '@status' => $agentStatus,
      ]);
      return;
    }

    $logger->info('Processing agent @agent for QA run @id.', [
      '@agent' => $agentId,
      '@id' => $qaRunId,
    ]);

    // Execute the agent.
    try {
      $result = $this->runner->executeAgent($qaRun, $agentId, $retryCount);

      // Update agent status.
      $qaRun->setPluginStatus(
        $agentId,
        $result['status'],
        $result['findings'] ?? NULL,
        $result['error'] ?? NULL
      );

      // Persist findings to dedicated database table for reliable storage.
      if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
        // Delete any existing findings for this agent+run combination.
        QaFinding::deleteForPlugin((int) $qaRunId, $agentId);
        // Create new findings.
        QaFinding::createFromArray((int) $qaRunId, $agentId, $result['findings']);
        $logger->info('Persisted @count findings for agent @agent on QA run @id.', [
          '@count' => count($result['findings']),
          '@agent' => $agentId,
          '@id' => $qaRunId,
        ]);
      }

      // Update provider/model if set.
      if (!empty($result['provider_id'])) {
        $qaRun->set('provider_id', $result['provider_id']);
      }
      if (!empty($result['model'])) {
        $qaRun->set('model', $result['model']);
      }

      // Check if all agents are complete.
      $profile = $this->entityTypeManager->getStorage('qa_profile')->load($qaRun->getProfileId());
      if ($profile) {
        $expectedAgents = $profile->getAgentsEnabled();

        if ($qaRun->areAllPluginsComplete($expectedAgents)) {
          // All agents done - aggregate and finalize.
          $qaRun->aggregatePluginFindings();
          $qaRun->computeSummaryCounts();
          $qaRun->setStatus(QaRunInterface::STATUS_SUCCESS);

          $logger->info('QA run @id completed - all agents finished.', [
            '@id' => $qaRunId,
          ]);
        }
      }

      $qaRun->save();

      $logger->info('Agent @agent completed for QA run @id with status @status.', [
        '@agent' => $agentId,
        '@id' => $qaRunId,
        '@status' => $result['status'],
      ]);
    }
    catch (\Exception $e) {
      $errorMessage = $e->getMessage();
      $isRateLimitError = $this->isRateLimitError($errorMessage);

      // Check if we should retry.
      $settings = $this->configFactory->get('ai_qa_gate.settings');
      $retryOnRateLimit = $settings->get('retry_on_rate_limit') ?? TRUE;
      $maxRetries = $settings->get('max_retries') ?? 3;

      if ($isRateLimitError && $retryOnRateLimit && $retryCount < $maxRetries) {
        $backoffMultiplier = $settings->get('retry_backoff_multiplier') ?? 2.0;
        $baseBackoff = $settings->get('plugin_backoff_seconds') ?? 5;
        $retryDelay = (int) ($baseBackoff * pow($backoffMultiplier, $retryCount));

        $logger->warning('Rate limit hit for agent @agent (attempt @attempt/@max). Re-queuing with @delay second delay.', [
          '@agent' => $agentId,
          '@attempt' => $retryCount + 1,
          '@max' => $maxRetries,
          '@delay' => $retryDelay,
        ]);

        // Re-queue with increased retry count and delay.
        $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
        $queue->createItem([
          'qa_run_id' => $qaRunId,
          'agent_id' => $agentId,
          'entity_type_id' => $data['entity_type_id'] ?? NULL,
          'entity_id' => $data['entity_id'] ?? NULL,
          'revision_id' => $data['revision_id'] ?? NULL,
          'profile_id' => $data['profile_id'] ?? NULL,
          'requested_by' => $data['requested_by'] ?? NULL,
          'delay_until' => $this->time->getRequestTime() + $retryDelay,
          'retry_count' => $retryCount + 1,
        ]);
        return;
      }

      // Mark as failed.
      $qaRun->setPluginStatus($agentId, QaRunInterface::STATUS_FAILED, NULL, $errorMessage);
      $qaRun->save();

      $logger->error('Agent @agent failed for QA run @id: @message', [
        '@agent' => $agentId,
        '@id' => $qaRunId,
        '@message' => $errorMessage,
      ]);
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

}
