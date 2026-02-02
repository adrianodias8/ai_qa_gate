# AI QA Gate

A configurable AI-powered content QA gate framework for Drupal 10/11. Uses the
`ai_agents` module for AI execution and `ai_context` for per-agent policy
management. Provides pluggable report analyzers, a finding-to-example feedback
loop, and optional content moderation gating.

## Features

- **Agent-Centric Architecture**: Each QA report plugin is bound to an
  `ai_agent` config entity via `third_party_settings`. Plugin selection and
  configuration live on the agent edit form -- no duplication in profiles.
- **Pluggable Report Analyzers**: Built-in plugins for claims/policy accuracy,
  tone/neutrality, accessibility/clarity, and PII/policy compliance.
- **Per-Agent Policy Management**: Policies stored as `ai_context` entities,
  assigned per-agent via the ai_context pool admin UI. The agent's
  `system_prompt` holds the real analysis instructions baked from the plugin.
- **Finding-to-Example Feedback Loop**: Convert correct findings into policy
  examples; mark false positives as exclusions -- both feed back into the
  agent's context pool.
- **AI Review Tab**: Local task tab on entities showing analysis results with
  severity-based findings.
- **Content Moderation Gating**: Optionally block workflow transitions when
  findings exceed severity thresholds.
- **Acknowledgement Workflow**: Findings can be individually acknowledged to
  satisfy gating requirements.
- **Audit Trail**: Stores all analysis results with revision tracking and
  staleness detection.

## Requirements

- Drupal 10.x or 11.x
- PHP 8.3+
- **AI Module** (`drupal/ai`): Required for AI provider configuration.
- **AI Agents** (`drupal/ai_agents`): Required for agent-based execution.
- **AI Context** (`drupal/ai_context`): Required for policy/context management.

### Optional Dependencies

- **Content Moderation**: Required for gating features.

## Installation

1. Install the module and its dependencies:
   ```bash
   composer require drupal/ai drupal/ai_agents drupal/ai_context
   drush en ai_qa_gate
   ```

2. Configure an AI provider:
   - Navigate to **Configuration > AI > AI Settings**.
   - Configure a provider (e.g., Mistral, OpenRouter).
   - Set a default provider for "chat" operations.

3. Create a QA Profile:
   - Navigate to **Configuration > AI QA Gate > Profiles**.
   - Click "Add QA Profile".
   - Select target entity type and bundle.
   - Choose fields to analyze.
   - Enable the desired agents (only agents with a QA Report plugin configured
     appear).
   - Configure execution and gating settings.

## Architecture

### Agent-Centric Model

Each `ai_agent` config entity stores its QA report plugin binding in
`third_party_settings['ai_qa_gate']`:

```yaml
third_party_settings:
  ai_qa_gate:
    qa_report_plugin_id: tone_neutrality_institutional
    qa_report_configuration:
      check_promotional_language: true
      check_political_bias: true
      check_emotional_language: true
      check_self_congratulatory: true
```

The agent's `system_prompt` field contains the full analysis instructions from
the plugin's `buildSystemMessage()` method. This means:

- The agent is the single source of truth for its plugin, configuration, and
  system prompt.
- Admins manage everything from the AI Agent edit form (plugin selector, config
  checkboxes, optional "sync system prompt from plugin" action).
- QA Profiles simply store a list of enabled agent IDs (`agents_enabled`).
- The Runner never overrides the agent's system prompt at runtime.

### Entity Flow

```
QaProfile (config entity)
  defines: target entity type/bundle, fields, enabled agent IDs, gating rules
      |
      v
QaRun (content entity)
  stores: execution status, timestamps, per-agent results, input hash
      |
      v
QaFinding (content entity)
  stores: per-finding data (title, severity, evidence, explanation,
          suggested fix, acknowledgement status)
```

### Service Layer

- **Runner** (`ai_qa_gate.runner`): Orchestrates analysis. For each enabled
  agent: loads the agent entity, reads the QA Report plugin ID and
  configuration from `third_party_settings`, creates the plugin instance,
  builds the user message, executes the agent (without overriding its system
  prompt), and persists findings.
