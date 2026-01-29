<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for QA Policy entities.
 */
class QaPolicyForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface $policy */
    $policy = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $policy->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $policy->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_qa_gate\Entity\QaPolicy::load',
      ],
      '#disabled' => !$policy->isNew(),
    ];

    $form['policy_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Policy text'),
      '#default_value' => $policy->getPolicyText(),
      '#rows' => 15,
      '#description' => $this->t('The main policy guidelines. Supports Markdown formatting.'),
    ];

    $form['examples_good'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Good examples'),
      '#default_value' => $policy->getExamplesGood(),
      '#rows' => 8,
      '#description' => $this->t('Examples of content that follows this policy.'),
    ];

    $form['examples_bad'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Bad examples'),
      '#default_value' => $policy->getExamplesBad(),
      '#rows' => 8,
      '#description' => $this->t('Examples of content that violates this policy.'),
    ];

    $form['disallowed_phrases'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Disallowed phrases'),
      '#default_value' => $policy->getDisallowedPhrases(),
      '#rows' => 6,
      '#description' => $this->t('One phrase per line. Content containing these phrases should be flagged.'),
    ];

    $form['required_disclaimers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Required disclaimers'),
      '#default_value' => $policy->getRequiredDisclaimers(),
      '#rows' => 4,
      '#description' => $this->t('One disclaimer per line. Content should include appropriate disclaimers.'),
    ];

    // Metadata.
    $metadata = $policy->getMetadata();
    $form['metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Metadata'),
      '#tree' => TRUE,
    ];

    $form['metadata']['jurisdiction'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Jurisdiction'),
      '#default_value' => $metadata['jurisdiction'] ?? '',
      '#description' => $this->t('e.g., EU, US, Global'),
    ];

    $form['metadata']['audience'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audience'),
      '#default_value' => $metadata['audience'] ?? '',
      '#description' => $this->t('e.g., policy_professionals, general, legal_professionals'),
    ];

    $form['metadata']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => $metadata['version'] ?? '1.0',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface $policy */
    $policy = $this->entity;

    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('QA Policy %label has been created.', [
        '%label' => $policy->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('QA Policy %label has been updated.', [
        '%label' => $policy->label(),
      ]));
    }

    $form_state->setRedirectUrl($policy->toUrl('collection'));

    return $status;
  }

}

