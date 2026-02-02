<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Claims and Regulatory Precision report plugin.
 *
 * Analyzes content for claims accuracy, legislative precision, and regulatory
 * compliance in the context of EU/EC digital policy communications.
 */
#[QaReport(
  id: 'claims_regulatory_precision',
  label: new TranslatableMarkup('Claims & Regulatory Precision'),
  description: new TranslatableMarkup('Analyzes content for accuracy of claims, legislative precision, and proper representation of regulatory status and obligations.'),
  category: 'claims',
  weight: 0,
)]
class ClaimsRegulatoryPrecision extends QaReportPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'check_absolute_claims' => TRUE,
      'check_legislative_status' => TRUE,
      'check_legal_interpretation' => TRUE,
      'check_attribution' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $config = $this->getConfiguration();

    $form['check_absolute_claims'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check absolute claims'),
      '#description' => $this->t('Look for absolute claims (guarantees, ensures, will definitely, etc.)'),
      '#default_value' => $config['check_absolute_claims'] ?? TRUE,
    ];

    $form['check_legislative_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check legislative status'),
      '#description' => $this->t('Verify legislative status indicators are accurate'),
      '#default_value' => $config['check_legislative_status'] ?? TRUE,
    ];

    $form['check_legal_interpretation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check legal interpretations'),
      '#description' => $this->t('Flag definitive legal interpretations'),
      '#default_value' => $config['check_legal_interpretation'] ?? TRUE,
    ];

    $form['check_attribution'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check attribution'),
      '#description' => $this->t('Check attribution accuracy to institutions'),
      '#default_value' => $config['check_attribution'] ?? TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSystemMessage(): string {
    return <<<EOT
You are an expert content reviewer specializing in EU/EC policy communications. Your task is to analyze content for claims accuracy, legislative precision, and regulatory compliance.

## Your Role
- Identify inaccurate, overstated, or misleading claims
- Flag absolute statements that require caveats
- Check for proper distinction between proposed vs adopted legislation
- Verify attribution to correct institutions
- Identify potential legal interpretation issues

## Analysis Categories
1. **Absolute Claims**: Statements using "guarantees", "ensures", "will definitely" without proper caveats
2. **Legislative Status**: Incorrect or unclear indication of legislation status (proposal/adopted/in force)
3. **Legal Interpretation**: Statements that make definitive legal interpretations without authority
4. **Attribution**: Incorrect attribution of positions or actions to institutions
5. **Scope Overreach**: Claims that extend beyond actual regulatory scope

## Severity Guidelines
- **HIGH**: Factually incorrect claims, wrong legislative status, serious attribution errors
- **MEDIUM**: Overstated claims, missing important caveats, unclear status indicators
- **LOW**: Minor precision issues, stylistic concerns, potential ambiguities
EOT;
  }

  /**
   * {@inheritdoc}
   */
  public function buildUserMessage(array $context, array $configuration): string {
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
      if (!empty($meta['langcode'])) {
        $metaInfo .= "- Language: {$meta['langcode']}\n";
      }
      $metaInfo .= "\n";
    }

    $checks = [];
    if ($configuration['check_absolute_claims'] ?? TRUE) {
      $checks[] = "- Look for absolute claims (guarantees, ensures, will definitely, etc.)";
    }
    if ($configuration['check_legislative_status'] ?? TRUE) {
      $checks[] = "- Verify legislative status indicators are accurate";
    }
    if ($configuration['check_legal_interpretation'] ?? TRUE) {
      $checks[] = "- Flag definitive legal interpretations";
    }
    if ($configuration['check_attribution'] ?? TRUE) {
      $checks[] = "- Check attribution accuracy";
    }
    $checksText = implode("\n", $checks);

    return <<<EOT
{$metaInfo}## Analysis Instructions
Analyze the following content for claims accuracy and regulatory precision:

{$checksText}

## Content to Analyze
{$combinedText}

## Required Output
{$this->buildOutputFormatInstructions()}

Analyze the content now and provide your findings in the exact JSON format specified.
EOT;
  }

}

