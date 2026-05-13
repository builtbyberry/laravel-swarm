# Guardrails Policy

Shows how to attach input, step, and output guardrails to a swarm to enforce
policy at every phase of orchestration.

Use this pattern when a workflow must validate what it accepts (input), what
intermediate agents produce (step), and what it delivers (output) before any
of those outcomes are persisted or dispatched.

This example teaches:

- `SwarmInputGuardrail` blocks a run before any agent fires;
- `SwarmStepGuardrail` intercepts each agent output before the step is recorded;
- `SwarmOutputGuardrail` validates the final result before completion is persisted;
- `DefinesGuardrails` wires per-swarm guardrails via the container;
- `GuardrailViolation::block()` is the only way to signal a policy failure;
- `$e->policyCode` (not `$e->code`) identifies the violation in catch blocks;
- global config-based guardrails apply to every swarm in the application.

## Prerequisites

- Laravel AI is configured with at least one provider and model.
- `builtbyberry/laravel-swarm` is installed.
- No queue worker or database persistence is required for synchronous use.

## Files To Create

### `app/Ai/Guardrails/TopicAllowlistGuardrail.php`

Blocks runs whose topic is not on the allow list. Runs at dispatch time for
every execution mode; for `queue()` and `broadcastOnQueue()`, it also runs
again inside the worker (see [Guardrails: queued double-validation](../../docs/guardrails.md#queued-execution--input-guardrails-run-twice)).

```php
<?php

namespace App\Ai\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmInputGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class TopicAllowlistGuardrail implements SwarmInputGuardrail
{
    private const ALLOWED = ['product', 'engineering', 'company', 'open-source'];

    public function validate(RunContext $context): void
    {
        $topic = is_array($context->task) ? ($context->task['topic'] ?? '') : '';

        if ($topic === '') {
            throw GuardrailViolation::block(
                policyCode: 'missing_topic',
                reason: 'A topic is required.',
            );
        }

        $normalized = strtolower($topic);
        foreach (self::ALLOWED as $allowed) {
            if (str_contains($normalized, $allowed)) {
                return;
            }
        }

        throw GuardrailViolation::block(
            policyCode: 'topic_not_allowed',
            reason: "Topic '{$topic}' is not on the approved list.",
            metadata: ['topic' => $topic, 'allowed' => self::ALLOWED],
        );
    }
}
```

### `app/Ai/Guardrails/NoPlaceholderStepGuardrail.php`

Catches degenerate step output — placeholder text that would corrupt later
agents — before the step row is written to history.

```php
<?php

namespace App\Ai\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmStepGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

class NoPlaceholderStepGuardrail implements SwarmStepGuardrail
{
    private const PATTERNS = ['[INSERT ', '[TODO]', 'TODO:', 'PLACEHOLDER'];

    public function validate(GuardrailStepContext $context): void
    {
        foreach (self::PATTERNS as $pattern) {
            if (str_contains($context->output, $pattern)) {
                throw GuardrailViolation::block(
                    policyCode: 'incomplete_step_output',
                    reason: 'Step output contains placeholder text that would corrupt downstream agents.',
                    metadata: [
                        'agent'      => $context->agentClass,
                        'step_index' => $context->stepIndex,
                    ],
                );
            }
        }
    }
}
```

### `app/Ai/Guardrails/OutputLengthGuardrail.php`

Rejects a final output that exceeds the configured character cap. Enforces the
policy before `SwarmCompleted` is dispatched and history is written.

```php
<?php

namespace App\Ai\Guardrails;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmOutputGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class OutputLengthGuardrail implements SwarmOutputGuardrail
{
    public function __construct(private readonly int $maxChars = 4000) {}

    public function validate(RunContext $context, string $output): void
    {
        $length = strlen($output);

        if ($length > $this->maxChars) {
            throw GuardrailViolation::block(
                policyCode: 'output_too_long',
                reason: sprintf(
                    'Final output (%d chars) exceeds the %d-char policy limit.',
                    $length,
                    $this->maxChars,
                ),
                metadata: ['length' => $length, 'limit' => $this->maxChars],
            );
        }
    }
}
```

### `app/Ai/Swarms/GuardedContentPipeline.php`

Implements `DefinesGuardrails` to wire the guardrails above. The runner merges
these with any global guardrails registered in `config/swarm.php`.

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ArticleEditor;
use App\Ai\Agents\ArticlePlanner;
use App\Ai\Agents\ArticleWriter;
use App\Ai\Guardrails\NoPlaceholderStepGuardrail;
use App\Ai\Guardrails\OutputLengthGuardrail;
use App\Ai\Guardrails\TopicAllowlistGuardrail;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DefinesGuardrails;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class GuardedContentPipeline implements Swarm, DefinesGuardrails
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ArticlePlanner,
            new ArticleWriter,
            new ArticleEditor,
        ];
    }

    public function guardrails(): array
    {
        return [
            TopicAllowlistGuardrail::class,
            NoPlaceholderStepGuardrail::class,
            OutputLengthGuardrail::class,
        ];
    }
}
```

The runner infers which phase each class belongs to from the interface it
implements. A class may implement multiple guardrail interfaces.

## Run It

```php
use App\Ai\Swarms\GuardedContentPipeline;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;

