<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Accessibility and Clarity report plugin.
 *
 * Analyzes content for readability, accessibility, acronym usage, and clarity
 * of communication.
 */
#[QaReport(
  id: 'accessibility_clarity',
  label: new TranslatableMarkup('Accessibility & Clarity'),
  description: new TranslatableMarkup('Analyzes content for readability, accessibility, proper acronym usage, and overall clarity of communication.'),
  category: 'accessibility',
  weight: 20,
)]
class AccessibilityClarity extends QaReportPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'check_acronyms' => TRUE,
      'check_jargon' => TRUE,
      'check_sentence_length' => TRUE,
      'check_readability' => TRUE,
      'max_sentence_words' => 25,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $config = $this->getConfiguration();

    $form['check_acronyms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check acronyms'),
      '#description' => $this->t('Identify acronyms not expanded on first use'),
      '#default_value' => $config['check_acronyms'] ?? TRUE,
    ];

    $form['check_jargon'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check jargon'),
      '#description' => $this->t('Flag technical jargon without explanation'),
      '#default_value' => $config['check_jargon'] ?? TRUE,
    ];

    $form['check_sentence_length'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check sentence length'),
      '#description' => $this->t('Flag sentences exceeding the maximum word count'),
      '#default_value' => $config['check_sentence_length'] ?? TRUE,
    ];

    $form['max_sentence_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum sentence words'),
      '#description' => $this->t('Maximum number of words allowed per sentence'),
      '#default_value' => $config['max_sentence_words'] ?? 25,
      '#min' => 10,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name$="[check_sentence_length]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['check_readability'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check readability'),
      '#description' => $this->t('Identify general readability issues'),
      '#default_value' => $config['check_readability'] ?? TRUE,
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
You are an expert content reviewer specializing in accessibility and clarity of institutional communications. Your task is to analyze content for readability and plain language compliance.

## Your Role
- Identify unexpanded acronyms on first use
- Flag jargon and technical terms without explanation
- Detect overly complex sentence structures
- Identify accessibility barriers in the text

## Analysis Categories
1. **Acronyms**: Acronyms not expanded on first use (e.g., "DSA" instead of "Digital Services Act (DSA)")
2. **Jargon**: Technical or legal jargon without adequate explanation
3. **Sentence Complexity**: Overly long or complex sentences
4. **Readability**: General readability issues that may impede understanding
5. **Structure**: Poor paragraph or content organization

## Severity Guidelines
- **HIGH**: Critical acronyms unexpanded, impenetrable jargon, severely unclear passages
- **MEDIUM**: Some unexpanded acronyms, moderate jargon usage, occasionally complex sentences
- **LOW**: Minor clarity issues, stylistic improvements possible, edge-case acronyms

{$policies}
EOT;
  }

  /**
   * Builds the user message.
   */
  protected function buildUserMessage(array $context, array $configuration): string {
    $meta = $context['meta'] ?? [];
    $combinedText = $context['combined_text'] ?? '';
    $maxSentenceWords = $configuration['max_sentence_words'] ?? 25;

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
    if ($configuration['check_acronyms'] ?? TRUE) {
      $checks[] = "- Identify acronyms not expanded on first use";
    }
    if ($configuration['check_jargon'] ?? TRUE) {
      $checks[] = "- Flag technical jargon without explanation";
    }
    if ($configuration['check_sentence_length'] ?? TRUE) {
      $checks[] = "- Flag sentences exceeding {$maxSentenceWords} words";
    }
    if ($configuration['check_readability'] ?? TRUE) {
      $checks[] = "- Identify general readability issues";
    }
    $checksText = implode("\n", $checks);

    return <<<EOT
{$metaInfo}## Analysis Instructions
Analyze the following content for accessibility and clarity:

{$checksText}

## Content to Analyze
{$combinedText}

## Required Output
{$this->buildOutputFormatInstructions()}

Analyze the content now and provide your findings in the exact JSON format specified.
EOT;
  }

}

