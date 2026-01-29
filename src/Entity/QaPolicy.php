<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the QA Policy config entity.
 *
 * @ConfigEntityType(
 *   id = "qa_policy",
 *   label = @Translation("QA Policy"),
 *   label_collection = @Translation("QA Policies"),
 *   label_singular = @Translation("QA policy"),
 *   label_plural = @Translation("QA policies"),
 *   label_count = @PluralTranslation(
 *     singular = "@count QA policy",
 *     plural = "@count QA policies",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\ai_qa_gate\QaPolicyListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ai_qa_gate\Form\QaPolicyForm",
 *       "edit" = "Drupal\ai_qa_gate\Form\QaPolicyForm",
 *       "delete" = "Drupal\ai_qa_gate\Form\QaPolicyDeleteForm",
 *     },
 *   },
 *   config_prefix = "qa_policy",
 *   admin_permission = "administer ai qa gate",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "policy_text",
 *     "examples_good",
 *     "examples_bad",
 *     "disallowed_phrases",
 *     "required_disclaimers",
 *     "metadata",
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai-qa-gate/policies",
 *     "add-form" = "/admin/config/ai-qa-gate/policies/add",
 *     "edit-form" = "/admin/config/ai-qa-gate/policies/{qa_policy}/edit",
 *     "delete-form" = "/admin/config/ai-qa-gate/policies/{qa_policy}/delete",
 *   },
 * )
 */
class QaPolicy extends ConfigEntityBase implements QaPolicyInterface {

  /**
   * The policy ID.
   *
   * @var string
   */
  protected string $id;

  /**
   * The policy label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The policy text.
   *
   * @var string
   */
  protected string $policy_text = '';

  /**
   * Good examples.
   *
   * @var string
   */
  protected string $examples_good = '';

  /**
   * Bad examples.
   *
   * @var string
   */
  protected string $examples_bad = '';

  /**
   * Disallowed phrases (newline-separated).
   *
   * @var string
   */
  protected string $disallowed_phrases = '';

  /**
   * Required disclaimers (newline-separated).
   *
   * @var string
   */
  protected string $required_disclaimers = '';

  /**
   * Metadata.
   *
   * @var array
   */
  protected array $metadata = [
    'jurisdiction' => '',
    'audience' => '',
    'version' => '',
  ];

  /**
   * {@inheritdoc}
   */
  public function getPolicyText(): string {
    return $this->policy_text;
  }

  /**
   * {@inheritdoc}
   */
  public function getExamplesGood(): string {
    return $this->examples_good;
  }

  /**
   * {@inheritdoc}
   */
  public function getExamplesBad(): string {
    return $this->examples_bad;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisallowedPhrases(): string {
    return $this->disallowed_phrases;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisallowedPhrasesArray(): array {
    if (empty($this->disallowed_phrases)) {
      return [];
    }
    $lines = explode("\n", $this->disallowed_phrases);
    return array_filter(array_map('trim', $lines));
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredDisclaimers(): string {
    return $this->required_disclaimers;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredDisclaimersArray(): array {
    if (empty($this->required_disclaimers)) {
      return [];
    }
    $lines = explode("\n", $this->required_disclaimers);
    return array_filter(array_map('trim', $lines));
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    return $this->metadata + [
      'jurisdiction' => '',
      'audience' => '',
      'version' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPromptContext(): string {
    $parts = [];

    // Add label as header.
    $parts[] = "## Policy: " . $this->label();

    // Add main policy text.
    if (!empty($this->policy_text)) {
      $parts[] = $this->policy_text;
    }

    // Add good examples.
    if (!empty($this->examples_good)) {
      $parts[] = "\n### Good Examples";
      $parts[] = $this->examples_good;
    }

    // Add bad examples.
    if (!empty($this->examples_bad)) {
      $parts[] = "\n### Bad Examples (Avoid These)";
      $parts[] = $this->examples_bad;
    }

    // Add disallowed phrases.
    $disallowed = $this->getDisallowedPhrasesArray();
    if (!empty($disallowed)) {
      $parts[] = "\n### Disallowed Phrases";
      $parts[] = "Flag content containing any of these phrases:";
      $parts[] = "- " . implode("\n- ", $disallowed);
    }

    // Add required disclaimers.
    $disclaimers = $this->getRequiredDisclaimersArray();
    if (!empty($disclaimers)) {
      $parts[] = "\n### Required Disclaimers";
      $parts[] = "Content should include appropriate disclaimers such as:";
      $parts[] = "- " . implode("\n- ", $disclaimers);
    }

    return implode("\n\n", $parts);
  }

}

