<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Service;

use Drupal\ai_qa_gate\Entity\QaProfileInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Service for building analysis context from entities.
 */
class ContextBuilder implements ContextBuilderInterface {

  /**
   * Constructs a ContextBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildContext(EntityInterface $entity, QaProfileInterface $profile): array {
    $meta = $this->buildMeta($entity, $profile);
    $fragments = $this->buildFragments($entity, $profile);
    $combinedText = $this->buildCombinedText($fragments);
    $policies = $this->buildPoliciesContext($profile);

    return [
      'meta' => $meta,
      'fragments' => $fragments,
      'combined_text' => $combinedText,
      'policies' => $policies,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function computeInputHash(EntityInterface $entity, QaProfileInterface $profile): string {
    $context = $this->buildContext($entity, $profile);

    // Build hash input from combined text, meta subset, profile ID, and report plugin IDs.
    $hashInput = [
      'combined_text' => $context['combined_text'],
      'meta' => $context['meta'],
      'profile_id' => $profile->id(),
      'report_plugin_ids' => $profile->getEnabledReportPluginIds(),
    ];

    return hash('sha256', json_encode($hashInput));
  }

  /**
   * Builds entity metadata.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The QA profile.
   *
   * @return array
   *   The metadata array.
   */
  protected function buildMeta(EntityInterface $entity, QaProfileInterface $profile): array {
    $meta = [];
    $includeMeta = $profile->getIncludeMeta();

    if (!empty($includeMeta['include_entity_label'])) {
      $meta['entity_label'] = $entity->label();
    }

    if (!empty($includeMeta['include_langcode']) && method_exists($entity, 'language')) {
      $meta['langcode'] = $entity->language()->getId();
    }

    if (!empty($includeMeta['include_bundle'])) {
      $meta['bundle'] = $entity->bundle();
    }

    if (!empty($includeMeta['include_moderation_state']) && $entity instanceof FieldableEntityInterface) {
      if ($entity->hasField('moderation_state') && !$entity->get('moderation_state')->isEmpty()) {
        $meta['moderation_state'] = $entity->get('moderation_state')->value;
      }
    }

    if (!empty($includeMeta['include_taxonomy_labels']) && $entity instanceof FieldableEntityInterface) {
      $meta['taxonomy_labels'] = $this->extractTaxonomyLabels($entity);
    }

    $meta['entity_type'] = $entity->getEntityTypeId();
    $meta['entity_id'] = $entity->id();

    if ($entity instanceof \Drupal\Core\Entity\RevisionableInterface) {
      $meta['revision_id'] = $entity->getRevisionId();
    }

    return $meta;
  }

  /**
   * Builds field fragments.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The QA profile.
   *
   * @return array
   *   Array of field fragments.
   */
  protected function buildFragments(EntityInterface $entity, QaProfileInterface $profile): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }

    $fragments = [];
    $fieldsConfig = $profile->getFieldsToAnalyze();

    // Sort by weight.
    usort($fieldsConfig, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    foreach ($fieldsConfig as $fieldConfig) {
      $fieldName = $fieldConfig['field_name'] ?? '';
      if (empty($fieldName) || !$entity->hasField($fieldName)) {
        continue;
      }

      $field = $entity->get($fieldName);
      if ($field->isEmpty()) {
        continue;
      }

      $fragment = $this->extractFieldFragment($field, $fieldConfig);
      if (!empty($fragment['text'])) {
        $fragments[$fieldName] = $fragment;
      }
    }

    return $fragments;
  }

  /**
   * Extracts a fragment from a field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field.
   * @param array $config
   *   The field configuration.
   *
   * @return array
   *   The fragment with label and text.
   */
  protected function extractFieldFragment(FieldItemListInterface $field, array $config): array {
    $fragment = [
      'label' => '',
      'text' => '',
    ];

    if (!empty($config['include_label'])) {
      $fragment['label'] = $field->getFieldDefinition()->getLabel();
    }

    $text = $this->extractFieldText($field, $config);

    if (!empty($config['strip_html'])) {
      $text = $this->stripHtml($text);
    }

    $fragment['text'] = trim($text);

    return $fragment;
  }

  /**
   * Extracts text content from a field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field.
   * @param array $config
   *   The field configuration.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractFieldText(FieldItemListInterface $field, array $config): string {
    $fieldType = $field->getFieldDefinition()->getType();
    $texts = [];

    foreach ($field as $item) {
      $text = '';

      switch ($fieldType) {
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $text = $item->value ?? '';
          break;

        case 'string':
        case 'string_long':
          $text = $item->value ?? '';
          break;

        case 'entity_reference':
        case 'entity_reference_revisions':
          if (!empty($config['include_referenced_labels'])) {
            $referenced = $item->entity;
            if ($referenced) {
              $text = $referenced->label() ?? '';
            }
          }
          break;

        case 'link':
          $text = $item->title ?? '';
          if (!empty($item->uri)) {
            $text .= ' (' . $item->uri . ')';
          }
          break;

        case 'list_string':
        case 'list_integer':
        case 'list_float':
          $text = $item->value ?? '';
          break;

        default:
          // Try to get a string representation.
          if (method_exists($item, '__toString')) {
            $text = (string) $item;
          }
          elseif (isset($item->value)) {
            $text = (string) $item->value;
          }
          break;
      }

      if (!empty($text)) {
        $texts[] = $text;
      }
    }

    return implode("\n", $texts);
  }

  /**
   * Strips HTML from text.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The stripped text.
   */
  protected function stripHtml(string $text): string {
    // Convert common HTML elements to text equivalents.
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n\n", $text);
    $text = preg_replace('/<\/div>/i', "\n", $text);
    $text = preg_replace('/<li>/i', "\n- ", $text);
    $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);

    // Strip remaining tags.
    $text = strip_tags($text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace.
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
  }

  /**
   * Extracts taxonomy labels from an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return array
   *   Array of taxonomy labels.
   */
  protected function extractTaxonomyLabels(FieldableEntityInterface $entity): array {
    $labels = [];

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') !== 'taxonomy_term') {
        continue;
      }

      $field = $entity->get($fieldName);
      foreach ($field as $item) {
        if ($item->entity) {
          $labels[] = $item->entity->label();
        }
      }
    }

    return array_filter($labels);
  }

  /**
   * Builds combined text from fragments.
   *
   * @param array $fragments
   *   The fragments.
   *
   * @return string
   *   The combined text.
   */
  protected function buildCombinedText(array $fragments): string {
    $parts = [];

    foreach ($fragments as $fieldName => $fragment) {
      if (!empty($fragment['label'])) {
        $parts[] = "## {$fragment['label']}";
      }
      $parts[] = $fragment['text'];
      $parts[] = '';
    }

    return trim(implode("\n", $parts));
  }

  /**
   * Builds policies context from profile.
   *
   * @param \Drupal\ai_qa_gate\Entity\QaProfileInterface $profile
   *   The QA profile.
   *
   * @return string
   *   The combined policy context.
   */
  protected function buildPoliciesContext(QaProfileInterface $profile): string {
    $policyIds = $profile->getPolicyIds();
    if (empty($policyIds)) {
      return '';
    }

    $storage = $this->entityTypeManager->getStorage('qa_policy');
    $policies = $storage->loadMultiple($policyIds);

    $parts = [];
    foreach ($policies as $policy) {
      $parts[] = $policy->buildPromptContext();
    }

    if (empty($parts)) {
      return '';
    }

    return "## Policy Guidelines\n\n" . implode("\n\n---\n\n", $parts);
  }

}

