# AI QA Gate

A configurable AI-powered Content QA gate framework for Drupal 10/11. Provides pluggable report analyzers and optional content moderation gating.

## Features

- **Generic Analyzer Framework**: Run AI-powered QA analysis on any entity type with configurable field selection
- **Pluggable Report Types**: Built-in plugins for claims/policy accuracy, tone/neutrality, accessibility/clarity, and PII/policy compliance
- **AI Review Tab**: Local task tab showing analysis results with severity-based findings
- **Content Moderation Gating**: Optionally block workflow transitions when findings exceed severity thresholds
- **Audit Trail**: Stores all analysis results with revision tracking and staleness detection
- **Provider Agnostic**: Works with any AI provider configured through the AI Core module

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1+

### Optional Dependencies

- **AI Module (drupal/ai)**: Required for actual AI analysis. Without it, the module will install but analysis will be disabled.
- **Content Moderation**: Required for gating features

## Installation

1. Install the module:
   ```bash
   composer require drupal/ai  # If not already installed
   drush en ai_qa_gate
   ```

2. Configure an AI provider:
   - Navigate to **Configuration > AI > AI Settings**
   - Configure a provider (e.g., OpenRouter, OpenAI)
   - Set a default provider for "chat" operations

3. Create a QA Profile:
   - Navigate to **Configuration > AI QA Gate > Profiles**
   - Click "Add QA Profile"
   - Select target entity type and bundle
   - Choose fields to analyze
   - Enable desired report plugins
   - Optionally attach policies

## Configuration

### QA Profiles

Profiles define:
- **Target**: Which entity type and bundle to analyze
- **Fields**: Which fields to include in the analysis
- **Reports**: Which report plugins to run
- **Policies**: Which policy contexts to inject
- **AI Settings**: Override default provider/model settings
- **Execution**: Queue vs sync mode
- **Gating**: Content moderation blocking rules

### QA Policies

Policies provide reusable context injected into AI prompts:
- Policy guidelines text
- Good and bad examples
- Disallowed phrases
- Required disclaimers

Default policies are provided for EC digital policy communications.

### Permissions

- `administer ai qa gate`: Manage profiles and policies
- `view ai qa results`: View the AI Review tab
- `run ai qa analysis`: Trigger analysis runs
- `override ai qa gate`: Bypass gating blocks

## Usage

### Running Analysis

1. Navigate to any entity with a matching QA profile
2. Click the "AI Review" tab
3. Click "Run AI QA Analysis"
4. Review the findings

### Understanding Results

Results are organized by:
- **Category**: Claims, tone, accessibility, PII, etc.
- **Severity**: High, Medium, Low

Each finding includes:
- Title and explanation
- Evidence excerpt from the content
- Suggested fix (when available)
- Confidence score

### Content Moderation Gating

When gating is enabled on a profile:
1. Users attempting blocked transitions will see an error
2. They must run QA analysis first
3. If findings exceed the threshold, the transition is blocked
4. Users with "override" permission can bypass the block

## Built-in Report Plugins

### Claims & Regulatory Precision
Analyzes content for:
- Absolute claims requiring caveats
- Legislative status accuracy
- Legal interpretation issues
- Attribution accuracy

### Tone & Neutrality
Analyzes content for:
- Promotional language
- Political bias
- Emotional appeals
- Self-congratulatory language

### Accessibility & Clarity
Analyzes content for:
- Unexpanded acronyms
- Technical jargon
- Sentence complexity
- General readability

### PII & Policy Compliance
Analyzes content for:
- Personal information exposure
- Contact information
- Internal references
- Confidential markers

## Extending

### Custom Report Plugins

Create custom report plugins by:

1. Create a class in `src/Plugin/QaReport/`
2. Use the `#[QaReport]` attribute
3. Extend `QaReportPluginBase`
4. Implement `buildPrompt()` and optionally override `parseResponse()`

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

  public function buildPrompt(array $context, array $configuration): array {
    return [
      'system_message' => 'You are an expert reviewer...',
      'user_message' => 'Analyze: ' . $context['combined_text'],
    ];
  }

}
```

### API Usage

```php
// Get the runner service
$runner = \Drupal::service('ai_qa_gate.runner');

// Run analysis
$qaRun = $runner->run($entity, 'my_profile_id');

// Get latest run
$latestRun = $runner->getLatestRun($entity, 'my_profile_id');

// Check results
if ($latestRun->isSuccessful()) {
  $findings = $latestRun->getFindings();
  $maxSeverity = $latestRun->getMaxSeverity();
}
```

## Results Schema

Results follow schema version 1.0:

```json
{
  "schema_version": "1.0",
  "entity": {
    "type": "node",
    "id": "123",
    "revision": "456",
    "bundle": "page",
    "langcode": "en"
  },
  "profile_id": "my_profile",
  "generated_at": "2024-01-15T10:30:00+00:00",
  "overall": {
    "max_severity": "medium",
    "counts": {"high": 0, "medium": 2, "low": 5},
    "summary": "Found 2 medium, 5 low severity issue(s)."
  },
  "findings": [
    {
      "category": "claims",
      "severity": "medium",
      "title": "Overstated claim",
      "explanation": "The phrase 'guarantees safety' makes an absolute claim...",
      "evidence": {
        "field": "body",
        "excerpt": "This regulation guarantees user safety online.",
        "start": 150,
        "end": 195
      },
      "suggested_fix": "Consider: 'aims to enhance user safety'",
      "confidence": 0.85
    }
  ]
}
```

## Troubleshooting

### Analysis not running
- Check that the AI module is installed and configured
- Verify a default chat provider is set
- Check the status report at `/admin/reports/status`

### Queue not processing
- Run cron: `drush cron`
- Or process manually: `drush queue:run ai_qa_gate_run_worker`

### Gating not blocking
- Ensure Content Moderation is enabled
- Check the profile has gating enabled
- Verify transition IDs match (use format: `draft__published` or just `published`)

## License

This project is licensed under the GPL-2.0-or-later license.

