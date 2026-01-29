<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Tone and Neutrality report plugin.
 *
 * Analyzes content for appropriate institutional tone, political neutrality,
 * and avoidance of promotional or biased language.
 */
#[QaReport(
  id: 'tone_neutrality_institutional',
  label: new TranslatableMarkup('Tone & Neutrality'),
  description: new TranslatableMarkup('Analyzes content for appropriate institutional tone, political neutrality, and avoidance of promotional or biased language.'),
  category: 'tone',
  weight: 10,
)]
class ToneNeutralityInstitutional extends QaReportPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'check_promotional_language' => TRUE,
      'check_political_bias' => TRUE,
      'check_emotional_language' => TRUE,
      'check_self_congratulatory' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $config = $this->getConfiguration();

    $form['check_promotional_language'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check promotional language'),
      '#description' => $this->t('Identify promotional or marketing language'),
      '#default_value' => $config['check_promotional_language'] ?? TRUE,
    ];

    $form['check_political_bias'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check political bias'),
      '#description' => $this->t('Flag political bias or non-neutral framing'),
      '#default_value' => $config['check_political_bias'] ?? TRUE,
    ];

    $form['check_emotional_language'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check emotional language'),
      '#description' => $this->t('Detect inappropriate emotional appeals'),
      '#default_value' => $config['check_emotional_language'] ?? TRUE,
    ];

    $form['check_self_congratulatory'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check self-congratulatory language'),
      '#description' => $this->t('Identify self-congratulatory or triumphalist language'),
      '#default_value' => $config['check_self_congratulatory'] ?? TRUE,
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
You are an expert content reviewer specializing in institutional communication tone and neutrality. Your task is to analyze content for appropriate tone in official policy communications.

## Your Role
- Identify promotional or marketing language inappropriate for institutional content
- Flag political bias or non-neutral presentations
- Detect emotional appeals that undermine objective communication
- Identify self-congratulatory or triumphalist language

## Analysis Categories
1. **Promotional Language**: Marketing-style language, hyperbole, superlatives
2. **Political Bias**: Language favoring particular political positions
3. **Emotional Appeals**: Language designed to evoke emotional rather than rational responses
4. **Self-Congratulatory**: Triumphalist, self-praising language
5. **Dismissive Language**: Language that dismisses critics or alternative viewpoints

## Tone Standards
- Formal but accessible
- Objective and balanced
- Informative rather than persuasive
- Respectful of diverse viewpoints

## Severity Guidelines
- **HIGH**: Clear political bias, strongly promotional content, dismissive of legitimate concerns
- **MEDIUM**: Moderately promotional language, subtle bias, unnecessary superlatives
- **LOW**: Minor tone inconsistencies, slightly informal language, mild promotional hints

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
    if ($configuration['check_promotional_language'] ?? TRUE) {
      $checks[] = "- Identify promotional or marketing language";
    }
    if ($configuration['check_political_bias'] ?? TRUE) {
      $checks[] = "- Flag political bias or non-neutral framing";
    }
    if ($configuration['check_emotional_language'] ?? TRUE) {
      $checks[] = "- Detect inappropriate emotional appeals";
    }
    if ($configuration['check_self_congratulatory'] ?? TRUE) {
      $checks[] = "- Identify self-congratulatory or triumphalist language";
    }
    $checksText = implode("\n", $checks);

    return <<<EOT
{$metaInfo}## Analysis Instructions
Analyze the following content for tone and neutrality:

{$checksText}

## Content to Analyze
{$combinedText}

## Required Output
{$this->buildOutputFormatInstructions()}

Analyze the content now and provide your findings in the exact JSON format specified.
EOT;
  }

}

