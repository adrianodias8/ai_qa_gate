<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\AiClient;

/**
 * Value object for AI client responses.
 */
class AiClientResponse {

  /**
   * Constructs an AiClientResponse.
   *
   * @param string $content
   *   The response content.
   * @param string $provider_id
   *   The provider ID used.
   * @param string $model
   *   The model used.
   * @param array $metadata
   *   Additional metadata.
   */
  public function __construct(
    protected readonly string $content,
    protected readonly string $provider_id,
    protected readonly string $model,
    protected readonly array $metadata = [],
  ) {}

  /**
   * Gets the response content.
   *
   * @return string
   *   The content.
   */
  public function getContent(): string {
    return $this->content;
  }

  /**
   * Gets the provider ID.
   *
   * @return string
   *   The provider ID.
   */
  public function getProviderId(): string {
    return $this->provider_id;
  }

  /**
   * Gets the model.
   *
   * @return string
   *   The model.
   */
  public function getModel(): string {
    return $this->model;
  }

  /**
   * Gets the metadata.
   *
   * @return array
   *   The metadata.
   */
  public function getMetadata(): array {
    return $this->metadata;
  }

}

