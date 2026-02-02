<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Plugin\QaReport;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for QA Report plugins.
 */
abstract class QaReportPluginBase extends PluginBase implements QaReportPluginInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The plugin configuration.
   *
   * @var array
   */
  protected array $pluginConfiguration = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->pluginConfiguration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return (string) ($this->pluginDefinition['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string {
    return $this->pluginDefinition['category'] ?? 'general';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'enabled' => TRUE,
      'severity_weight' => 1.0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->pluginConfiguration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->pluginConfiguration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildSystemMessage(): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildUserMessage(array $context, array $configuration): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildPrompt(array $context, array $configuration): array {
    return [
      'system_message' => $this->buildSystemMessage(),
      'user_message' => $this->buildUserMessage($context, $configuration),
      'output_schema_description' => $this->buildOutputFormatInstructions(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    // Default implementation returns an empty form.
    // Plugins should override this to provide their configuration options.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEntityType(string $entity_type_id): bool {
    // By default, support all entity types.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'findings' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'category' => ['type' => 'string'],
              'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
              'title' => ['type' => 'string'],
              'explanation' => ['type' => 'string'],
              'evidence' => [
                'type' => 'object',
                'properties' => [
                  'field' => ['type' => 'string'],
                  'excerpt' => ['type' => 'string'],
                  'start' => ['type' => ['integer', 'null']],
                  'end' => ['type' => ['integer', 'null']],
                ],
                'required' => ['field', 'excerpt'],
              ],
              'suggested_fix' => ['type' => ['string', 'null']],
              'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['category', 'severity', 'title', 'explanation', 'evidence', 'confidence'],
          ],
        ],
      ],
      'required' => ['findings'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function parseResponse(string $raw, array $configuration): array {
    // Clean the response - remove markdown code blocks if present.
    $cleaned = $this->cleanJsonResponse($raw);

    $decoded = json_decode($cleaned, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      // Return an error finding if we can't parse.
      return [
        [
          'category' => 'system',
          'severity' => 'low',
          'title' => 'Failed to parse AI response',
          'explanation' => 'The AI response could not be parsed as valid JSON: ' . json_last_error_msg(),
          'evidence' => [
            'field' => '_system',
            'excerpt' => substr($raw, 0, 200),
            'start' => NULL,
            'end' => NULL,
          ],
          'suggested_fix' => NULL,
          'confidence' => 0.0,
        ],
      ];
    }

    // Normalize findings.
    $findings = $decoded['findings'] ?? [];
    $normalized = [];

    foreach ($findings as $finding) {
      $normalized[] = $this->normalizeFinding($finding);
    }

    return $normalized;
  }

  /**
   * Cleans a JSON response by removing markdown code blocks.
   *
   * @param string $raw
   *   The raw response.
   *
   * @return string
   *   The cleaned JSON.
   */
  protected function cleanJsonResponse(string $raw): string {
    $cleaned = trim($raw);

    // Remove markdown code blocks.
    if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $cleaned, $matches)) {
      $cleaned = trim($matches[1]);
    }

    // Remove any leading/trailing non-JSON characters.
    $start = strpos($cleaned, '{');
    $end = strrpos($cleaned, '}');
    if ($start !== FALSE && $end !== FALSE && $end > $start) {
      $cleaned = substr($cleaned, $start, $end - $start + 1);
    }

    return $cleaned;
  }

  /**
   * Normalizes a single finding.
   *
   * @param array $finding
   *   The raw finding.
   *
   * @return array
   *   The normalized finding.
   */
  protected function normalizeFinding(array $finding): array {
    return [
      'category' => $finding['category'] ?? $this->getCategory(),
      'severity' => $this->normalizeSeverity($finding['severity'] ?? 'low'),
      'title' => $finding['title'] ?? 'Untitled finding',
      'explanation' => $finding['explanation'] ?? '',
      'evidence' => [
        'field' => $finding['evidence']['field'] ?? '_combined',
        'excerpt' => $finding['evidence']['excerpt'] ?? '',
        'start' => $finding['evidence']['start'] ?? NULL,
        'end' => $finding['evidence']['end'] ?? NULL,
      ],
      'suggested_fix' => $finding['suggested_fix'] ?? NULL,
      'confidence' => $this->normalizeConfidence($finding['confidence'] ?? 0.5),
    ];
  }

  /**
   * Normalizes a severity value.
   *
   * @param string $severity
   *   The severity value.
   *
   * @return string
   *   The normalized severity.
   */
  protected function normalizeSeverity(string $severity): string {
    $severity = strtolower(trim($severity));
    if (in_array($severity, ['low', 'medium', 'high'], TRUE)) {
      return $severity;
    }
    return 'low';
  }

  /**
   * Normalizes a confidence value.
   *
   * @param mixed $confidence
   *   The confidence value.
   *
   * @return float
   *   The normalized confidence (0-1).
   */
  protected function normalizeConfidence(mixed $confidence): float {
    $value = (float) $confidence;
    return max(0.0, min(1.0, $value));
  }

  /**
   * Builds the output format instructions for the prompt.
   *
   * @return string
   *   The format instructions.
   */
  protected function buildOutputFormatInstructions(): string {
    return <<<EOT
You MUST respond with ONLY valid JSON. No markdown, no explanations, no additional text.
The JSON must follow this exact structure:

{
  "findings": [
    {
      "category": "string - the category of this finding",
      "severity": "low|medium|high",
      "title": "string - brief title of the issue",
      "explanation": "string - detailed explanation of the issue",
      "evidence": {
        "field": "string - the field name where issue was found, or '_combined' for general issues",
        "excerpt": "string - exact excerpt from the content showing the issue",
        "start": null or integer - character position start,
        "end": null or integer - character position end
      },
      "suggested_fix": "string or null - suggested correction",
      "confidence": 0.0-1.0 - how confident you are in this finding
    }
  ]
}

If no issues are found, return: {"findings": []}
EOT;
  }

}

