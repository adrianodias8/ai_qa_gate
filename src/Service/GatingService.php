<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for handling content moderation gating.
 */
class GatingService implements GatingServiceInterface {

  /**
   * Constructs a GatingService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai_qa_gate\Service\RunnerInterface $runner
   *   The runner service.
   * @param \Drupal\ai_qa_gate\Service\ContextBuilderInterface $contextBuilder
   *   The context builder.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RunnerInterface $runner,
    protected readonly ContextBuilderInterface $contextBuilder,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function checkGating(EntityInterface $entity): ?string {
    // Only check if content_moderation is enabled.
    if (!$this->moduleHandler->moduleExists('content_moderation')) {
      return NULL;
    }

    // Only check fieldable entities with moderation_state.
    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }

    if (!$entity->hasField('moderation_state')) {
      return NULL;
    }

    // Get the moderation state transition.
    $newState = $entity->get('moderation_state')->value;
    $originalState = NULL;

    if ($entity->original instanceof FieldableEntityInterface &&
        $entity->original->hasField('moderation_state')) {
      $originalState = $entity->original->get('moderation_state')->value;
    }

    // If no state change, skip.
    if ($newState === $originalState) {
      return NULL;
    }

    // Find applicable profile with gating enabled.
    $profile = $this->runner->getApplicableProfile($entity);
    if (!$profile || !$profile->isGatingEnabled()) {
      return NULL;
    }

    // Check if this transition is in the gated transitions list.
    $blockedTransitions = $profile->getBlockedTransitionIds();
    
    // Get the actual workflow transition being performed.
    $workflowTransitionId = $this->getWorkflowTransitionId($entity, $originalState, $newState);
    
    // Check if the workflow transition ID is blocked.
    $transitionBlocked = FALSE;
    if ($workflowTransitionId && in_array($workflowTransitionId, $blockedTransitions, TRUE)) {
      $transitionBlocked = TRUE;
    }
    
    if (!$transitionBlocked) {
      // Transition not blocked.
      return NULL;
    }

    // Check user override permission.
    if ($this->userCanOverride()) {
      $this->loggerFactory->get('ai_qa_gate')->notice(
        'User @uid used override permission to bypass QA gate for @entity_type @entity_id transition to @state',
        [
          '@uid' => $this->currentUser->id(),
          '@entity_type' => $entity->getEntityTypeId(),
          '@entity_id' => $entity->id(),
          '@state' => $newState,
        ]
      );
      return NULL;
    }

    // Get latest QA run.
    $latestRun = $this->runner->getLatestRun($entity, $profile->id());

    // If no run exists, block.
    if (!$latestRun) {
      return $this->t('An AI QA Review is required before this content can be published. Please run the AI QA analysis from the AI Review tab.');
    }

    // Check if run is successful.
    if (!$latestRun->isSuccessful()) {
      if ($latestRun->getStatus() === QaRunInterface::STATUS_PENDING) {
        return $this->t('An AI QA Review is currently pending. Please wait for it to complete before publishing.');
      }
      return $this->t('The last AI QA Review failed. Please run a new analysis from the AI Review tab.');
    }

    // Check if run is stale.
    $currentHash = $this->contextBuilder->computeInputHash($entity, $profile);
    if ($latestRun->isStale($currentHash)) {
      return $this->t('The content has changed since the last AI QA Review. Please run a new analysis from the AI Review tab.');
    }

    // Check severity threshold.
    $threshold = $profile->getSeverityThreshold();
    $maxSeverity = $latestRun->getMaxSeverity();

    if ($this->exceedsThreshold($maxSeverity, $threshold)) {
      // Load all findings for this run.
      $findings = QaFinding::loadForRun((int) $latestRun->id());
      
      // Filter findings that meet or exceed the threshold.
      $thresholdFindings = $this->filterFindingsByThreshold($findings, $threshold);
      
      if (empty($thresholdFindings)) {
        // No findings at threshold, allow transition.
        return NULL;
      }

      // Check if acknowledgement is required.
      $gatingSettings = $profile->getGatingSettings();
      $requireAcknowledgement = !empty($gatingSettings['require_acknowledgement']);

      if ($requireAcknowledgement) {
        // Check if all threshold findings are acknowledged.
        $unacknowledged = [];
        foreach ($thresholdFindings as $finding) {
          if (!$finding->isAcknowledged()) {
            $unacknowledged[] = $finding;
          }
        }

        if (!empty($unacknowledged)) {
          $unacknowledgedCount = count($unacknowledged);
          $totalCount = count($thresholdFindings);
          
          $message = $this->t('This content cannot be published. @unacknowledged of @total finding(s) at or above the severity threshold must be acknowledged before publishing.', [
            '@unacknowledged' => $unacknowledgedCount,
            '@total' => $totalCount,
          ]);
          
          $message .= ' ' . $this->t('Please acknowledge the findings in the AI Review tab, or contact an administrator with override permissions.');
          
          return (string) $message;
        }
      }
      else {
        // Acknowledgement not required, but findings exceed threshold.
        $highCount = $latestRun->getHighCount();
        $mediumCount = $latestRun->getMediumCount();

        $message = $this->t('This content cannot be published due to AI QA findings exceeding the severity threshold.');

        if ($highCount > 0) {
          $message .= ' ' . $this->t('@count high severity issue(s) found.', ['@count' => $highCount]);
        }
        if ($mediumCount > 0 && $threshold !== 'high') {
          $message .= ' ' . $this->t('@count medium severity issue(s) found.', ['@count' => $mediumCount]);
        }

        $message .= ' ' . $this->t('Please review the findings in the AI Review tab and address the issues, or contact an administrator with override permissions.');

        return (string) $message;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function userCanOverride(): bool {
    return false; // TODO: Remove this after testing.
    // return $this->currentUser->hasPermission('override ai qa gate');
  }

  /**
   * Gets the workflow transition ID for a state change.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string|null $fromState
   *   The original state.
   * @param string|null $toState
   *   The new state.
   *
   * @return string|null
   *   The workflow transition ID, or NULL if not found.
   */
  protected function getWorkflowTransitionId(EntityInterface $entity, ?string $fromState, ?string $toState): ?string {
    if (!$this->moduleHandler->moduleExists('content_moderation')) {
      return NULL;
    }

    /** @var \Drupal\content_moderation\ModerationInformationInterface $moderationInfo */
    $moderationInfo = \Drupal::service('content_moderation.moderation_information');

    $entityType = $this->entityTypeManager->getDefinition($entity->getEntityTypeId());
    if (!$moderationInfo->shouldModerateEntitiesOfBundle($entityType, $entity->bundle())) {
      return NULL;
    }

    $workflow = $moderationInfo->getWorkflowForEntityTypeAndBundle($entity->getEntityTypeId(), $entity->bundle());
    if (!$workflow) {
      return NULL;
    }

    // Find the transition that goes from $fromState to $toState.
    $typePlugin = $workflow->getTypePlugin();
    foreach ($typePlugin->getTransitions() as $transition) {
      if ($transition->to()->id() === $toState) {
        // Check if this transition can come from the original state.
        if ($fromState === NULL) {
          // New entity - check if this transition is valid from any state.
          return $transition->id();
        }
        foreach ($transition->from() as $fromStateObj) {
          if ($fromStateObj->id() === $fromState) {
            return $transition->id();
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Checks if a severity exceeds the threshold.
   *
   * @param string $severity
   *   The actual severity.
   * @param string $threshold
   *   The threshold.
   *
   * @return bool
   *   TRUE if exceeds, FALSE otherwise.
   */
  protected function exceedsThreshold(string $severity, string $threshold): bool {
    $severityOrder = [
      'none' => 0,
      'low' => 1,
      'medium' => 2,
      'high' => 3,
    ];

    $severityValue = $severityOrder[$severity] ?? 0;
    $thresholdValue = $severityOrder[$threshold] ?? 3;

    return $severityValue >= $thresholdValue;
  }

  /**
   * Filters findings by severity threshold.
   *
   * @param array $findings
   *   Array of QaFindingInterface entities.
   * @param string $threshold
   *   The severity threshold (low, medium, high).
   *
   * @return array
   *   Array of findings that meet or exceed the threshold.
   */
  protected function filterFindingsByThreshold(array $findings, string $threshold): array {
    $severityOrder = [
      'low' => 1,
      'medium' => 2,
      'high' => 3,
    ];

    $thresholdValue = $severityOrder[$threshold] ?? 3;
    $filtered = [];

    foreach ($findings as $finding) {
      $severity = $finding->getSeverity();
      $severityValue = $severityOrder[$severity] ?? 0;
      
      if ($severityValue >= $thresholdValue) {
        $filtered[] = $finding;
      }
    }

    return $filtered;
  }

  /**
   * Translates a string.
   *
   * @param string $string
   *   The string to translate.
   * @param array $args
   *   The arguments.
   *
   * @return string
   *   The translated string.
   */
  protected function t(string $string, array $args = []): string {
    return (string) \Drupal::translation()->translate($string, $args);
  }

}

