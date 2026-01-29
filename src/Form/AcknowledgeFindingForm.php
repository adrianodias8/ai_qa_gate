<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form for acknowledging a QA finding.
 */
class AcknowledgeFindingForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding */
    $finding = $this->entity;

    // Check if already acknowledged.
    if ($finding->isAcknowledged()) {
      $dateFormatter = \Drupal::service('date.formatter');
      $form['already_acknowledged'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('This finding has already been acknowledged by @user on @date.', [
          '@user' => $finding->getAcknowledgedBy() ? $finding->getAcknowledgedBy()->getDisplayName() : $this->t('Unknown'),
          '@date' => $dateFormatter->format($finding->getAcknowledgedAt()),
        ]) . '</div>',
      ];

      if ($finding->getAcknowledgementNote()) {
        $form['existing_note'] = [
          '#type' => 'item',
          '#title' => $this->t('Existing note'),
          '#markup' => '<p>' . nl2br(htmlspecialchars($finding->getAcknowledgementNote())) . '</p>',
        ];
      }
    }

    // Display finding information.
    $form['finding_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Finding Details'),
      '#open' => TRUE,
    ];

    $severityClass = match ($finding->getSeverity()) {
      'high' => 'color-error',
      'medium' => 'color-warning',
      default => '',
    };

    $form['finding_info']['severity'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '<strong>' . $this->t('Severity:') . '</strong> <span class="' . $severityClass . '">' . strtoupper($finding->getSeverity()) . '</span>',
    ];

    $form['finding_info']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '<strong>' . $this->t('Title:') . '</strong> ' . htmlspecialchars($finding->getTitle()),
    ];

    $form['finding_info']['explanation'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '<strong>' . $this->t('Explanation:') . '</strong> ' . nl2br(htmlspecialchars($finding->getExplanation())),
    ];

    // Acknowledgement note field.
    $form['acknowledgement_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Acknowledgement note'),
      '#description' => $this->t('Optional: Provide a note explaining why you are acknowledging this finding.'),
      '#default_value' => $finding->isAcknowledged() ? $finding->getAcknowledgementNote() : '',
      '#rows' => 5,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    /** @var \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding */
    $finding = $this->entity;

    if ($finding->isAcknowledged()) {
      // Change button text if already acknowledged.
      $actions['submit']['#value'] = $this->t('Update acknowledgement');
    }
    else {
      $actions['submit']['#value'] = $this->t('Acknowledge finding');
    }

    // Add cancel link.
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    // Do not copy form values to the entity automatically.
    // We handle updates manually in the save() method via $finding->acknowledge().
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Check permission.
    if (!$this->currentUser()->hasPermission('acknowledge ai qa findings')) {
      $form_state->setError($form, $this->t('You do not have permission to acknowledge findings.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding */
    $finding = $this->entity;

    $note = $form_state->getValue('acknowledgement_note');
    $note = !empty($note) ? trim($note) : NULL;

    // Load the actual User entity from the current user's ID.
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser()->id());
    $finding->acknowledge($user, $note);
    $status = $finding->save();

    if ($status === SAVED_UPDATED) {
      $this->messenger()->addStatus($this->t('Finding has been acknowledged.'));
    }

    // Redirect back to the AI Review page.
    $form_state->setRedirectUrl($this->getCancelUrl());

    return $status;
  }

  /**
   * Gets the cancel URL (back to AI Review page).
   *
   * @return \Drupal\Core\Url
   *   The cancel URL.
   */
  protected function getCancelUrl(): Url {
    /** @var \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding */
    $finding = $this->entity;

    // Load the QA run to get the entity.
    $qaRunId = $finding->getQaRunId();
    $qaRun = \Drupal::entityTypeManager()->getStorage('qa_run')->load($qaRunId);

    if ($qaRun) {
      $entityTypeId = $qaRun->getTargetEntityTypeId();
      $entityId = $qaRun->getTargetEntityId();

      $entity = \Drupal::entityTypeManager()->getStorage($entityTypeId)->load($entityId);

      if ($entity) {
        if ($entityTypeId === 'node') {
          return Url::fromRoute('ai_qa_gate.node_review', ['node' => $entityId]);
        }
        else {
          return Url::fromRoute('ai_qa_gate.entity_review', [
            'entity_type_id' => $entityTypeId,
            'entity' => $entityId,
          ]);
        }
      }
    }

    // Fallback to admin profiles page.
    return Url::fromRoute('ai_qa_gate.admin_profiles');
  }

}

