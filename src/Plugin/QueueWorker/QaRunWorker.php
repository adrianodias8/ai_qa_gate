<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QueueWorker;

use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\Service\RunnerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for processing QA runs.
 *
 * @QueueWorker(
 *   id = "ai_qa_gate_run_worker",
 *   title = @Translation("AI QA Gate Run Worker"),
 *   cron = {"time" = 60}
 * )
 */
class QaRunWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a QaRunWorker.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly RunnerInterface $runner,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $logger = $this->loggerFactory->get('ai_qa_gate');

    // Validate data.
    if (empty($data['qa_run_id'])) {
      $logger->error('Queue item missing qa_run_id.');
      return;
    }

    // Load the QA run.
    $qaRun = $this->entityTypeManager->getStorage('qa_run')->load($data['qa_run_id']);

    if (!$qaRun instanceof QaRunInterface) {
      $logger->error('QA run @id not found.', ['@id' => $data['qa_run_id']]);
      return;
    }

    // Skip if already processed.
    if ($qaRun->getStatus() !== QaRunInterface::STATUS_PENDING) {
      $logger->notice('QA run @id already processed with status @status.', [
        '@id' => $data['qa_run_id'],
        '@status' => $qaRun->getStatus(),
      ]);
      return;
    }

    $logger->info('Processing QA run @id for @entity_type @entity_id.', [
      '@id' => $data['qa_run_id'],
      '@entity_type' => $data['entity_type_id'] ?? 'unknown',
      '@entity_id' => $data['entity_id'] ?? 'unknown',
    ]);

    // Execute the run.
    try {
      $this->runner->executeRun($qaRun);
    }
    catch (\Exception $e) {
      $logger->error('Failed to execute QA run @id: @message', [
        '@id' => $data['qa_run_id'],
        '@message' => $e->getMessage(),
      ]);

      // Mark as failed.
      $qaRun->setStatus(QaRunInterface::STATUS_FAILED);
      $qaRun->setErrorMessage('Queue processing failed: ' . $e->getMessage());
      $qaRun->save();
    }
  }

}

