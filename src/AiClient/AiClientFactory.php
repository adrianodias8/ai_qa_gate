<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\AiClient;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Factory for creating AI client instances.
 */
class AiClientFactory implements AiClientInterface {

  /**
   * The actual client instance.
   *
   * @var \Drupal\ai_qa_gate\AiClient\AiClientInterface|null
   */
  protected ?AiClientInterface $client = NULL;

  /**
   * Constructs an AiClientFactory.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ContainerInterface $container,
  ) {}

  /**
   * Gets the actual client instance.
   *
   * @return \Drupal\ai_qa_gate\AiClient\AiClientInterface
   *   The AI client.
   */
  protected function getClient(): AiClientInterface {
    if ($this->client === NULL) {
      if ($this->moduleHandler->moduleExists('ai')) {
        $providerManager = $this->container->get('ai.provider');
        $this->client = new AiCoreClient($providerManager);
      }
      else {
        $this->client = new NullClient();
      }
    }
    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->getClient()->isAvailable();
  }

  /**
   * {@inheritdoc}
   */
  public function getUnavailableMessage(): ?string {
    return $this->getClient()->getUnavailableMessage();
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $system_message, string $user_message, array $options = []): AiClientResponse {
    return $this->getClient()->chat($system_message, $user_message, $options);
  }

}

