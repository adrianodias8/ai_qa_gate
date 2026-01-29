<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_qa_gate\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Tests gating decision logic.
 *
 * @group ai_qa_gate
 */
class GatingDecisionTest extends UnitTestCase {

  /**
   * Tests severity threshold comparison.
   *
   * @dataProvider severityThresholdProvider
   */
  public function testExceedsThreshold(string $severity, string $threshold, bool $expected): void {
    $result = $this->exceedsThreshold($severity, $threshold);
    $this->assertEquals($expected, $result);
  }

  /**
   * Data provider for severity threshold tests.
   */
  public static function severityThresholdProvider(): array {
    return [
      // High threshold - only high triggers.
      ['none', 'high', FALSE],
      ['low', 'high', FALSE],
      ['medium', 'high', FALSE],
      ['high', 'high', TRUE],

      // Medium threshold - medium and high trigger.
      ['none', 'medium', FALSE],
      ['low', 'medium', FALSE],
      ['medium', 'medium', TRUE],
      ['high', 'medium', TRUE],

      // Low threshold - everything except none triggers.
      ['none', 'low', FALSE],
      ['low', 'low', TRUE],
      ['medium', 'low', TRUE],
      ['high', 'low', TRUE],
    ];
  }

  /**
   * Replicates the threshold logic from GatingService.
   */
  protected function exceedsThreshold(string $severity, string $threshold): bool {
    $severityOrder = [
      'none' => 0,
      'low' => 1,
      'medium' => 2,
      'high' => 3,
    ];

    $severityValue = $severityOrder[$severity] ?? 0;
    $thresholdValue = $severityOrder[$threshold] ?? 3;

    return $severityValue >= $thresholdValue;
  }

  /**
   * Tests transition ID building.
   */
  public function testBuildTransitionId(): void {
    $this->assertEquals('draft__published', $this->buildTransitionId('draft', 'published'));
    $this->assertEquals('_new__draft', $this->buildTransitionId(NULL, 'draft'));
    $this->assertEquals('published__archived', $this->buildTransitionId('published', 'archived'));
  }

  /**
   * Replicates the transition ID logic from GatingService.
   */
  protected function buildTransitionId(?string $from, ?string $to): string {
    return ($from ?? '_new') . '__' . ($to ?? '_none');
  }

  /**
   * Tests summary count computation.
   */
  public function testComputeSummaryCounts(): void {
    $findings = [
      ['severity' => 'high'],
      ['severity' => 'high'],
      ['severity' => 'medium'],
      ['severity' => 'low'],
      ['severity' => 'low'],
      ['severity' => 'low'],
    ];

    $counts = $this->computeCounts($findings);

    $this->assertEquals(2, $counts['high']);
    $this->assertEquals(1, $counts['medium']);
    $this->assertEquals(3, $counts['low']);
  }

  /**
   * Computes severity counts from findings.
   */
  protected function computeCounts(array $findings): array {
    $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

    foreach ($findings as $finding) {
      $severity = $finding['severity'] ?? 'low';
      if (isset($counts[$severity])) {
        $counts[$severity]++;
      }
    }

    return $counts;
  }

  /**
   * Tests max severity calculation.
   */
  public function testGetMaxSeverity(): void {
    $this->assertEquals('high', $this->getMaxSeverity(2, 1, 3));
    $this->assertEquals('medium', $this->getMaxSeverity(0, 1, 3));
    $this->assertEquals('low', $this->getMaxSeverity(0, 0, 3));
    $this->assertEquals('none', $this->getMaxSeverity(0, 0, 0));
  }

  /**
   * Replicates max severity logic.
   */
  protected function getMaxSeverity(int $high, int $medium, int $low): string {
    if ($high > 0) {
      return 'high';
    }
    if ($medium > 0) {
      return 'medium';
    }
    if ($low > 0) {
      return 'low';
    }
    return 'none';
  }

}

