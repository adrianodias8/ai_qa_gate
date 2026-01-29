<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\AiClient\AiClientInterface;
use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\ai_qa_gate\Entity\QaPolicyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Generates policy examples from QA findings using AI.
 */
class ExampleGenerator {

  use StringTranslationTrait;

  /**
   * Constructs an ExampleGenerator.
   *
   * @param \Drupal\ai_qa_gate\AiClient\AiClientInterface $aiClient
   *   The AI client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly AiClientInterface $aiClient,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Generates a policy example line from a finding.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaFindingInterface $finding
   *   The finding to convert.
   * @param \Drupal\ai_qa_gate\Entity\QaPolicyInterface $policy
   *   The target policy.
   * @param string $type
   *   The example type: 'good' or 'bad'.
   * @param array $ai_settings
   *   Optional AI settings array from the QA profile (provider_id, model, etc).
   *
   * @return string
   *   The generated example line.
   */
  public function generateExample(QaFindingInterface $finding, QaPolicyInterface $policy, string $type = 'bad', array $ai_settings = []): string {
    $type = strtolower($type) === 'good' ? 'good' : 'bad';

    if (!$this->aiClient->isAvailable()) {
      $message = $this->aiClient->getUnavailableMessage()
        ?? $this->t('AI provider is not available for generating examples.');
      throw new \RuntimeException((string) $message);
    }

    $logger = $this->loggerFactory->get('ai_qa_gate');

    $evidence = trim((string) $finding->getEvidenceExcerpt() ?? '');
    $explanation = trim($finding->getExplanation());
    $suggested_fix = trim((string) $finding->getSuggestedFix() ?? '');

    $policy_label = $policy->label();
    $policy_text = $policy->getPolicyText();
    $existing_good = $policy->getExamplesGood();
    $existing_bad = $policy->getExamplesBad();

    $system_message = (string) $this->t('You are helping maintain a content QA policy. Given a problematic content finding and existing policy context, generate ONE concise policy example line. The line must strictly follow this format:

- For BAD examples: BAD: "problematic text" (short reason why it is problematic)
- For GOOD examples: GOOD: "corrected text"

Do not include any other commentary or markdown, only the example line.');

    $parts = [];
    $parts[] = 'Target policy: ' . $policy_label;

    if (!empty($policy_text)) {
      $parts[] = 'Policy guidelines:';
      $parts[] = $policy_text;
    }

    if (!empty($existing_good)) {
      $parts[] = 'Existing GOOD examples:';
      $parts[] = $existing_good;
    }

    if (!empty($existing_bad)) {
      $parts[] = 'Existing BAD examples:';
      $parts[] = $existing_bad;
    }

    $parts[] = 'Finding details:';
    $parts[] = 'Severity: ' . $finding->getSeverity();
    $parts[] = 'Title: ' . $finding->getTitle();

    if (!empty($evidence)) {
      $parts[] = 'Evidence excerpt: "' . $evidence . '"';
    }

    if (!empty($explanation)) {
      $parts[] = 'Explanation: ' . $explanation;
    }

    if (!empty($suggested_fix)) {
      $parts[] = 'Suggested fix from the QA plugin: "' . $suggested_fix . '"';
    }

    $parts[] = 'Example type to generate: ' . strtoupper($type);

    if ($type === 'good') {
      $parts[] = 'If a suggested fix is provided, base the GOOD example on that fix, rewriting it if necessary so that it is clear and compliant with the policy.';
    }
    else {
      $parts[] = 'For the BAD example, base the content on the problematic evidence excerpt, and summarize why it is problematic in the parenthetical reason.';
    }

    $user_message = implode("\n\n", $parts);

    $logger->info('Generating @type policy example for finding @id on policy @policy.', [
      '@type' => $type,
      '@id' => $finding->id(),
      '@policy' => $policy->id(),
    ]);

    // Build AI options, preferring explicit profile settings when provided.
    $options = [
      'provider_id' => $ai_settings['provider_id'] ?? NULL,
      'model' => $ai_settings['model'] ?? NULL,
      'temperature' => $ai_settings['temperature'] ?? 0.2,
      'max_tokens' => $ai_settings['max_tokens'] ?? 512,
    ];

    $response = $this->aiClient->chat($system_message, $user_message, $options);

    $example_line = trim($response->getContent());

    // In case the model returns surrounding formatting, try to extract the line.
    // Keep it simple: take the first non-empty line.
    $lines = preg_split('/\r\n|\r|\n/', $example_line) ?: [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '') {
        $example_line = $line;
        break;
      }
    }

    return $example_line;
  }

}