- **ContextBuilder** (`ai_qa_gate.context_builder`): Extracts text content and
  metadata from entities based on the profile's field configuration.
- **ProfileMatcher** (`ai_qa_gate.profile_matcher`): Finds the applicable
  QA profile for an entity.
- **GatingService** (`ai_qa_gate.gating`): Evaluates whether content
  moderation transitions should be blocked based on findings.

### Integration with ai_agents

Each QA report plugin type has a dedicated `ai_agent` config entity:

| Agent ID | Report Plugin | Purpose |
|----------|--------------|---------|
| `qa_gate_tone` | `tone_neutrality_institutional` | Tone & neutrality analysis |
| `qa_gate_claims` | `claims_regulatory_precision` | Claims & regulatory precision |
| `qa_gate_accessibility` | `accessibility_clarity` | Accessibility & clarity |
| `qa_gate_pii` | `pii_policy` | PII & policy compliance |

All agents share the same structured output JSON schema that produces
normalized findings. Each agent is configured with:
- `max_loops: 1` -- single-shot analysis, no iteration.
- `structured_output_enabled: true` -- enforces valid JSON output.
- `tools: {}` -- no tools, pure analysis.
- `secured_system_prompt: '[ai_agent:agent_instructions]'` -- token-based
  prompt injection from ai_context.

**Execution flow per agent:**

1. `ContextBuilder.buildContext(entity, profile)` -- extracts field text and
   metadata.
2. Runner loads agent entity, reads `qa_report_plugin_id` and
   `qa_report_configuration` from `third_party_settings`.
3. `plugin.buildUserMessage(context, config)` -- builds the user message
   containing the content to analyze.
4. `AiAgentManager.createInstance(agent_id)` -- creates agent wrapper. The
   agent's `system_prompt` is used as-is (not overridden).
5. `agent.setTask(new Task(userMessage))` -- sets the user message as the task.
6. `agent.determineSolvability()` -- fires `BuildSystemPromptEvent`, which
   triggers ai_context's `SystemPromptSubscriber` to inject the agent's policy
   pool into the prompt.
7. `agent.answerQuestion()` -- calls the AI provider and returns the response.
8. `plugin.parseResponse(response, config)` -- normalizes findings.

### Integration with ai_context

Policies are stored as `ai_context` entities with markdown content. They are
organized into per-agent pools via the `ai_context.agent_pools` configuration.

When the Runner executes an agent, ai_context automatically injects the
relevant policies through the `BuildSystemPromptEvent`. This means:
- Each agent only sees the policies assigned to its pool.
- Admins can reassign policies per agent via the ai_context pools UI.
- The feedback loop (examples + exclusions) automatically takes effect on
  the next analysis run.

**Default policy contexts (installed with module):**

| Context ID | Description |
|-----------|-------------|
| `qa_ec_neutral_tone` | EC neutral tone policy |
| `qa_ec_digital_policy_baseline` | EC digital policy baseline |
| `qa_ec_legislative_precision` | EC legislative precision policy |
| `qa_ec_accessibility_clarity` | EC accessibility clarity policy |
| `qa_style_guide_accessibility_inclusivity` | Style guide: accessibility & inclusivity |
| `qa_style_guide_tone_voice` | Style guide: tone & voice |
| `qa_style_guide_seo_formatting` | Style guide: SEO & formatting |
| `qa_style_guide_terminology_clarity` | Style guide: terminology & clarity |

**Exclusion contexts (one per agent):**

| Context ID | Agent |
|-----------|-------|
| `qa_gate_tone_exclusions` | `qa_gate_tone` |
| `qa_gate_claims_exclusions` | `qa_gate_claims` |
| `qa_gate_accessibility_exclusions` | `qa_gate_accessibility` |
| `qa_gate_pii_exclusions` | `qa_gate_pii` |

### Per-Agent Policy Assignment

Each agent's context pool determines which policies are injected into that
agent's analysis. Default pool assignments:

