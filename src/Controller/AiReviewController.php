<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Controller;

use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\Service\ContextBuilderInterface;
use Drupal\ai_qa_gate\Service\ProfileMatcher;
use Drupal\ai_qa_gate\Service\RunnerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the AI Review tab.
 */
class AiReviewController extends ControllerBase {

  /**
   * Constructs an AiReviewController.
   *
   * @param \Drupal\ai_qa_gate\Service\ProfileMatcher $profileMatcher
   *   The profile matcher.
   * @param \Drupal\ai_qa_gate\Service\RunnerInterface $runner
   *   The runner service.
   * @param \Drupal\ai_qa_gate\Service\ContextBuilderInterface $contextBuilder
   *   The context builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly ProfileMatcher $profileMatcher,
    protected readonly RunnerInterface $runner,
    protected readonly ContextBuilderInterface $contextBuilder,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_qa_gate.profile_matcher'),
      $container->get('ai_qa_gate.runner'),
      $container->get('ai_qa_gate.context_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Shows the AI Review page for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array
   *   The render array.
   */
  public function nodeReview(NodeInterface $node): array {
    return $this->buildReviewPage($node);
  }

  /**
   * Shows the AI Review page for any entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The render array.
   */
  public function review(string $entity_type_id, EntityInterface $entity): array {
    return $this->buildReviewPage($entity);
  }

  /**
   * Builds the review page for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   The render array.
   */
  protected function buildReviewPage(EntityInterface $entity): array {
    $profile = $this->profileMatcher->getApplicableProfile($entity);

    if (!$profile) {
      return [
        '#markup' => $this->t('No QA profile is configured for this content type.'),
      ];
    }

    // Get latest run.
    $latestRun = $this->runner->getLatestRun($entity, $profile->id());

    // Check staleness.
    $isStale = FALSE;
    if ($latestRun && $latestRun->isSuccessful()) {
      $currentHash = $this->contextBuilder->computeInputHash($entity, $profile);
      $isStale = $latestRun->isStale($currentHash);
    }

      // Build findings by plugin - load from dedicated QaFinding entities.
      $findingsByPlugin = [];
      $summary = NULL;
      $findingEntities = [];

      if ($latestRun) {
        // Load findings from dedicated database table.
        $qaRunId = (int) $latestRun->id();
        \Drupal::logger('ai_qa_gate')->debug('Controller loading findings for qa_run_id=@id', ['@id' => $qaRunId]);
        $findingEntities = QaFinding::loadForRun($qaRunId);
        \Drupal::logger('ai_qa_gate')->debug('Controller found @count finding entities', ['@count' => count($findingEntities)]);
        $allFindings = [];

        foreach ($findingEntities as $findingEntity) {
          $finding = $findingEntity->toArray();
          $pluginId = $finding['plugin_id'] ?? 'unknown';
          $findingsByPlugin[$pluginId][] = $finding;
          $allFindings[] = $finding;
        }

      // Order findings within each plugin by severity (high, medium, low) then by title.
      $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
      foreach ($findingsByPlugin as $pluginId => &$findings) {
        usort($findings, function ($a, $b) use ($severityOrder) {
          $severityA = $severityOrder[$a['severity'] ?? 'low'] ?? 2;
          $severityB = $severityOrder[$b['severity'] ?? 'low'] ?? 2;
          if ($severityA !== $severityB) {
            return $severityA <=> $severityB;
          }
          // If same severity, sort by title.
          return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });
      }
      unset($findings);

      // Order agents by label.
      $agentStorage = $this->entityTypeManager->getStorage('ai_agent');
      uksort($findingsByPlugin, function ($agentIdA, $agentIdB) use ($agentStorage) {
        $agentA = $agentStorage->load($agentIdA);
        $agentB = $agentStorage->load($agentIdB);
        $labelA = $agentA ? $agentA->label() : $agentIdA;
        $labelB = $agentB ? $agentB->label() : $agentIdB;
        return strcasecmp((string) $labelA, (string) $labelB);
      });

      // Build summary from findings.
      if (!empty($allFindings)) {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($allFindings as $finding) {
          $severity = $finding['severity'] ?? 'low';
          if (isset($counts[$severity])) {
            $counts[$severity]++;
          }
        }

        $isComplete = $latestRun->isSuccessful();
        $summaryText = $isComplete
          ? $this->t('Analysis complete.')
          : $this->t('Partial results - some plugins have not run yet.');

        $summary = [
          'counts' => $counts,
          'summary' => $summaryText,
        ];
      }
    }

    // Build the render array.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-qa-gate-review']],
    ];

