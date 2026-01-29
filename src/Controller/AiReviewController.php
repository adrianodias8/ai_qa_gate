<?php

declare(strict_types=1);

namespace Drupal\ai_qa_gate\Controller;

use Drupal\ai_qa_gate\AiClient\AiClientInterface;
use Drupal\ai_qa_gate\Entity\QaFinding;
use Drupal\ai_qa_gate\Entity\QaFindingInterface;
use Drupal\ai_qa_gate\Entity\QaRunInterface;
use Drupal\ai_qa_gate\QaReportPluginManager;
use Drupal\ai_qa_gate\Service\ContextBuilderInterface;
use Drupal\ai_qa_gate\Service\ProfileMatcher;
use Drupal\ai_qa_gate\Service\RunnerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
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
   * @param \Drupal\ai_qa_gate\AiClient\AiClientInterface $aiClient
   *   The AI client.
   * @param \Drupal\ai_qa_gate\QaReportPluginManager $reportPluginManager
   *   The report plugin manager.
   */
  public function __construct(
    protected readonly ProfileMatcher $profileMatcher,
    protected readonly RunnerInterface $runner,
    protected readonly ContextBuilderInterface $contextBuilder,
    protected readonly AiClientInterface $aiClient,
    protected readonly QaReportPluginManager $reportPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_qa_gate.profile_matcher'),
      $container->get('ai_qa_gate.runner'),
      $container->get('ai_qa_gate.context_builder'),
      $container->get('ai_qa_gate.ai_client'),
      $container->get('plugin.manager.qa_report'),
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

    // Check AI availability.
    $aiAvailable = $this->aiClient->isAvailable();
    $aiMessage = $aiAvailable ? NULL : $this->aiClient->getUnavailableMessage();

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

      // Order plugins by weight (from plugin definition) then by label.
      $pluginDefinitions = $this->reportPluginManager->getDefinitions();
      uksort($findingsByPlugin, function ($pluginIdA, $pluginIdB) use ($pluginDefinitions) {
        $defA = $pluginDefinitions[$pluginIdA] ?? [];
        $defB = $pluginDefinitions[$pluginIdB] ?? [];
        $weightA = $defA['weight'] ?? 0;
        $weightB = $defB['weight'] ?? 0;
        if ($weightA !== $weightB) {
          return $weightA <=> $weightB;
        }
        // If same weight, sort by label.
        $labelA = $defA['label'] ?? $pluginIdA;
        $labelB = $defB['label'] ?? $pluginIdB;
        return strcasecmp($labelA, $labelB);
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

    // AI availability warning.
    if (!$aiAvailable) {
      $build['ai_warning'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'message' => [
          '#markup' => $aiMessage,
        ],
      ];
    }

    // Profile info.
    $enabledPluginIds = $profile->getEnabledReportPluginIds();
    $pluginDefinitions = $this->reportPluginManager->getDefinitions();
    
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

    // Enabled reports with their settings.
    if (!empty($enabledPluginIds)) {
      $build['profile']['reports'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-qa-gate-enabled-reports']],
      ];

      $build['profile']['reports']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Enabled reports:'),
      ];

      $build['profile']['reports']['list'] = [
        '#theme' => 'item_list',
        '#items' => [],
      ];

      foreach ($enabledPluginIds as $pluginId) {
        $pluginLabel = $pluginDefinitions[$pluginId]['label'] ?? $pluginId;
        $pluginConfig = $profile->getReportPluginConfiguration($pluginId);
        
        // Format the settings for display.
        $settingsText = $this->formatPluginSettings($pluginId, $pluginConfig, $pluginDefinitions);
        
        // Convert TranslatableMarkup to string.
        $pluginLabelString = (string) $pluginLabel;
        $settingsTextString = (string) $settingsText;
        
        // Build item content.
        $itemContent = '<strong>' . htmlspecialchars($pluginLabelString) . '</strong>';
        if (!empty($settingsTextString)) {
          // Settings text is safe - it's built from translation calls with escaped placeholders.
          $itemContent .= '<br><em>' . $this->t('Settings:') . '</em><br>' . $settingsTextString;
        }
        
        $build['profile']['reports']['list']['#items'][] = [
          '#markup' => $itemContent,
          '#allowed_tags' => ['strong', 'em', 'br'],
        ];
      }
    }
    else {
      $build['profile']['info']['#items'][] = $this->t('Enabled reports: @reports', [
        '@reports' => $this->t('None'),
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

      $pluginDefinitions = $this->reportPluginManager->getDefinitions();
      foreach ($findingsByPlugin as $pluginId => $findings) {
        $pluginLabel = $pluginDefinitions[$pluginId]['label'] ?? $pluginId;
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
    $canRun = $this->currentUser()->hasPermission('run ai qa analysis') && $aiAvailable;

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
        '#title' => $latestRun ? $this->t('Run All Plugins Again') : $this->t('Run All Plugins'),
        '#url' => $runAllUrl,
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];

      // Per-plugin status and buttons.
      $enabledPlugins = $profile->getEnabledReportPluginIds();
      $pluginDefinitions = $this->reportPluginManager->getDefinitions();
      $pluginResults = $latestRun ? $latestRun->getPluginResults() : [];

      if (!empty($enabledPlugins)) {
        $build['actions']['plugins'] = [
          '#type' => 'details',
          '#title' => $this->t('Individual Plugin Controls'),
          '#open' => TRUE,
          '#attributes' => ['class' => ['ai-qa-gate-plugin-controls']],
        ];

        $build['actions']['plugins']['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('Plugin'),
            $this->t('Status'),
            $this->t('Findings'),
            $this->t('Actions'),
          ],
          '#empty' => $this->t('No plugins enabled.'),
        ];

        foreach ($enabledPlugins as $pluginId) {
          $pluginLabel = $pluginDefinitions[$pluginId]['label'] ?? $pluginId;
          $pluginStatus = $pluginResults[$pluginId]['status'] ?? 'not_run';
          $pluginError = $pluginResults[$pluginId]['error'] ?? NULL;

          // Load findings from database for this plugin.
          $pluginFindingEntities = $latestRun
            ? QaFinding::loadForRun((int) $latestRun->id(), $pluginId)
            : [];

          // Status display.
          $statusClass = match ($pluginStatus) {
            QaRunInterface::STATUS_SUCCESS => 'color-success',
            QaRunInterface::STATUS_FAILED => 'color-error',
            QaRunInterface::STATUS_PENDING => 'color-warning',
            default => '',
          };
          $statusLabel = match ($pluginStatus) {
            QaRunInterface::STATUS_SUCCESS => $this->t('Success'),
            QaRunInterface::STATUS_FAILED => $this->t('Failed'),
            QaRunInterface::STATUS_PENDING => $this->t('Pending'),
            default => $this->t('Not run'),
          };

          // Findings count by severity - load from database entities.
          $findingsDisplay = '-';
          if ($pluginStatus === QaRunInterface::STATUS_SUCCESS && !empty($pluginFindingEntities)) {
            $high = 0;
            $medium = 0;
            $low = 0;
            foreach ($pluginFindingEntities as $findingEntity) {
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
          elseif ($pluginStatus === QaRunInterface::STATUS_SUCCESS) {
            $findingsDisplay = $this->t('None');
          }
          elseif ($pluginError) {
            $findingsDisplay = '<span class="color-error" title="' . htmlspecialchars($pluginError) . '">' . $this->t('Error') . '</span>';
          }

          // Run plugin URL.
          if ($entity instanceof NodeInterface) {
            $runPluginUrl = Url::fromRoute('ai_qa_gate.node_run_plugin', [
              'node' => $entity->id(),
              'plugin_id' => $pluginId,
            ]);
          }
          else {
            $runPluginUrl = Url::fromRoute('ai_qa_gate.entity_run_plugin', [
              'entity_type_id' => $entity->getEntityTypeId(),
              'entity' => $entity->id(),
              'plugin_id' => $pluginId,
            ]);
          }

          $build['actions']['plugins']['table'][$pluginId] = [
            'plugin' => [
              '#markup' => '<strong>' . $pluginLabel . '</strong>',
            ],
            'status' => [
              '#markup' => '<span class="' . $statusClass . '">' . $statusLabel . '</span>',
            ],
            'findings' => [
              '#markup' => $findingsDisplay,
            ],
            'actions' => [
              '#type' => 'link',
              '#title' => $pluginStatus === 'not_run' ? $this->t('Run') : $this->t('Re-run'),
              '#url' => $runPluginUrl,
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

  /**
   * Formats plugin settings for display.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $configuration
   *   The plugin configuration.
   * @param array $plugin_definitions
   *   All plugin definitions.
   *
   * @return string
   *   Formatted settings string.
   */
  protected function formatPluginSettings(string $plugin_id, array $configuration, array $plugin_definitions): string {
    if (empty($configuration)) {
      return '';
    }

    // Filter out default/common settings that aren't meaningful to display.
    $defaultSettings = ['enabled', 'severity_weight'];
    $settings = [];
    
    foreach ($configuration as $key => $value) {
      // Skip default settings.
      if (in_array($key, $defaultSettings, TRUE)) {
        continue;
      }

      // Format boolean values (including 1/0 from checkboxes, as int or string).
      if (is_bool($value) || $value === 1 || $value === 0 || $value === '1' || $value === '0') {
        $boolValue = is_bool($value) ? $value : (bool) (int) $value;
        $settings[] = $this->t('@key: @value', [
          '@key' => $this->formatSettingKey($key),
          '@value' => $boolValue ? $this->t('Yes') : $this->t('No'),
        ]);
      }
      // Format numeric values (excluding 1/0 which are handled as booleans).
      elseif (is_numeric($value) && (int) $value !== 1 && (int) $value !== 0) {
        $settings[] = $this->t('@key: @value', [
          '@key' => $this->formatSettingKey($key),
          '@value' => $value,
        ]);
      }
      // Format string values.
      elseif (is_string($value) && !empty($value)) {
        $settings[] = $this->t('@key: @value', [
          '@key' => $this->formatSettingKey($key),
          '@value' => $value,
        ]);
      }
    }

    return !empty($settings) ? (string) implode('<br>', $settings) : '';
  }

  /**
   * Formats a setting key for display.
   *
   * @param string $key
   *   The setting key.
   *
   * @return string
   *   Formatted key.
   */
  protected function formatSettingKey(string $key): string {
    // Convert snake_case to Title Case.
    $formatted = str_replace('_', ' ', $key);
    $formatted = ucwords($formatted);
    return $formatted;
  }

}

