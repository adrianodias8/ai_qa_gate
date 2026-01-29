<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_qa_gate\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests QA Report plugin discovery.
 *
 * @group ai_qa_gate
 */
class PluginDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai_qa_gate',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('qa_run');
  }

  /**
   * Tests that all built-in plugins are discovered.
   */
  public function testPluginDiscovery(): void {
    /** @var \Drupal\ai_qa_gate\QaReportPluginManager $pluginManager */
    $pluginManager = \Drupal::service('plugin.manager.qa_report');

    $definitions = $pluginManager->getDefinitions();

    // Should have at least 4 built-in plugins.
    $this->assertGreaterThanOrEqual(4, count($definitions));

    // Check specific plugins exist.
    $this->assertArrayHasKey('claims_regulatory_precision', $definitions);
    $this->assertArrayHasKey('tone_neutrality_institutional', $definitions);
    $this->assertArrayHasKey('accessibility_clarity', $definitions);
    $this->assertArrayHasKey('pii_policy', $definitions);
  }

  /**
   * Tests plugin instantiation.
   */
  public function testPluginInstantiation(): void {
    /** @var \Drupal\ai_qa_gate\QaReportPluginManager $pluginManager */
    $pluginManager = \Drupal::service('plugin.manager.qa_report');

    // Test claims plugin.
    $claimsPlugin = $pluginManager->createInstance('claims_regulatory_precision');
    $this->assertEquals('Claims & Regulatory Precision', $claimsPlugin->label());
    $this->assertEquals('claims', $claimsPlugin->getCategory());
    $this->assertTrue($claimsPlugin->supportsEntityType('node'));

    // Test tone plugin.
    $tonePlugin = $pluginManager->createInstance('tone_neutrality_institutional');
    $this->assertEquals('Tone & Neutrality', $tonePlugin->label());
    $this->assertEquals('tone', $tonePlugin->getCategory());

    // Test accessibility plugin.
    $accessibilityPlugin = $pluginManager->createInstance('accessibility_clarity');
    $this->assertEquals('Accessibility & Clarity', $accessibilityPlugin->label());
    $this->assertEquals('accessibility', $accessibilityPlugin->getCategory());

    // Test PII plugin.
    $piiPlugin = $pluginManager->createInstance('pii_policy');
    $this->assertEquals('PII & Policy Compliance', $piiPlugin->label());
    $this->assertEquals('pii', $piiPlugin->getCategory());
  }

  /**
   * Tests plugin options helper.
   */
  public function testPluginOptions(): void {
    /** @var \Drupal\ai_qa_gate\QaReportPluginManager $pluginManager */
    $pluginManager = \Drupal::service('plugin.manager.qa_report');

    $options = $pluginManager->getPluginOptions();

    $this->assertIsArray($options);
    $this->assertGreaterThanOrEqual(4, count($options));
    $this->assertArrayHasKey('claims_regulatory_precision', $options);
  }

  /**
   * Tests prompt building.
   */
  public function testPromptBuilding(): void {
    /** @var \Drupal\ai_qa_gate\QaReportPluginManager $pluginManager */
    $pluginManager = \Drupal::service('plugin.manager.qa_report');

    $plugin = $pluginManager->createInstance('claims_regulatory_precision');

    $context = [
      'meta' => [
        'entity_label' => 'Test Article',
        'bundle' => 'article',
        'langcode' => 'en',
      ],
      'fragments' => [
        'body' => [
          'label' => 'Body',
          'text' => 'The DSA guarantees user safety online.',
        ],
      ],
      'combined_text' => 'The DSA guarantees user safety online.',
      'policies' => 'Test policy context.',
    ];

    $prompt = $plugin->buildPrompt($context, $plugin->getConfiguration());

    $this->assertArrayHasKey('system_message', $prompt);
    $this->assertArrayHasKey('user_message', $prompt);
    $this->assertNotEmpty($prompt['system_message']);
    $this->assertNotEmpty($prompt['user_message']);
    $this->assertStringContainsString('DSA guarantees', $prompt['user_message']);
  }

}

