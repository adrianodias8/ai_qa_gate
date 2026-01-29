<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\ai_qa_gate\Plugin\QaReport\QaReportPluginInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for QA Report plugins.
 */
class QaReportPluginManager extends DefaultPluginManager {

  /**
   * Constructs a new QaReportPluginManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/QaReport',
      $namespaces,
      $module_handler,
      QaReportPluginInterface::class,
      QaReport::class,
    );
    $this->alterInfo('qa_report_info');
    $this->setCacheBackend($cache_backend, 'qa_report_plugins');
  }

  /**
   * Gets all available plugins as options.
   *
   * @return array
   *   Array of plugin labels keyed by plugin ID.
   */
  public function getPluginOptions(): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $options[$id] = $definition['label'] ?? $id;
    }
    return $options;
  }

  /**
   * Gets plugins grouped by category.
   *
   * @return array
   *   Array of plugin definitions grouped by category.
   */
  public function getPluginsByCategory(): array {
    $grouped = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $category = $definition['category'] ?? 'general';
      $grouped[$category][$id] = $definition;
    }
    return $grouped;
  }

  /**
   * Gets plugins that support a specific entity type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return array
   *   Array of plugin definitions.
   */
  public function getPluginsForEntityType(string $entity_type_id): array {
    $supported = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $instance = $this->createInstance($id);
      if ($instance->supportsEntityType($entity_type_id)) {
        $supported[$id] = $definition;
      }
    }
    return $supported;
  }

}

