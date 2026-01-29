<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\AiClient;

/**
 * Interface for AI client adapters.
 */
interface AiClientInterface {

  /**
   * Checks if the AI client is available and configured.
   *
   * @return bool
   *   TRUE if available, FALSE otherwise.
   */
  public function isAvailable(): bool;

  /**
   * Gets the error message if not available.
   *
   * @return string|null
   *   The error message or NULL if available.
   */
  public function getUnavailableMessage(): ?string;

  /**
   * Sends a chat completion request.
   *
   * @param string $system_message
   *   The system message.
   * @param string $user_message
   *   The user message.
   * @param array $options
   *   Additional options:
   *   - provider_id: string|null
   *   - model: string|null
   *   - temperature: float|null
   *   - max_tokens: int|null.
   *
   * @return \Drupal\ai_qa_gate\AiClient\AiClientResponse
   *   The response.
   *
   * @throws \Drupal\ai_qa_gate\Exception\AiClientException
   *   If the request fails.
   */
  public function chat(string $system_message, string $user_message, array $options = []): AiClientResponse;

}

