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
 * Queue worker for processing individual QA report plugins.
 *
 * @QueueWorker(
 *   id = "ai_qa_gate_plugin_worker",
 *   title = @Translation("AI QA Gate Plugin Worker"),
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

    // Validate data.
    if (empty($data['qa_run_id']) || empty($data['plugin_id'])) {
      $logger->error('Queue item missing required fields (qa_run_id or plugin_id).');
      return;
    }

    $qaRunId = $data['qa_run_id'];
    $pluginId = $data['plugin_id'];
    $retryCount = $data['retry_count'] ?? 0;
    $delayUntil = $data['delay_until'] ?? 0;

    // Check if we need to delay this item.
    $currentTime = $this->time->getRequestTime();
    if ($delayUntil > $currentTime) {
      $delay = $delayUntil - $currentTime;
      $logger->info('Plugin @plugin for QA run @id is delayed by @delay seconds.', [
        '@plugin' => $pluginId,
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

    // Check if this plugin was already processed.
    $pluginStatus = $qaRun->getPluginStatus($pluginId);
    if ($pluginStatus !== QaRunInterface::STATUS_PENDING) {
      $logger->notice('Plugin @plugin for QA run @id already processed with status @status.', [
        '@plugin' => $pluginId,
        '@id' => $qaRunId,
        '@status' => $pluginStatus,
      ]);
      return;
    }

    $logger->info('Processing plugin @plugin for QA run @id.', [
      '@plugin' => $pluginId,
      '@id' => $qaRunId,
    ]);

    // Execute the plugin.
    try {
      $result = $this->runner->executePlugin($qaRun, $pluginId, $retryCount);

      // Update plugin status.
      $qaRun->setPluginStatus(
        $pluginId,
        $result['status'],
        $result['findings'] ?? NULL,
        $result['error'] ?? NULL
      );

      // Persist findings to dedicated database table for reliable storage.
      if ($result['status'] === QaRunInterface::STATUS_SUCCESS && !empty($result['findings'])) {
        // Delete any existing findings for this plugin+run combination.
        QaFinding::deleteForPlugin((int) $qaRunId, $pluginId);
        // Create new findings.
        QaFinding::createFromArray((int) $qaRunId, $pluginId, $result['findings']);
        $logger->info('Persisted @count findings for plugin @plugin on QA run @id.', [
          '@count' => count($result['findings']),
          '@plugin' => $pluginId,
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

      // Check if all plugins are complete.
      $profile = $this->entityTypeManager->getStorage('qa_profile')->load($qaRun->getProfileId());
      if ($profile) {
        $expectedPlugins = $profile->getEnabledReportPluginIds();
        
        if ($qaRun->areAllPluginsComplete($expectedPlugins)) {
          // All plugins done - aggregate and finalize.
          $qaRun->aggregatePluginFindings();
          $qaRun->computeSummaryCounts();
          $qaRun->setStatus(QaRunInterface::STATUS_SUCCESS);
          
          $logger->info('QA run @id completed - all plugins finished.', [
            '@id' => $qaRunId,
          ]);
        }
      }

      $qaRun->save();

      $logger->info('Plugin @plugin completed for QA run @id with status @status.', [
        '@plugin' => $pluginId,
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
        
        $logger->warning('Rate limit hit for plugin @plugin (attempt @attempt/@max). Re-queuing with @delay second delay.', [
          '@plugin' => $pluginId,
          '@attempt' => $retryCount + 1,
          '@max' => $maxRetries,
          '@delay' => $retryDelay,
        ]);

        // Re-queue with increased retry count and delay.
        $queue = $this->queueFactory->get('ai_qa_gate_plugin_worker');
        $queue->createItem([
          'qa_run_id' => $qaRunId,
          'plugin_id' => $pluginId,
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
      $qaRun->setPluginStatus($pluginId, QaRunInterface::STATUS_FAILED, NULL, $errorMessage);
      $qaRun->save();

      $logger->error('Plugin @plugin failed for QA run @id: @message', [
        '@plugin' => $pluginId,
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

