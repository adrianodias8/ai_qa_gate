<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a QA Report plugin attribute object.
 *
 * Plugin namespace: Plugin\QaReport.
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class QaReport extends Plugin {

  /**
   * Constructs a QaReport attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The plugin label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   The plugin description.
   * @param string $category
   *   The report category.
   * @param int $weight
   *   The plugin weight for ordering.
   * @param class-string|null $deriver
   *   The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly string $category = 'general',
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}