| Agent | Default Pool |
|-------|-------------|
| `qa_gate_tone` | `qa_ec_neutral_tone`, `qa_style_guide_tone_voice`, `qa_gate_tone_exclusions` |
| `qa_gate_claims` | `qa_ec_legislative_precision`, `qa_ec_digital_policy_baseline`, `qa_gate_claims_exclusions` |
| `qa_gate_accessibility` | `qa_ec_accessibility_clarity`, `qa_style_guide_accessibility_inclusivity`, `qa_style_guide_terminology_clarity`, `qa_gate_accessibility_exclusions` |
| `qa_gate_pii` | `qa_gate_pii_exclusions` |

To reassign policies:
- Navigate to **Configuration > AI > AI Context > Context Pools**.
- Edit the pool for the desired agent (e.g., `qa_gate_tone`).
- Add or remove context entities.

## Configuration

### AI Agent Form Integration

When editing an AI Agent entity, the module adds a **QA Report Plugin**
fieldset via `hook_form_alter`. This fieldset contains:

- **Plugin selector**: Dropdown of all available QA Report plugins (or
  "- None -" to detach the agent from QA Gate).
- **Plugin configuration**: Plugin-specific checkboxes and settings (loaded
  via AJAX when the plugin selection changes).
- **Sync system prompt from plugin**: Checkbox that, when checked and saved,
  copies the plugin's `buildSystemMessage()` output into the agent's
  `system_prompt` field.

### QA Profiles

Profiles define:
- **Target**: Which entity type and bundle to analyze.
- **Fields**: Which fields to include in the analysis (with weight, label,
  HTML stripping options).
- **Agents**: Which agents to run (only agents with a QA Report plugin
  configured appear as options).
- **Execution**: Queue vs sync mode, backoff settings.
- **Gating**: Content moderation blocking rules, severity threshold,
  acknowledgement requirements.

### Permissions

| Permission | Description |
|-----------|-------------|
| `administer ai qa gate` | Manage profiles and settings |
| `view ai qa results` | View the AI Review tab |
| `run ai qa analysis` | Trigger analysis runs |
| `acknowledge ai qa findings` | Acknowledge individual findings |
| `convert findings to examples` | Convert findings to policy examples or exclusions |
| `override ai qa gate` | Bypass gating blocks |

## Usage

### Running Analysis

1. Navigate to any entity with a matching QA profile.
2. Click the **AI Review** tab.
3. Click **Run All Agents** (or run individual agents from the controls table).
4. Review the findings organized by agent and severity.

### Understanding Results

Each finding includes:
- **Severity**: High, Medium, or Low.
- **Title** and **explanation**.
- **Evidence excerpt** from the content.
- **Suggested fix** (when available).
- **Confidence score**.

### Finding-to-Example Feedback Loop

From the AI Review tab, each finding has a **Convert to policy example** action
(requires `convert findings to examples` permission):

1. **Correct finding** -- Select "Correct finding -- add as policy example".
   Choose which policy context to append to. The finding's evidence and
   explanation are added as a new example, teaching the AI to continue flagging
   similar patterns.

2. **Incorrect finding (false positive)** -- Select "Incorrect finding --
   mark as false positive". The finding is added to the agent's exclusions
   context, teaching the AI to stop flagging that pattern.

Both actions immediately take effect on the next analysis run since the
contexts are injected via the agent's pool.

### Content Moderation Gating

When gating is enabled on a profile:
1. Users attempting blocked transitions see an error.
2. They must run QA analysis first.
3. If findings exceed the severity threshold, the transition is blocked.
4. Findings can be individually **acknowledged** to satisfy gating.
5. Users with the `override ai qa gate` permission can bypass the block.

### Queue vs Sync Execution

Profiles can be configured for:
- **Sync mode**: Analysis runs immediately in the request. Best for quick
  feedback during editing.
- **Queue mode**: Each agent is queued as a separate item for background
  processing with retry logic and exponential backoff. Best for large content
  or rate-limited providers.

Process the queue with:
```bash
drush queue:run ai_qa_gate_plugin_worker
drush queue:run ai_qa_gate_run_worker
```

## Built-in Report Plugins

### Tone & Neutrality (`tone_neutrality_institutional`)
- Promotional language
- Political bias
- Emotional appeals
- Self-congratulatory language

