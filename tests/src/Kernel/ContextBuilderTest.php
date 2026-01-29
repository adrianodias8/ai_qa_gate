<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_qa_gate\Kernel;

use Drupal\ai_qa_gate\Entity\QaPolicy;
use Drupal\ai_qa_gate\Entity\QaProfile;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests the ContextBuilder service.
 *
 * @group ai_qa_gate
 */
class ContextBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai_qa_gate',
    'node',
    'field',
    'text',
    'user',
    'system',
    'filter',
  ];

  /**
   * The context builder service.
   *
   * @var \Drupal\ai_qa_gate\Service\ContextBuilderInterface
   */
  protected $contextBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('qa_run');
    $this->installConfig(['filter', 'node']);

    // Create a content type.
    $nodeType = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $nodeType->save();

    // Install field storage for body field.
    $this->installConfig(['node']);

    $this->contextBuilder = \Drupal::service('ai_qa_gate.context_builder');
  }

  /**
   * Tests basic context building.
   */
  public function testBuildContext(): void {
    // Create a policy.
    $policy = QaPolicy::create([
      'id' => 'test_policy',
      'label' => 'Test Policy',
      'policy_text' => 'This is a test policy.',
    ]);
    $policy->save();

    // Create a profile.
    $profile = QaProfile::create([
      'id' => 'test_profile',
      'label' => 'Test Profile',
      'enabled' => TRUE,
      'target_entity_type_id' => 'node',
      'target_bundle' => 'article',
      'fields_to_analyze' => [
        [
          'field_name' => 'title',
          'weight' => 0,
          'include_label' => TRUE,
          'strip_html' => TRUE,
          'include_referenced_labels' => FALSE,
        ],
      ],
      'include_meta' => [
        'include_entity_label' => TRUE,
        'include_langcode' => TRUE,
        'include_bundle' => TRUE,
        'include_moderation_state' => FALSE,
        'include_taxonomy_labels' => FALSE,
      ],
      'policy_ids' => ['test_policy'],
      'reports_enabled' => [
        [
          'plugin_id' => 'claims_regulatory_precision',
          'enabled' => TRUE,
          'configuration' => [],
        ],
      ],
    ]);
    $profile->save();

    // Create a node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article Title',
      'uid' => 0,
    ]);
    $node->save();

    // Build context.
    $context = $this->contextBuilder->buildContext($node, $profile);

    // Assert meta.
    $this->assertArrayHasKey('meta', $context);
    $this->assertEquals('Test Article Title', $context['meta']['entity_label']);
    $this->assertEquals('article', $context['meta']['bundle']);
    $this->assertEquals('node', $context['meta']['entity_type']);

    // Assert fragments.
    $this->assertArrayHasKey('fragments', $context);
    $this->assertArrayHasKey('title', $context['fragments']);
    $this->assertEquals('Test Article Title', $context['fragments']['title']['text']);

    // Assert combined text.
    $this->assertArrayHasKey('combined_text', $context);
    $this->assertStringContainsString('Test Article Title', $context['combined_text']);

    // Assert policies.
    $this->assertArrayHasKey('policies', $context);
    $this->assertStringContainsString('Test Policy', $context['policies']);
    $this->assertStringContainsString('This is a test policy.', $context['policies']);
  }

  /**
   * Tests input hash computation.
   */
  public function testComputeInputHash(): void {
    $profile = QaProfile::create([
      'id' => 'hash_test_profile',
      'label' => 'Hash Test Profile',
      'enabled' => TRUE,
      'target_entity_type_id' => 'node',
      'target_bundle' => 'article',
      'fields_to_analyze' => [
        [
          'field_name' => 'title',
          'weight' => 0,
          'include_label' => TRUE,
          'strip_html' => TRUE,
          'include_referenced_labels' => FALSE,
        ],
      ],
      'include_meta' => [
        'include_entity_label' => TRUE,
        'include_langcode' => TRUE,
        'include_bundle' => TRUE,
        'include_moderation_state' => FALSE,
        'include_taxonomy_labels' => FALSE,
      ],
      'policy_ids' => [],
      'reports_enabled' => [
        [
          'plugin_id' => 'claims_regulatory_precision',
          'enabled' => TRUE,
          'configuration' => [],
        ],
      ],
    ]);
    $profile->save();

    // Create a node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original Title',
      'uid' => 0,
    ]);
    $node->save();

    // Compute initial hash.
    $hash1 = $this->contextBuilder->computeInputHash($node, $profile);
    $this->assertNotEmpty($hash1);
    $this->assertEquals(64, strlen($hash1)); // SHA256 hex = 64 chars.

    // Same content should produce same hash.
    $hash2 = $this->contextBuilder->computeInputHash($node, $profile);
    $this->assertEquals($hash1, $hash2);

    // Change title should produce different hash.
    $node->setTitle('Modified Title');
    $hash3 = $this->contextBuilder->computeInputHash($node, $profile);
    $this->assertNotEquals($hash1, $hash3);
  }

}

