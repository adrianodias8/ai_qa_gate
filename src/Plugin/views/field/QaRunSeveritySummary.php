<?php

namespace Drupal\ai_qa_gate\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\ai_qa_gate\Entity\QaRunInterface;

/**
 * Field handler to display QA Run severity summary.
 *
 * @ViewsField("qa_run_severity_summary")
 */
class QaRunSeveritySummary extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This field has no query.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    /** @var \Drupal\ai_qa_gate\Entity\QaRunInterface $entity */
    $entity = $this->getEntity($values);

    if (!$entity instanceof QaRunInterface) {
      $row_entity = $values->_entity ?? NULL;
      if ($row_entity instanceof \Drupal\node\NodeInterface) {
        $run_url = Url::fromRoute('ai_qa_gate.node_run', ['node' => $row_entity->id()]);
        return [
          '#type' => 'link',
          '#title' => $this->t('Run AI Review'),
          '#url' => $run_url,
        ];
      }

      return NULL;
    }

    $high = (int) $entity->get('high_count')->value;
    $medium = (int) $entity->get('medium_count')->value;
    $low = (int) $entity->get('low_count')->value;

    // Use total count to check if we should display anything other than "Passed".
    $total = $high + $medium + $low;

    $items = [];
    if ($high > 0) {
      $items[] = [
        'severity' => 'High',
        'count' => $high,
        'color' => '#e53935', // Red
        'icon' => '🔴',
      ];
    }
    if ($medium > 0) {
      $items[] = [
        'severity' => 'Medium',
        'count' => $medium,
        'color' => '#fb8c00', // Orange
        'icon' => '🟠',
      ];
    }
    if ($low > 0) {
      $items[] = [
        'severity' => 'Low',
        'count' => $low,
        'color' => '#fdd835', // Yellow
        'icon' => '🟡',
      ];
    }

    if ($total === 0) {
      $output = '<span style="color: #43a047; font-weight: bold;">✅ Passed</span>';
    }
    else {
      $parts = [];
      foreach ($items as $item) {
        $parts[] = sprintf(
          '<span style="color: %s; margin-right: 8px; white-space: nowrap; font-weight: bold;" title="%s Severity">%s %d</span>',
          $item['color'],
          $item['severity'],
          $item['icon'],
          $item['count']
        );
      }
      $output = implode(' ', $parts);
    }

    $entity_type_id = $entity->get('entity_type_id')->value;
    $entity_id = $entity->get('entity_id')->value;

    if ($entity_type_id === 'node') {
      try {
        $url = Url::fromRoute('ai_qa_gate.node_review', ['node' => $entity_id]);
      }
      catch (\Exception $e) {
        // Fallback if route does not exist or node missing.
        return ['#markup' => $output];
      }
    }
    else {
      try {
        $url = Url::fromRoute('ai_qa_gate.entity_review', [
          'entity_type_id' => $entity_type_id,
          'entity' => $entity_id,
        ]);
      }
      catch (\Exception $e) {
        return ['#markup' => $output];
      }
    }

    return [
      '#type' => 'link',
      '#title' => ['#markup' => $output],
      '#url' => $url,
      '#options' => [
        'html' => TRUE,
        'attributes' => [
          'class' => ['ai-qa-severity-summary'],
          'style' => 'text-decoration: none;',
        ],
      ],
    ];
  }

}
