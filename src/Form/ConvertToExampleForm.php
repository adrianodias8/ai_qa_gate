<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Form;

use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\ai_qa_gate\Entity\QaPolicyInterface;
use Drupal\ai_qa_gate\Service\ExampleGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for converting a QA finding into a policy example.
 */
class ConvertToExampleForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The example generator service.
   *
   * @var \Drupal\ai_qa_gate\Service\ExampleGenerator
   */
  protected ExampleGenerator $exampleGenerator;

  /**
   * The finding being converted.
   *
   * @var \Drupal\ai_qa_gate\Entity\QaFindingInterface|null
   */
  protected ?QaFindingInterface $finding = NULL;

  /**
   * AI settings derived from the QA profile for this finding.
   *
   * @var array
   */
  protected array $aiSettings = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->exampleGenerator = $container->get('ai_qa_gate.example_generator');
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

    // Load the associated QA run and profile to determine suggested policies.
    $suggested_policy_ids = [];

    $qa_run_id = $this->finding->getQaRunId();
    if ($qa_run_id) {
      $qa_run_storage = $this->entityTypeManager->getStorage('qa_run');
      /** @var \Drupal\ai_qa_gate\Entity\QaRunInterface|null $qa_run */
      $qa_run = $qa_run_storage->load($qa_run_id);
      if ($qa_run instanceof \Drupal\ai_qa_gate\Entity\QaRunInterface) {
        $profile_id = $qa_run->getProfileId();
        if ($profile_id) {
          $profile_storage = $this->entityTypeManager->getStorage('qa_profile');
          /** @var \Drupal\ai_qa_gate\Entity\QaProfileInterface|null $profile */
          $profile = $profile_storage->load($profile_id);
          if ($profile instanceof \Drupal\ai_qa_gate\Entity\QaProfileInterface) {
            $suggested_policy_ids = $profile->getPolicyIds();
            // Store AI settings from the profile so we can reuse the same
            // provider/model configuration when generating examples.
            $this->aiSettings = $profile->getAiSettings();
          }
        }
      }
    }

    // Load all policies and build options, prioritising suggested policies.
    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface[] $policies */
    $policy_storage = $this->entityTypeManager->getStorage('qa_policy');
    $policies = $policy_storage->loadMultiple();

    if (!$policies) {
      $form['message'] = [
        '#markup' => $this->t('No QA policies are configured. Create a policy first before converting findings into examples.'),
      ];
      return $form;
    }

    $options = [];
    $default_policy_id = NULL;

    // First add suggested policies in order.
    foreach ($suggested_policy_ids as $policy_id) {
      if (isset($policies[$policy_id]) && $policies[$policy_id] instanceof QaPolicyInterface) {
        $options[$policy_id] = $policies[$policy_id]->label();
        if ($default_policy_id === NULL) {
          $default_policy_id = $policy_id;
        }
        unset($policies[$policy_id]);
      }
    }

    // Then add the remaining policies.
    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface $policy */
    foreach ($policies as $policy) {
      $options[$policy->id()] = $policy->label();
      if ($default_policy_id === NULL) {
        $default_policy_id = $policy->id();
      }
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

    // Target policy selection.
    $description = $this->t('Choose which policy to add this example to.');
    if (!empty($suggested_policy_ids)) {
      $description .= ' ' . $this->t('Policies associated with the QA profile are listed first.');
    }

    $form['policy_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Target policy'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $default_policy_id,
      '#description' => $description,
    ];

    // Example type selection.
    $form['example_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Example type'),
      '#required' => TRUE,
      '#options' => [
        'bad' => $this->t('BAD example (demonstrates what to avoid)'),
        'good' => $this->t('GOOD example (shows correct wording)'),
      ],
      '#default_value' => 'bad',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate and save to policy'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $policy_id = (string) $form_state->getValue('policy_id');
    $example_type = (string) $form_state->getValue('example_type');

    if (!$this->finding) {
      $this->messenger()->addError($this->t('The requested finding could not be loaded.'));
      return;
    }

    /** @var \Drupal\ai_qa_gate\Entity\QaPolicyInterface|null $policy */
    $policy_storage = $this->entityTypeManager->getStorage('qa_policy');
    $policy = $policy_storage->load($policy_id);

    if (!$policy instanceof QaPolicyInterface) {
      $this->messenger()->addError($this->t('The selected policy could not be loaded.'));
      return;
    }

    try {
      $example_line = $this->exampleGenerator->generateExample($this->finding, $policy, $example_type, $this->aiSettings);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to generate example: @message', [
        '@message' => $e->getMessage(),
      ]));
      return;
    }

    if ($example_type === 'good') {
      $current = trim($policy->getExamplesGood());
      $new_value = $current === '' ? $example_line : $current . "\n" . $example_line;
      $policy->set('examples_good', $new_value);
    }
    else {
      $current = trim($policy->getExamplesBad());
      $new_value = $current === '' ? $example_line : $current . "\n" . $example_line;
      $policy->set('examples_bad', $new_value);
    }

    $policy->save();

    $this->messenger()->addStatus($this->t('A new @type example has been added to the "@policy" policy.', [
      '@type' => strtoupper($example_type),
      '@policy' => $policy->label(),
    ]));

    // Redirect to the policy edit form so the user can review or tweak.
    $form_state->setRedirectUrl($policy->toUrl('edit-form'));
  }

}