### Claims & Regulatory Precision (`claims_regulatory_precision`)
- Absolute claims requiring caveats
- Legislative status accuracy
- Legal interpretation issues
- Attribution accuracy

### Accessibility & Clarity (`accessibility_clarity`)
- Unexpanded acronyms
- Technical jargon
- Sentence complexity
- General readability

### PII & Policy Compliance (`pii_policy`)
- Personal information exposure
- Contact information
- Internal references
- Confidential markers

## Extending

### Custom Report Plugins

1. Create a class in `src/Plugin/QaReport/`.
2. Use the `#[QaReport]` attribute.
3. Extend `QaReportPluginBase`.
4. Implement `buildSystemMessage()` and `buildUserMessage()`.

```php
<?php

namespace Drupal\my_module\Plugin\QaReport;

use Drupal\ai_qa_gate\Attribute\QaReport;
use Drupal\ai_qa_gate\Plugin\QaReport\QaReportPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[QaReport(
  id: 'my_custom_report',
  label: new TranslatableMarkup('My Custom Report'),
  description: new TranslatableMarkup('Analyzes content for custom criteria.'),
  category: 'custom',
)]
class MyCustomReport extends QaReportPluginBase {

  public function buildSystemMessage(): string {
    return 'You are an expert reviewer specializing in ...';
  }

  public function buildUserMessage(array $context, array $configuration): string {
    return 'Analyze the following content: ' . $context['combined_text'];
  }

}
```

To integrate with ai_agents:
1. Create an `ai_agent` config entity for your plugin.
2. On the agent edit form, select your plugin from the "QA Report Plugin"
   dropdown and check "Sync system prompt from plugin" to populate the agent's
   `system_prompt` field.
3. Create `ai_context` entities for your agent's policies.
4. Register a pool entry mapping the agent to its contexts.
5. Enable the agent in a QA Profile.

### API Usage

```php
// Get the runner service.
$runner = \Drupal::service('ai_qa_gate.runner');

// Run all agents for an entity.
$qaRun = $runner->run($entity, 'my_profile_id');

// Run a single agent.
$qaRun = $runner->runAgent($entity, 'my_profile_id', 'qa_gate_tone');

// Queue all agents for background processing.
$qaRun = $runner->queueAllAgents($entity, 'my_profile_id');

// Get latest run.
$latestRun = $runner->getLatestRun($entity, 'my_profile_id');

// Check results.
if ($latestRun->isSuccessful()) {
  $findings = QaFinding::loadForRun((int) $latestRun->id());
  $maxSeverity = $latestRun->getMaxSeverity();
}
```

## Admin UI Paths

| Path | Description |
|------|-------------|
| `/admin/config/ai-qa-gate/profiles` | QA profile management |
| `/admin/config/ai-qa-gate/settings` | Module settings |
| `/admin/config/ai/contexts` | AI context entity management |
| `/admin/config/ai/ai-context/pools/{agent}/edit` | Per-agent context pool configuration |
| `/admin/config/ai/agents` | AI agent entity management |

## Troubleshooting

### Analysis not running
- Check that the AI module is installed and configured.
- Verify a default chat provider is set at **Configuration > AI > AI Settings**.
- Ensure the agents enabled in the profile have a QA Report plugin configured
  (check the agent edit form for the "QA Report Plugin" fieldset).
- Check the status report at `/admin/reports/status`.

### Queue not processing
- Run cron: `drush cron`
- Or process manually: `drush queue:run ai_qa_gate_plugin_worker`

### Gating not blocking
- Ensure Content Moderation is enabled.
- Check the profile has gating enabled.
- Verify transition IDs match (use format: `draft__published` or just
  `published`).

### Findings not reflecting policy changes
- Ensure the policy context is assigned to the correct agent's pool.
- Verify the context content has been saved.
- Run a new analysis (cached results won't reflect policy changes).

### Agent has no QA Report plugin
- Edit the agent at **Configuration > AI > Agents**.
- In the "QA Report Plugin" fieldset, select the desired plugin.
- Check "Sync system prompt from plugin" if you want the agent's system prompt
  updated from the plugin.
- Save the agent.

## License

This project is licensed under the GPL-2.0-or-later license.
