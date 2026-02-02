<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\Service\ProfileMatcher;
use Drupal\ai_qa_gate\Service\RunnerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to run QA analysis.
 */
class RunQaForm extends FormBase {

  /**
   * Constructs a RunQaForm.
   *
   * @param \Drupal\ai_qa_gate\Service\ProfileMatcher $profileMatcher
   *   The profile matcher.
   * @param \Drupal\ai_qa_gate\Service\RunnerInterface $runner
   *   The runner service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly ProfileMatcher $profileMatcher,
    protected readonly RunnerInterface $runner,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_qa_gate.profile_matcher'),
      $container->get('ai_qa_gate.runner'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_qa_gate_run_qa_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?string $entity_type_id = NULL, ?EntityInterface $entity = NULL): array {
    // Determine the entity.
    $targetEntity = $node ?? $entity;

    if (!$targetEntity) {
      $form['error'] = [
        '#markup' => $this->t('Entity not found.'),
      ];
      return $form;
    }

    // Store entity info.
    $form_state->set('target_entity_type_id', $targetEntity->getEntityTypeId());
    $form_state->set('target_entity_id', $targetEntity->id());

    // Get profile.
    $profile = $this->profileMatcher->getApplicableProfile($targetEntity);

    if (!$profile) {
      $form['error'] = [
        '#markup' => $this->t('No QA profile is configured for this content type.'),
      ];
      return $form;
    }

    $form_state->set('profile_id', $profile->id());

    $form['info'] = [
      '#type' => 'container',
      'entity' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('You are about to run AI QA analysis on: <strong>@title</strong>', [
          '@title' => $targetEntity->label(),
        ]),
      ],
      'profile' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Using profile: <strong>@profile</strong>', [
          '@profile' => $profile->label(),
        ]),
      ],
    ];

    // Show run mode.
    $runMode = $profile->getRunMode();
    if ($runMode === 'queue') {
      $form['mode_info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'message' => [
          '#markup' => $this->t('The analysis will be queued for background processing. Results will appear shortly.'),
        ],
      ];
    }
    else {
      $form['mode_info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'message' => [
          '#markup' => $this->t('The analysis will run immediately. This may take a few moments.'),
        ],
      ];
    }

    $form['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force new analysis'),
      '#description' => $this->t('Run a new analysis even if a recent one exists.'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Analysis'),
      '#button_type' => 'primary',
    ];

    // Cancel link.
    if ($targetEntity instanceof NodeInterface) {
      $cancelUrl = Url::fromRoute('ai_qa_gate.node_review', ['node' => $targetEntity->id()]);
    }
    else {
      $cancelUrl = Url::fromRoute('ai_qa_gate.entity_review', [
        'entity_type_id' => $targetEntity->getEntityTypeId(),
        'entity' => $targetEntity->id(),
      ]);
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $cancelUrl,
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entityTypeId = $form_state->get('target_entity_type_id');
    $entityId = $form_state->get('target_entity_id');
    $profileId = $form_state->get('profile_id');
    $force = (bool) $form_state->getValue('force');

    // Load the entity.
    $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($entityId);

    if (!$entity) {
      $this->messenger()->addError($this->t('Entity not found.'));
      return;
    }

    try {
      $qaRun = $this->runner->run($entity, $profileId, $force);

      if ($qaRun->getStatus() === QaRunInterface::STATUS_PENDING) {
        $this->messenger()->addStatus($this->t('AI QA analysis has been queued. Results will appear shortly.'));
      }
      elseif ($qaRun->isSuccessful()) {
        $high = $qaRun->getHighCount();
        $medium = $qaRun->getMediumCount();
        $low = $qaRun->getLowCount();
        $total = $high + $medium + $low;

        if ($total === 0) {
          $this->messenger()->addStatus($this->t('AI QA analysis completed. No issues found!'));
        }
        else {
          $this->messenger()->addStatus($this->t('AI QA analysis completed. Found @total issue(s): @high high, @medium medium, @low low.', [
            '@total' => $total,
            '@high' => $high,
            '@medium' => $medium,
            '@low' => $low,
          ]));
        }
      }
      else {
        $this->messenger()->addError($this->t('AI QA analysis failed: @error', [
          '@error' => $qaRun->getErrorMessage() ?? 'Unknown error',
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to run AI QA analysis: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    // Redirect back to review page.
    if ($entity instanceof NodeInterface) {
      $form_state->setRedirect('ai_qa_gate.node_review', ['node' => $entity->id()]);
    }
    else {
      $form_state->setRedirect('ai_qa_gate.entity_review', [
        'entity_type_id' => $entityTypeId,
        'entity' => $entityId,
      ]);
    }
  }

}

