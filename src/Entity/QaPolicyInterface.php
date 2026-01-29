<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for QA Policy entities.
 */
interface QaPolicyInterface extends ConfigEntityInterface {

  /**
   * Gets the policy text.
   *
   * @return string
   *   The policy text.
   */
  public function getPolicyText(): string;

  /**
   * Gets good examples.
   *
   * @return string
   *   The good examples text.
   */
  public function getExamplesGood(): string;

  /**
   * Gets bad examples.
   *
   * @return string
   *   The bad examples text.
   */
  public function getExamplesBad(): string;

  /**
   * Gets disallowed phrases.
   *
   * @return string
   *   The disallowed phrases text.
   */
  public function getDisallowedPhrases(): string;

  /**
   * Gets disallowed phrases as array.
   *
   * @return array
   *   Array of disallowed phrases.
   */
  public function getDisallowedPhrasesArray(): array;

  /**
   * Gets required disclaimers.
   *
   * @return string
   *   The required disclaimers text.
   */
  public function getRequiredDisclaimers(): string;

  /**
   * Gets required disclaimers as array.
   *
   * @return array
   *   Array of required disclaimers.
   */
  public function getRequiredDisclaimersArray(): array;

  /**
   * Gets metadata.
   *
   * @return array
   *   Array of metadata.
   */
  public function getMetadata(): array;

  /**
   * Builds the full policy context for prompt injection.
   *
   * @return string
   *   The complete policy context string.
   */
  public function buildPromptContext(): string;

}

