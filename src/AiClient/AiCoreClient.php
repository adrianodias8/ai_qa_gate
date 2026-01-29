<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\AiClient;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_qa_gate\Exception\AiClientException;

/**
 * AI client implementation using AI Core module.
 */
class AiCoreClient implements AiClientInterface {

  /**
   * Constructs an AiCoreClient.
   *
   * @param \Drupal\ai\AiProviderPluginManager $providerManager
   *   The AI provider plugin manager.
   */
  public function __construct(
    protected readonly AiProviderPluginManager $providerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    // Check if we have any chat providers configured.
    return $this->providerManager->hasProvidersForOperationType('chat', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getUnavailableMessage(): ?string {
    if (!$this->providerManager->hasProvidersForOperationType('chat', FALSE)) {
      return 'No AI providers are available for chat operations. Please install and configure an AI provider.';
    }

    if (!$this->providerManager->hasProvidersForOperationType('chat', TRUE)) {
      return 'AI providers exist but are not properly configured. Please configure an AI provider for chat operations.';
    }

    $default = $this->providerManager->getDefaultProviderForOperationType('chat');
    if (empty($default)) {
      return 'No default AI provider is configured for chat operations. Please set a default provider.';
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $system_message, string $user_message, array $options = []): AiClientResponse {
    try {
      // Determine provider and model.
      $provider_id = $options['provider_id'] ?? NULL;
      $model = $options['model'] ?? NULL;

      if ($provider_id && $model) {
        $provider = $this->providerManager->createInstance($provider_id);
      }
      else {
        // Use default provider.
        $default = $this->providerManager->getDefaultProviderForOperationType('chat');
        if (empty($default)) {
          throw new AiClientException('No default AI provider configured for chat operations.');
        }
        $provider_id = $default['provider_id'];
        $model = $default['model_id'];
        $provider = $this->providerManager->createInstance($provider_id);
      }

      // Check if provider is usable.
      if (!$provider->isUsable('chat')) {
        throw new AiClientException("AI provider '{$provider_id}' is not properly configured for chat operations.");
      }

      // Set configuration if provided.
      $config = [];
      if (isset($options['temperature'])) {
        $config['temperature'] = $options['temperature'];
      }
      if (isset($options['max_tokens'])) {
        $config['max_tokens'] = $options['max_tokens'];
      }
      if (!empty($config)) {
        $provider->setConfiguration($config);
      }

      // Build the chat input.
      $messages = [
        new ChatMessage('user', $user_message),
      ];
      $input = new ChatInput($messages);

      // Set system prompt.
      if (!empty($system_message)) {
        $input->setSystemPrompt($system_message);
      }

      // Execute the chat.
      $output = $provider->chat($input, $model, ['ai_qa_gate']);

      // Extract the response.
      $normalized = $output->getNormalized();
      $content = $normalized->getText();

      return new AiClientResponse(
        $content,
        $provider_id,
        $model,
        [
          'raw_output' => $output->getRawOutput(),
        ],
      );
    }
    catch (AiClientException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      throw new AiClientException('AI request failed: ' . $e->getMessage(), 0, $e);
    }
  }

}

