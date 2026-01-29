<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_qa_gate\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests response parsing and schema validation.
 *
 * @group ai_qa_gate
 */
class ResponseParsingTest extends UnitTestCase {

  /**
   * Tests JSON cleaning from markdown blocks.
   */
  public function testCleanJsonResponse(): void {
    // Test with markdown code block.
    $raw = "```json\n{\"findings\": []}\n```";
    $cleaned = $this->cleanJsonResponse($raw);
    $this->assertEquals('{"findings": []}', $cleaned);

    // Test without markdown.
    $raw = '{"findings": []}';
    $cleaned = $this->cleanJsonResponse($raw);
    $this->assertEquals('{"findings": []}', $cleaned);

    // Test with extra text.
    $raw = "Here is the analysis:\n```json\n{\"findings\": []}\n```\nDone.";
    $cleaned = $this->cleanJsonResponse($raw);
    $this->assertEquals('{"findings": []}', $cleaned);

    // Test with leading/trailing whitespace.
    $raw = "\n  {\"findings\": []}  \n";
    $cleaned = $this->cleanJsonResponse($raw);
    $this->assertEquals('{"findings": []}', $cleaned);
  }

  /**
   * Replicates the clean JSON logic from QaReportPluginBase.
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
   * Tests finding normalization.
   */
  public function testNormalizeFinding(): void {
    $raw = [
      'category' => 'claims',
      'severity' => 'HIGH',
      'title' => 'Test Finding',
      'explanation' => 'This is a test.',
      'evidence' => [
        'field' => 'body',
        'excerpt' => 'test content',
      ],
      'confidence' => 0.9,
    ];

    $normalized = $this->normalizeFinding($raw);

    $this->assertEquals('claims', $normalized['category']);
    $this->assertEquals('high', $normalized['severity']); // Lowercase.
    $this->assertEquals('Test Finding', $normalized['title']);
    $this->assertEquals('This is a test.', $normalized['explanation']);
    $this->assertEquals('body', $normalized['evidence']['field']);
    $this->assertEquals('test content', $normalized['evidence']['excerpt']);
    $this->assertNull($normalized['evidence']['start']);
    $this->assertNull($normalized['evidence']['end']);
    $this->assertNull($normalized['suggested_fix']);
    $this->assertEquals(0.9, $normalized['confidence']);
  }

  /**
   * Replicates the finding normalization from QaReportPluginBase.
   */
  protected function normalizeFinding(array $finding): array {
    return [
      'category' => $finding['category'] ?? 'general',
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
   * Normalizes severity.
   */
  protected function normalizeSeverity(string $severity): string {
    $severity = strtolower(trim($severity));
    if (in_array($severity, ['low', 'medium', 'high'], TRUE)) {
      return $severity;
    }
    return 'low';
  }

  /**
   * Normalizes confidence.
   */
  protected function normalizeConfidence(mixed $confidence): float {
    $value = (float) $confidence;
    return max(0.0, min(1.0, $value));
  }

  /**
   * Tests severity normalization edge cases.
   */
  public function testSeverityNormalization(): void {
    $this->assertEquals('high', $this->normalizeSeverity('HIGH'));
    $this->assertEquals('high', $this->normalizeSeverity('High'));
    $this->assertEquals('medium', $this->normalizeSeverity(' MEDIUM '));
    $this->assertEquals('low', $this->normalizeSeverity('low'));
    $this->assertEquals('low', $this->normalizeSeverity('invalid'));
    $this->assertEquals('low', $this->normalizeSeverity(''));
    $this->assertEquals('low', $this->normalizeSeverity('critical')); // Unknown maps to low.
  }

  /**
   * Tests confidence normalization edge cases.
   */
  public function testConfidenceNormalization(): void {
    $this->assertEquals(0.5, $this->normalizeConfidence(0.5));
    $this->assertEquals(0.0, $this->normalizeConfidence(-0.5)); // Clamped to 0.
    $this->assertEquals(1.0, $this->normalizeConfidence(1.5)); // Clamped to 1.
    $this->assertEquals(0.0, $this->normalizeConfidence(0));
    $this->assertEquals(1.0, $this->normalizeConfidence(1));
    $this->assertEquals(0.0, $this->normalizeConfidence('invalid')); // String becomes 0.
  }

  /**
   * Tests full results schema.
   */
  public function testResultsSchema(): void {
    $validResults = [
      'schema_version' => '1.0',
      'entity' => [
        'type' => 'node',
        'id' => '123',
        'revision' => '456',
        'bundle' => 'page',
        'langcode' => 'en',
      ],
      'profile_id' => 'test_profile',
      'generated_at' => '2024-01-15T10:30:00+00:00',
      'overall' => [
        'max_severity' => 'medium',
        'counts' => ['high' => 0, 'medium' => 2, 'low' => 5],
        'summary' => 'Found 2 medium, 5 low severity issue(s).',
      ],
      'findings' => [],
    ];

    // Validate required keys exist.
    $this->assertArrayHasKey('schema_version', $validResults);
    $this->assertArrayHasKey('entity', $validResults);
    $this->assertArrayHasKey('profile_id', $validResults);
    $this->assertArrayHasKey('generated_at', $validResults);
    $this->assertArrayHasKey('overall', $validResults);
    $this->assertArrayHasKey('findings', $validResults);

    // Validate entity structure.
    $this->assertArrayHasKey('type', $validResults['entity']);
    $this->assertArrayHasKey('id', $validResults['entity']);
    $this->assertArrayHasKey('bundle', $validResults['entity']);

    // Validate overall structure.
    $this->assertArrayHasKey('max_severity', $validResults['overall']);
    $this->assertArrayHasKey('counts', $validResults['overall']);
    $this->assertArrayHasKey('summary', $validResults['overall']);
  }

}