    // Profile info.
    $enabledAgentIds = $profile->getAgentsEnabled();
    $agentStorage = $this->entityTypeManager->getStorage('ai_agent');

    $build['profile'] = [
      '#type' => 'details',
      '#title' => $this->t('QA Profile: @label', ['@label' => $profile->label()]),
      '#open' => FALSE,
      'info' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Target: @type / @bundle', [
            '@type' => $profile->getTargetEntityTypeId(),
            '@bundle' => $profile->getTargetBundle() ?: $this->t('All bundles'),
          ]),
        ],
      ],
    ];

    // Enabled agents list.
    if (!empty($enabledAgentIds)) {
      $build['profile']['agents'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-enabled-agents']],
      ];

      $build['profile']['agents']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Enabled agents:'),
      ];

      $build['profile']['agents']['list'] = [
        '#theme' => 'item_list',
        '#items' => [],
      ];

      foreach ($enabledAgentIds as $agentId) {
        $agentEntity = $agentStorage->load($agentId);
        $agentLabel = $agentEntity ? (string) $agentEntity->label() : $agentId;

        $build['profile']['agents']['list']['#items'][] = [
          '#markup' => '<strong>' . htmlspecialchars($agentLabel) . '</strong>',
          '#allowed_tags' => ['strong'],
        ];
      }
    }
    else {
      $build['profile']['info']['#items'][] = $this->t('Enabled agents: @agents', [
        '@agents' => $this->t('None'),
      ]);
    }

    // Staleness warning.
    if ($isStale) {
      $build['stale_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#markup' => $this->t('Content has changed since the last analysis. The results below may be outdated. Run a new analysis to see current findings.'),
        ],
      ];
    }

    // Latest run info.
    if ($latestRun) {
      $build['run_info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-run-info']],
      ];

      $statusClass = match ($latestRun->getStatus()) {
        'success' => 'color-success',
        'failed' => 'color-error',
        default => 'color-warning',
      };

      $build['run_info']['status'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        'label' => [
          '#markup' => '<strong>' . $this->t('Last run:') . '</strong> ',
        ],
        'status' => [
          '#markup' => '<span class="' . $statusClass . '">' . ucfirst($latestRun->getStatus()) . '</span>',
        ],
        'time' => [
          '#markup' => ' - ' . $this->t('@time ago', [
            '@time' => \Drupal::service('date.formatter')->formatTimeDiffSince($latestRun->getExecutedAt()),
          ]),
        ],
      ];

      if ($latestRun->getProviderId()) {
        $build['run_info']['provider'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Provider: @provider / @model', [
            '@provider' => $latestRun->getProviderId(),
            '@model' => $latestRun->getModel() ?? 'unknown',
          ]),
        ];
      }

      if ($latestRun->getErrorMessage()) {
        $build['run_info']['error'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--error']],
          'message' => [
            '#markup' => $latestRun->getErrorMessage(),
          ],
        ];
      }
    }
    else {
      $build['no_run'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--status']],
        'message' => [
          '#markup' => $this->t('No AI QA analysis has been run for this content yet.'),
        ],
      ];
    }

    // Summary.
    if ($summary) {
      $build['summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-summary']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Summary'),
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $summary['summary'] ?? '',
        ],
        'counts' => [
          '#theme' => 'item_list',
          '#title' => $this->t('Findings by severity'),
          '#items' => [
            $this->t('High: @count', ['@count' => $summary['counts']['high'] ?? 0]),
            $this->t('Medium: @count', ['@count' => $summary['counts']['medium'] ?? 0]),
            $this->t('Low: @count', ['@count' => $summary['counts']['low'] ?? 0]),
          ],
        ],
      ];

      // Add gating status if gating is enabled.
      if ($profile->isGatingEnabled() && $latestRun && $latestRun->isSuccessful()) {
        $gatingSettings = $profile->getGatingSettings();
        $requireAcknowledgement = !empty($gatingSettings['require_acknowledgement']);
        
        if ($requireAcknowledgement && !empty($findingEntities)) {
          $threshold = $profile->getSeverityThreshold();
          $severityOrder = ['low' => 1, 'medium' => 2, 'high' => 3];
          $thresholdValue = $severityOrder[$threshold] ?? 3;
          
          $thresholdFindings = [];
          $acknowledgedCount = 0;
          
          foreach ($findingEntities as $findingEntity) {
            $severity = $findingEntity->getSeverity();
            $severityValue = $severityOrder[$severity] ?? 0;
            
            if ($severityValue >= $thresholdValue) {
              $thresholdFindings[] = $findingEntity;
              if ($findingEntity->isAcknowledged()) {
                $acknowledgedCount++;
              }
            }
          }
          
          $totalThreshold = count($thresholdFindings);
          $unacknowledgedCount = $totalThreshold - $acknowledgedCount;
          
          if ($totalThreshold > 0) {
            $statusClass = $unacknowledgedCount > 0 ? 'messages--warning' : 'messages--status';
            $statusMessage = $this->t('Gating Status: @acknowledged of @total finding(s) at or above @threshold severity threshold acknowledged.', [
              '@acknowledged' => $acknowledgedCount,
              '@total' => $totalThreshold,
              '@threshold' => $threshold,
            ]);
            
            if ($unacknowledgedCount > 0) {
              $statusMessage .= ' ' . $this->t('@remaining remaining must be acknowledged before publishing.', [
                '@remaining' => $unacknowledgedCount,
              ]);
            }
            else {
              $statusMessage .= ' ' . $this->t('All findings acknowledged. Content can be published.');
            }
            
            $build['gating_status'] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['messages', $statusClass]],
              'message' => [
                '#markup' => $statusMessage,
              ],
            ];
          }
        }
      }
    }

    // Findings.
    if (!empty($findingsByPlugin)) {
      $build['findings'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-findings']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Findings'),
        ],
      ];

      foreach ($findingsByPlugin as $pluginId => $findings) {
        $agentEntity = $agentStorage->load($pluginId);
        $pluginLabel = $agentEntity ? $agentEntity->label() : $pluginId;
        $build['findings'][$pluginId] = [
          '#type' => 'details',
          '#title' => $this->t('@plugin (@count)', [
            '@plugin' => $pluginLabel,
            '@count' => count($findings),
          ]),
          '#open' => TRUE,
        ];

        foreach ($findings as $index => $finding) {
          $severityClass = match ($finding['severity'] ?? 'low') {
            'high' => 'color-error',
            'medium' => 'color-warning',
            default => '',
          };

          // Find the corresponding finding entity for acknowledgement status.
          $findingEntity = NULL;
          $findingId = $finding['id'] ?? NULL;
          if ($findingId) {
            foreach ($findingEntities as $fe) {
              if ($fe->id() == $findingId) {
                $findingEntity = $fe;
                break;
              }
            }
          }

          $build['findings'][$pluginId][$index] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-qa-gate-finding', 'finding-' . ($finding['severity'] ?? 'low')]],
            'severity' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => '[' . strtoupper($finding['severity'] ?? 'low') . ']',
              '#attributes' => ['class' => [$severityClass]],
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'strong',
              '#value' => ' ' . ($finding['title'] ?? 'Untitled'),
            ],
            'explanation' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $finding['explanation'] ?? '',
            ],
          ];

          // Add acknowledgement status and action.
          if ($findingEntity instanceof QaFindingInterface) {
            $acknowledged = $findingEntity->isAcknowledged();
            
            if ($acknowledged) {
              $acknowledgedBy = $findingEntity->getAcknowledgedBy();
              $acknowledgedAt = $findingEntity->getAcknowledgedAt();
              $dateFormatter = \Drupal::service('date.formatter');
              
              $ackInfo = $this->t('Acknowledged by @user on @date', [
                '@user' => $acknowledgedBy ? $acknowledgedBy->getDisplayName() : $this->t('Unknown'),
                '@date' => $acknowledgedAt ? $dateFormatter->format($acknowledgedAt) : $this->t('Unknown date'),
              ]);
              
              if ($findingEntity->getAcknowledgementNote()) {
                $ackInfo .= ' - ' . htmlspecialchars($findingEntity->getAcknowledgementNote());
              }
              
              $build['findings'][$pluginId][$index]['acknowledgement_status'] = [
                '#type' => 'html_tag',
                '#tag' => 'p',
                '#value' => '<em class="color-success">✓ ' . $ackInfo . '</em>',
              ];
            }
            else {
              // Show acknowledge button if user has permission.
              if ($this->currentUser()->hasPermission('acknowledge ai qa findings')) {
                $acknowledgeUrl = Url::fromRoute('ai_qa_gate.acknowledge_finding', [
                  'qa_finding' => $findingEntity->id(),
                ]);
                
                $build['findings'][$pluginId][$index]['acknowledge_action'] = [
                  '#type' => 'link',
                  '#title' => $this->t('Acknowledge'),
                  '#url' => $acknowledgeUrl,
                  '#attributes' => [
                    'class' => ['button', 'button--small'],
                  ],
                ];
              }
            }
          }

          // Allow converting this finding into a policy example if permitted.
          if ($findingEntity instanceof QaFindingInterface && $this->currentUser()->hasPermission('convert findings to examples')) {
            $convertUrl = Url::fromRoute('ai_qa_gate.convert_to_example', [
              'qa_finding' => $findingEntity->id(),
            ]);

            $build['findings'][$pluginId][$index]['convert_to_example'] = [
              '#type' => 'link',
              '#title' => $this->t('Convert to policy example'),
              '#url' => $convertUrl,
              '#attributes' => [
                'class' => ['button', 'button--small'],
              ],
            ];
          }

          if (!empty($finding['evidence']['excerpt'])) {
            $build['findings'][$pluginId][$index]['evidence'] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['finding-evidence']],
              'label' => [
                '#markup' => '<em>' . $this->t('Evidence:') . '</em> ',
              ],
              'excerpt' => [
                '#type' => 'html_tag',
                '#tag' => 'blockquote',
                '#value' => htmlspecialchars($finding['evidence']['excerpt']),
              ],
            ];
          }

          if (!empty($finding['suggested_fix'])) {
            $build['findings'][$pluginId][$index]['fix'] = [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => '<em>' . $this->t('Suggested fix:') . '</em> ' . htmlspecialchars($finding['suggested_fix']),
            ];
          }
        }
      }
    }

    // Run buttons.
    $canRun = $this->currentUser()->hasPermission('run ai qa analysis');

    if ($canRun) {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-actions']],
      ];

      // Run All button.
      if ($entity instanceof NodeInterface) {
        $runAllUrl = Url::fromRoute('ai_qa_gate.node_run', ['node' => $entity->id()]);
      }
      else {
        $runAllUrl = Url::fromRoute('ai_qa_gate.entity_run', [
          'entity_type_id' => $entity->getEntityTypeId(),
          'entity' => $entity->id(),
        ]);
      }

      $build['actions']['run_all'] = [
        '#type' => 'link',
        '#title' => $latestRun ? $this->t('Run All Agents Again') : $this->t('Run All Agents'),
        '#url' => $runAllUrl,
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];

      // Per-agent status and buttons.
      $enabledAgents = $profile->getAgentsEnabled();
      $pluginResults = $latestRun ? $latestRun->getPluginResults() : [];

      if (!empty($enabledAgents)) {
        $build['actions']['agents'] = [
          '#type' => 'details',
          '#title' => $this->t('Individual Agent Controls'),
          '#open' => TRUE,
          '#attributes' => ['class' => ['ai-qa-gate-agent-controls']],
        ];

        $build['actions']['agents']['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Agent'),
            $this->t('Status'),
            $this->t('Findings'),
            $this->t('Actions'),
          ],
          '#empty' => $this->t('No agents enabled.'),
        ];

        foreach ($enabledAgents as $agentId) {
          $agentEntity = $agentStorage->load($agentId);
          $agentLabel = $agentEntity ? $agentEntity->label() : $agentId;
          $agentStatus = $pluginResults[$agentId]['status'] ?? 'not_run';
          $agentError = $pluginResults[$agentId]['error'] ?? NULL;

          // Load findings from database for this agent.
          $agentFindingEntities = $latestRun
            ? QaFinding::loadForRun((int) $latestRun->id(), $agentId)
            : [];

          // Status display.
          $statusClass = match ($agentStatus) {
            QaRunInterface::STATUS_SUCCESS => 'color-success',
            QaRunInterface::STATUS_FAILED => 'color-error',
            QaRunInterface::STATUS_PENDING => 'color-warning',
            default => '',
          };
          $statusLabel = match ($agentStatus) {
            QaRunInterface::STATUS_SUCCESS => $this->t('Success'),
            QaRunInterface::STATUS_FAILED => $this->t('Failed'),
            QaRunInterface::STATUS_PENDING => $this->t('Pending'),
            default => $this->t('Not run'),
          };

          // Findings count by severity - load from database entities.
          $findingsDisplay = '-';
          if ($agentStatus === QaRunInterface::STATUS_SUCCESS && !empty($agentFindingEntities)) {
            $high = 0;
            $medium = 0;
            $low = 0;
            foreach ($agentFindingEntities as $findingEntity) {
              $severity = $findingEntity->getSeverity();
              match ($severity) {
                'high' => $high++,
                'medium' => $medium++,
                'low' => $low++,
                default => $low++,
              };
            }
            $parts = [];
            if ($high > 0) {
              $parts[] = '<span class="color-error">' . $high . ' high</span>';
            }
            if ($medium > 0) {
              $parts[] = '<span class="color-warning">' . $medium . ' medium</span>';
            }
            if ($low > 0) {
              $parts[] = $low . ' low';
            }
            $findingsDisplay = $parts ? implode(', ', $parts) : $this->t('None');
          }
          elseif ($agentStatus === QaRunInterface::STATUS_SUCCESS) {
            $findingsDisplay = $this->t('None');
          }
          elseif ($agentError) {
            $findingsDisplay = '<span class="color-error" title="' . htmlspecialchars($agentError) . '">' . $this->t('Error') . '</span>';
          }

          // Run agent URL (uses plugin_id route param which now holds agent_id).
          if ($entity instanceof NodeInterface) {
            $runAgentUrl = Url::fromRoute('ai_qa_gate.node_run_plugin', [
              'node' => $entity->id(),
              'plugin_id' => $agentId,
            ]);
          }
          else {
            $runAgentUrl = Url::fromRoute('ai_qa_gate.entity_run_plugin', [
              'entity_type_id' => $entity->getEntityTypeId(),
              'entity' => $entity->id(),
              'plugin_id' => $agentId,
            ]);
          }

          $build['actions']['agents']['table'][$agentId] = [
            'agent' => [
              '#markup' => '<strong>' . $agentLabel . '</strong>',
            ],
            'status' => [
              '#markup' => '<span class="' . $statusClass . '">' . $statusLabel . '</span>',
            ],
            'findings' => [
              '#markup' => $findingsDisplay,
            ],
            'actions' => [
              '#type' => 'link',
              '#title' => $agentStatus === 'not_run' ? $this->t('Run') : $this->t('Re-run'),
              '#url' => $runAgentUrl,
              '#attributes' => [
                'class' => ['button', 'button--small'],
              ],
            ],
          ];
        }
      }
    }

    // Attach library.
    $build['#attached']['library'][] = 'ai_qa_gate/ai_review';

    return $build;
  }

  /**
   * Access callback for the node review tab.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function nodeAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    return $this->checkAccess($node, $account);
  }

  /**
   * Access callback for the node run form.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function nodeRunAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $viewAccess = $this->checkAccess($node, $account);
    $runAccess = AccessResult::allowedIfHasPermission($account, 'run ai qa analysis');
    return $viewAccess->andIf($runAccess);
  }

  /**
   * Access callback for the generic review route.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(string $entity_type_id, EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    return $this->checkAccess($entity, $account);
  }

  /**
   * Access callback for the generic run route.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function runAccess(string $entity_type_id, EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    $viewAccess = $this->checkAccess($entity, $account);
    $runAccess = AccessResult::allowedIfHasPermission($account, 'run ai qa analysis');
    return $viewAccess->andIf($runAccess);
  }

  /**
   * Checks access to the AI Review tab.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    // Check view permission.
    $hasViewPermission = AccessResult::allowedIfHasPermission($account, 'view ai qa results');

    // Check if entity can be viewed.
    $canViewEntity = $entity->access('view', $account, TRUE);

    // Check if profile exists.
    $profile = $this->profileMatcher->getApplicableProfile($entity);
    $hasProfile = AccessResult::allowedIf($profile !== NULL);
    $hasProfile->addCacheableDependency($entity);
    if ($profile) {
      $hasProfile->addCacheableDependency($profile);
    }

    $result = $hasViewPermission->andIf($canViewEntity)->andIf($hasProfile);
    // Ensure proper cache contexts to avoid stale access results.
    $result->cachePerPermissions();
    $result->addCacheContexts(['user']);
    
    return $result;
  }

}