try {
    $response = GuardedContentPipeline::make()->prompt([
        'topic'    => 'open-source Laravel packages',
        'audience' => 'intermediate developers',
        'format'   => '800-word article',
    ]);

    $response->output;

} catch (GuardrailViolation $e) {
    // $e->policyCode identifies the rule that fired (not $e->code — that is
    // PHP's inherited Exception integer property).
    logger()->warning('Guardrail blocked swarm', [
        'policy_code' => $e->policyCode,
        'reason'      => $e->getMessage(),
        'metadata'    => $e->metadata,
    ]);
}
```

A blocked topic:

```php
// Throws GuardrailViolation with policyCode 'topic_not_allowed'.
GuardedContentPipeline::make()->prompt([
    'topic' => 'cryptocurrency investment returns',
]);
```

## Global Guardrails

Guardrails that apply to every swarm in the application can be registered in
`config/swarm.php` instead of (or alongside) `DefinesGuardrails`:

```php
'guardrails' => [
    'input'  => [TopicAllowlistGuardrail::class],
    'step'   => [NoPlaceholderStepGuardrail::class],
    'output' => [OutputLengthGuardrail::class],
],
```

Global guardrails run for every swarm regardless of topology or execution mode.
Per-swarm `guardrails()` entries are merged with the global list; duplicates are
not deduplicated automatically, so avoid registering the same class in both
places.

## Testing

`SwarmFake` records dispatch intent and bypasses the runner entirely — guardrails
do not fire through the fake. Test each guardrail class directly as a plain PHP
unit, then test its integration with the real swarm runner when you need
end-to-end coverage.

**Unit-testing a guardrail:**

```php
use App\Ai\Guardrails\TopicAllowlistGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

it('blocks a topic not on the allowlist', function () {
    $guardrail = new TopicAllowlistGuardrail;

    expect(fn () => $guardrail->validate(RunContext::from(['topic' => 'cryptocurrency'])))
        ->toThrow(GuardrailViolation::class);
});

it('accepts an allowed topic', function () {
    $guardrail = new TopicAllowlistGuardrail;

    // Should not throw.
    $guardrail->validate(RunContext::from(['topic' => 'open-source tooling']));

    expect(true)->toBeTrue();
});

it('uses policyCode not code', function () {
    $guardrail = new TopicAllowlistGuardrail;

    try {
        $guardrail->validate(RunContext::from(['topic' => 'cryptocurrency']));
        $this->fail('Expected GuardrailViolation');
    } catch (GuardrailViolation $e) {
        expect($e->policyCode)->toBe('topic_not_allowed');
    }
});
```

**Unit-testing the step guardrail:**

```php
use App\Ai\Guardrails\NoPlaceholderStepGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

it('blocks a step output containing placeholder text', function () {
    $guardrail = new NoPlaceholderStepGuardrail;

    $context = new GuardrailStepContext(
        runId: 'test-run',
        swarmClass: 'App\\Ai\\Swarms\\GuardedContentPipeline',
        topology: \BuiltByBerry\LaravelSwarm\Enums\Topology::Sequential,
        executionMode: \BuiltByBerry\LaravelSwarm\Enums\ExecutionMode::Prompt,
        stepIndex: 0,
        agentClass: 'App\\Ai\\Agents\\ArticlePlanner',
        input: 'write an article',
        output: 'Here is an outline. [INSERT research here] More content.',
    );

    expect(fn () => $guardrail->validate($context))
        ->toThrow(GuardrailViolation::class);
});
```

See [`docs/guardrails.md`](../../docs/guardrails.md) for child inheritance,
parallel failure policy, and the full failure semantics reference.
