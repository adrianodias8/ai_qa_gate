<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\AiClient;

use Drupal\ai_qa_gate\Exception\AiClientException;

/**
 * Null AI client for when AI module is not installed.
 */
class NullClient implements AiClientInterface {

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getUnavailableMessage(): ?string {
    return 'The AI module is not installed. Please install the AI module (drupal/ai) and configure an AI provider to use AI QA Gate analysis features.';
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $system_message, string $user_message, array $options = []): AiClientResponse {
    throw new AiClientException($this->getUnavailableMessage());
  }

}

