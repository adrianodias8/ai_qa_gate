<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * PII and Policy Compliance report plugin.
 *
 * Analyzes content for personally identifiable information (PII) exposure
 * and policy compliance issues.
 */
#[QaReport(
  id: 'pii_policy',
  label: new TranslatableMarkup('PII & Policy Compliance'),
  description: new TranslatableMarkup('Analyzes content for personally identifiable information exposure and policy compliance issues.'),
  category: 'pii',
  weight: 30,
)]
class PiiPolicy extends QaReportPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'check_pii' => TRUE,
      'check_contact_info' => TRUE,
      'check_internal_references' => TRUE,
      'check_confidential_markers' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $config = $this->getConfiguration();

    $form['check_pii'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check PII'),
      '#description' => $this->t('Identify personally identifiable information'),
      '#default_value' => $config['check_pii'] ?? TRUE,
    ];

    $form['check_contact_info'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check contact information'),
      '#description' => $this->t('Flag personal (non-institutional) contact information'),
      '#default_value' => $config['check_contact_info'] ?? TRUE,
    ];

    $form['check_internal_references'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check internal references'),
      '#description' => $this->t('Detect internal document references or draft markers'),
      '#default_value' => $config['check_internal_references'] ?? TRUE,
    ];

    $form['check_confidential_markers'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check confidential markers'),
      '#description' => $this->t('Identify confidential or internal-only markers'),
      '#default_value' => $config['check_confidential_markers'] ?? TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPrompt(array $context, array $configuration): array {
    $systemMessage = $this->buildSystemMessage($context);
    $userMessage = $this->buildUserMessage($context, $configuration);

    return [
      'system_message' => $systemMessage,
      'user_message' => $userMessage,
      'output_schema_description' => $this->buildOutputFormatInstructions(),
    ];
  }

  /**
   * Builds the system message.
   */
  protected function buildSystemMessage(array $context): string {
    $policies = $context['policies'] ?? '';

    return <<<EOT
You are an expert content reviewer specializing in privacy and policy compliance. Your task is to analyze content for PII exposure and policy violations.

## Your Role
- Identify personally identifiable information (PII) that should not be published
- Flag inappropriate contact information
- Detect internal references that should not be public
- Identify confidential or draft markers left in content

## Analysis Categories
1. **PII Exposure**: Names, email addresses, phone numbers, identification numbers of private individuals
2. **Contact Information**: Personal (non-institutional) contact details
3. **Internal References**: Draft markers, internal document references, unpublished policy references
4. **Confidential Content**: Content marked as confidential, internal-only, or draft

## Important Distinctions
- Institutional contact information (press@ec.europa.eu) is generally acceptable
- Named public officials in their official capacity may be acceptable
- Internal document references or draft numbers should be removed before publication

## Severity Guidelines
- **HIGH**: Clear PII exposure (personal emails, phone numbers, ID numbers), confidential content
- **MEDIUM**: Internal references, draft markers, ambiguous personal information
- **LOW**: Minor policy concerns, edge cases, potential improvements

{$policies}
EOT;
  }

  /**
   * Builds the user message.
   */
  protected function buildUserMessage(array $context, array $configuration): string {
    $meta = $context['meta'] ?? [];
    $combinedText = $context['combined_text'] ?? '';

    $metaInfo = '';
    if (!empty($meta)) {
      $metaInfo = "## Content Metadata\n";
      if (!empty($meta['entity_label'])) {
        $metaInfo .= "- Title: {$meta['entity_label']}\n";
      }
      if (!empty($meta['bundle'])) {
        $metaInfo .= "- Content type: {$meta['bundle']}\n";
      }
      $metaInfo .= "\n";
    }

    $checks = [];
    if ($configuration['check_pii'] ?? TRUE) {
      $checks[] = "- Identify personally identifiable information";
    }
    if ($configuration['check_contact_info'] ?? TRUE) {
      $checks[] = "- Flag personal (non-institutional) contact information";
    }
    if ($configuration['check_internal_references'] ?? TRUE) {
      $checks[] = "- Detect internal document references or draft markers";
    }
    if ($configuration['check_confidential_markers'] ?? TRUE) {
      $checks[] = "- Identify confidential or internal-only markers";
    }
    $checksText = implode("\n", $checks);

    return <<<EOT
{$metaInfo}## Analysis Instructions
Analyze the following content for PII and policy compliance:

{$checksText}

## Content to Analyze
{$combinedText}

## Required Output
{$this->buildOutputFormatInstructions()}

Analyze the content now and provide your findings in the exact JSON format specified.
EOT;
  }

}

