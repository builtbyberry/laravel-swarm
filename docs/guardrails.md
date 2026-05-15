# Guardrails

Laravel Swarm runs **guardrails** at three deterministic phases:

1. **Input** — after payload limits / queue-safe validation and **before** `RunHistoryStore::start()` and `SwarmStarted`. Validated **synchronously** for every execution mode: `prompt()`, `queue()`, `stream()`, `broadcastOnQueue()`, and `dispatchDurable()` all throw eagerly if input validation fails.
2. **Step** — immediately **before** each step completion is recorded (`SwarmStepRecorder::completed()`), as soon as agent output is available.
3. **Output** — after the orchestration produces a final string output and **before** history completion and `SwarmCompleted`.

Guardrails are **not** HTTP middleware, routing hooks, or topology mutations. They express policy blocks only by throwing `GuardrailViolation` (use `GuardrailViolation::block()` for clarity).

## Contracts

- `SwarmInputGuardrail::validate(RunContext $context)`
- `SwarmStepGuardrail::validate(GuardrailStepContext $step)`
- `SwarmOutputGuardrail::validate(RunContext $context, string $output)`

Optional swarm hook: implement `DefinesGuardrails` and return class names (container-resolved) or instances from `guardrails()`.

## Configuration

Published `config/swarm.php` includes:

- `guardrails.input`, `guardrails.step`, `guardrails.output` — arrays of guardrail class strings (resolved via the container) or pass instances from `DefinesGuardrails::guardrails()`.
- `guardrails.child_inheritance`
  - `own_and_global` (default) — merge global config entries, then the swarm's `guardrails()`.
  - `own_global_and_parent` — also merge parent swarm `guardrails()` when `metadata.parent_run_id` resolves via persisted history (`swarm_class` on the parent row). When the parent swarm class no longer exists or cannot be resolved from the container, a warning is logged and parent guardrails are not applied. Monitor for these log entries in long-running deployments when swarm classes are renamed or removed.
- `guardrails.parallel_failure_policy` (**sync `ParallelRunner` only**; durable queued parallel branches keep existing behavior):
  - `existing` — validate each parallel branch immediately before that branch's step row is written.
  - `batch_validate_before_record` — validate every parallel output before **any** branch step completion row is written.

Do not confuse `guardrails.parallel_failure_policy` with durable branch join semantics (`durable_parallel_failure_policy` metadata / `DurableParallelFailurePolicy`).

## Failure semantics

On `GuardrailViolation`, Swarm follows the same terminal failure path as other exceptions: persisted failure state, `SwarmFailed`, audit hooks, and telemetry `exception_class` including `GuardrailViolation`. For input guardrail blocks on dispatch paths (`queue`, `broadcastOnQueue`, `dispatchDurable`), the failure is recorded as a preflight failure row — history is written before the exception propagates, exactly as it is for `prompt()` and `stream()`.

The exception exposes a stable **`policyCode`** field for the string policy identifier. Use `$e->policyCode` in application code — **not** `$e->code`. The property is named `policyCode` deliberately: PHP's `Exception::$code` is an inherited integer property, and declaring a `readonly string $code` on a subclass causes a fatal type conflict. `policyCode` is unambiguous and carries no inherited-property baggage.

Persisted context may include `guardrail_code` / `guardrail_scope` / `guardrail_metadata` from `safeContextMetadata()`.

Treat `reason` and guardrail `metadata` as operator-facing: avoid raw prompts or secrets.

## Queued execution — input guardrails run twice

For `queue()` and `broadcastOnQueue()`, input guardrails run at **two points**:

1. **Dispatch time** (synchronous, on the caller's process) — fail-fast before the job is placed on the queue. A preflight failure row is written and `SwarmFailed` is dispatched immediately.
2. **Execution time** (on the queue worker, inside `runWithExecutionMode`) — authoritative check. Validates current conditions when the job fires, regardless of how the job was constructed.

Both checks serve different purposes. Removing the dispatch-time check would prevent synchronous caller feedback. Removing the execution-time check would leave a gap for jobs replayed, retried, or constructed without going through `queue()`. Design guardrails as **pure, stateless policy checks** — throwing `GuardrailViolation` or returning nothing. A guardrail that has side effects (incrementing a rate-limit counter, logging an audit event) will run those effects twice for queued swarms.

Durable runs validate input once, at `dispatchDurable()` call time, before any database rows are written.

## Laravel AI middleware

Use Laravel AI's agent middleware for **single-agent** concerns. Swarm guardrails apply to orchestrated runs across modes (`prompt`, `queue`, `stream`, `dispatchDurable`) at the boundaries above.

## Queue lease schema errors

When the configured history table is missing `execution_token` and `leased_until`, database-backed queued execution raises `MissingQueueLeaseSchemaException` (a configuration error). This is separate from `LostSwarmLeaseException`, which can represent runtime lease loss on some paths.

## Example

See [Guardrails Policy](../examples/guardrails-policy/README.md) for a copy-paste
walkthrough of all three guardrail phases, `DefinesGuardrails`, global config
registration, and unit-testing patterns.

## Guardrail Scope

`GuardrailViolation` exposes a nullable `scope` string that identifies which phase produced the violation. The package sets this automatically when it catches and re-wraps violations; guardrail authors can also pass it explicitly via `GuardrailViolation::block()`:

- **`'input'`** — the violation was raised by a `SwarmInputGuardrail` before any agent ran. No agent tokens were consumed and no step rows were written.
- **`'step'`** — the violation was raised by a `SwarmStepGuardrail` after a specific agent produced output but before that step's completion row was recorded. `$e->metadata` often includes `agent` and `step_index` to identify which step failed.
- **`'output'`** — the violation was raised by a `SwarmOutputGuardrail` after all agents completed but before the run was marked successful and `SwarmCompleted` was dispatched.

Application code can branch on `$e->scope` to apply different recovery logic:

```php
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;

try {
    $response = ContentPipeline::make()->prompt($task);
} catch (GuardrailViolation $e) {
    match ($e->scope) {
        'input'  => $this->rejectWithUserFeedback($e->policyCode),
        'step'   => $this->alertOperationsTeam($e->policyCode, $e->metadata),
        'output' => $this->quarantineForReview($e->policyCode),
        default  => throw $e,
    };
}
```

`scope` is also persisted as `guardrail_scope` in the run's context metadata (see `safeContextMetadata()`), so you can filter history or audit records by phase without inspecting exception state.

When you throw `GuardrailViolation::block()` from your own guardrail, `scope` defaults to `null` unless you pass it explicitly. The framework sets it to the correct phase string as it dispatches each guardrail type.

## Child Swarm Inheritance

When a durable run spawns child swarms via `dispatchDurable()` with `parent_run_id` metadata, the `guardrails.child_inheritance` config key controls which guardrails apply to the child run. Set it in `config/swarm.php` or via the `SWARM_GUARDRAILS_CHILD_INHERITANCE` environment variable.

### `own_and_global` (default)

```php
'guardrails' => [
    'child_inheritance' => 'own_and_global',
],
```

Each run (parent or child) applies only global config guardrails plus its own `DefinesGuardrails::guardrails()`. Parent guardrails are **not** inherited.

Use this when child swarms are independently scoped — for example, a parent orchestrator spawns domain-specific child swarms, each with its own policy set that should not be polluted by the parent's constraints.

### `own_global_and_parent`

```php
'guardrails' => [
    'child_inheritance' => 'own_global_and_parent',
],
```

Also merges the parent swarm's `guardrails()` into the child run's guardrail set. The merge order is: global config entries, then child swarm `guardrails()`, then parent swarm `guardrails()`.

Use this when child swarms should automatically inherit the parent's safety policy — for example, a top-level content moderation guardrail that must apply to all nested sub-runs without being re-declared in every child swarm class.

**Resolution at runtime:** the runner looks up `parent_run_id` from the child's `RunContext::$metadata`, reads the `swarm_class` from the parent history row via `RunHistoryStore::find()`, and instantiates the parent swarm through the container to call `guardrails()` on it. Two failure conditions are handled gracefully:

- If the parent swarm class no longer exists (renamed or removed), a `warning` is logged and parent guardrails are silently skipped. Monitor log entries tagged `Laravel Swarm: parent swarm class` in long-running deployments when refactoring swarm classes.
- If the parent swarm cannot be resolved from the container, a `warning` is logged with the error message and container bindings should be reviewed.

In both cases, the child run proceeds with global and own guardrails only — the inheritance failure does not block the child run.

**Important:** `own_global_and_parent` requires that parent swarms implement `DefinesGuardrails`. A parent that does not implement the interface contributes no guardrails (the merge is a no-op), regardless of the inheritance setting.

## Best Practices

### Match guardrail phase to cost and purpose

**Input guardrails** are the cheapest gate. They fire synchronously before any agent runs and before any tokens are spent. Use them for:

- PII scrubbing or detection — reject prompts containing personal data before they reach the model.
- Prompt injection prevention — scan for known injection patterns in untrusted user input.
- Topic or domain allow-listing — block off-policy requests before the queue worker picks them up.
- Payload size enforcement (complementary to `limits.max_input_bytes`, which covers raw byte limits).

A rejected input guardrail on `queue()` or `dispatchDurable()` saves the cost of the entire agent pipeline.

**Step guardrails** run after each individual agent produces output, before the step row is recorded. Use them for:

- Schema conformance — ensure structured JSON produced by one agent matches what the next agent expects before the output is committed.
- Degenerate output detection — catch placeholder text, truncated responses, or refusal patterns that would corrupt downstream agents.
- Per-agent content policy — validate that intermediate outputs stay within an acceptable range before they are passed forward.

Be aware that step guardrails run for every agent step across all execution modes. In a parallel topology with many branches, a step guardrail runs once per branch per step. Avoid expensive external API calls or database queries inside step guardrails for wide fan-outs.

**Output guardrails** validate the final aggregated result before `SwarmCompleted` is dispatched and the run is marked successful. Use them for:

- Final content policy enforcement — the last safety net before output reaches consumers.
- Compliance checks — ensure the final result meets regulatory or contractual constraints.
- Format validation — confirm the final output conforms to an expected shape (e.g., valid JSON, required fields present).

### Keep guardrails stateless

Guardrails run inside queue workers and may be called from multiple processes concurrently. A guardrail that depends on request-scoped state (authenticated user, current HTTP session, `app()->make('request')`) will behave unexpectedly on the queue. Design guardrails as pure, stateless policy checks — they receive all context through `RunContext` or `GuardrailStepContext` and express failure only by throwing `GuardrailViolation`.

### Watch for double-execution on queued runs

Input guardrails run twice for `queue()` and `broadcastOnQueue()`: once synchronously at dispatch time and once inside the worker (see [Queued execution — input guardrails run twice](#queued-execution--input-guardrails-run-twice)). A guardrail with side effects — incrementing a rate-limit counter, recording an audit event, sending a notification — will execute those effects twice. Either make the side effect idempotent or restrict it to the authoritative worker-side check only.

### Use `policyCode` for machine-readable rejection reasons

`policyCode` is the stable, machine-readable identifier for a policy failure. Application code, audit sinks, and monitoring dashboards should key on `$e->policyCode`. The `reason` string and `metadata` array are operator-facing context — useful for logs and alerts, but not suitable for user-visible messages. Never embed raw prompts, user input, or secrets in `reason` or `metadata`; those fields may be persisted and emitted to observability systems (see [Configuration](configuration.md) for `capture` settings).

### Avoid heavy validation in step guardrails for wide parallel fan-outs

`guardrails.parallel_failure_policy` controls whether a step violation in one parallel branch immediately aborts others (`batch_validate_before_record`) or only fails after that branch's step is recorded (`existing`). Regardless of policy, step guardrails run once per parallel branch. A guardrail that makes a blocking HTTP call or runs a slow regex adds latency proportional to the fan-out width. For wide parallel topologies, prefer lightweight in-process validation at the step phase and reserve expensive checks for input and output guardrails.

## Testing Guide

Guardrails are plain PHP objects with a single `validate()` method — they test cleanly without any swarm runner involvement. The recommended pattern is two layers: unit tests that exercise the guardrail class directly, and integration tests that verify the guardrail produces the right observable outcome through a real execution.

`SwarmFake` records dispatch intent and bypasses the runner entirely, so guardrails do not fire through the fake. Use the fake to test application flow (how your code handles a blocked run), not guardrail logic itself. See [Testing](testing.md) for the full fake API.

### Unit testing a custom guardrail

Construct the guardrail directly, build the context with the package helpers, and assert that `GuardrailViolation` is thrown with the expected `policyCode`:

```php
use App\Ai\Guardrails\PiiGuardrail;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

it('blocks input that contains an email address', function () {
    $guardrail = new PiiGuardrail;

    expect(fn () => $guardrail->validate(RunContext::from('Please review user@example.com')))
        ->toThrow(GuardrailViolation::class);
});

it('allows clean input through', function () {
    $guardrail = new PiiGuardrail;

    // Expect no exception.
    $guardrail->validate(RunContext::from('Summarise the Q3 engineering roadmap.'));

    expect(true)->toBeTrue();
});

it('sets the correct policyCode when blocking', function () {
    $guardrail = new PiiGuardrail;

    try {
        $guardrail->validate(RunContext::from('Call me at 555-867-5309'));
        test()->fail('Expected GuardrailViolation');
    } catch (GuardrailViolation $e) {
        expect($e->policyCode)->toBe('pii_detected');
    }
});
```

For step guardrails, construct a `GuardrailStepContext` directly:

```php
use App\Ai\Guardrails\ContentPolicyGuardrail;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\GuardrailStepContext;

it('blocks a step output that violates content policy', function () {
    $guardrail = new ContentPolicyGuardrail;

    $context = new GuardrailStepContext(
        runId: 'test-run-01',
        swarmClass: 'App\\Ai\\Swarms\\ContentPipeline',
        topology: Topology::Sequential,
        executionMode: ExecutionMode::Prompt,
        stepIndex: 1,
        agentClass: 'App\\Ai\\Agents\\DraftWriter',
        input: 'Write a product description',
        output: 'This product will definitely cure all diseases.',
    );

    expect(fn () => $guardrail->validate($context))
        ->toThrow(GuardrailViolation::class);
});

it('includes step metadata in the violation', function () {
    $guardrail = new ContentPolicyGuardrail;

    $context = new GuardrailStepContext(
        runId: 'test-run-01',
        swarmClass: 'App\\Ai\\Swarms\\ContentPipeline',
        topology: Topology::Sequential,
        executionMode: ExecutionMode::Prompt,
        stepIndex: 1,
        agentClass: 'App\\Ai\\Agents\\DraftWriter',
        input: 'Write a product description',
        output: 'Guaranteed to cure all diseases.',
    );

    try {
        $guardrail->validate($context);
        test()->fail('Expected GuardrailViolation');
    } catch (GuardrailViolation $e) {
        expect($e->policyCode)->toBe('prohibited_health_claim')
            ->and($e->metadata)->toHaveKey('agent');
    }
});
```

When a guardrail has injected dependencies (a configuration repository, an HTTP client, a blocklist service), pass mock or stub instances through the constructor:

```php
use App\Ai\Guardrails\BlocklistGuardrail;
use App\Contracts\BlocklistService;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Mockery;

it('delegates to the blocklist service', function () {
    $blocklist = Mockery::mock(BlocklistService::class);
    $blocklist->shouldReceive('contains')->with('banned phrase')->andReturn(true);

    $guardrail = new BlocklistGuardrail($blocklist);

    expect(fn () => $guardrail->validate(RunContext::from('A prompt with banned phrase inside')))
        ->toThrow(\BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation::class);
});
```

### Integration testing guardrail propagation

When you need to verify that a guardrail violation propagates correctly through real swarm execution — including failure persistence and `SwarmFailed` dispatch — run a real execution with `InteractsWithSwarmEvents` rather than relying on the fake:

```php
use App\Ai\Swarms\ContentPipeline;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

class ContentPolicyGuardrailTest extends TestCase
{
    use InteractsWithSwarmEvents;

    public function test_content_policy_guardrail_blocks_run_and_fires_swarm_failed(): void
    {
        $this->expectException(GuardrailViolation::class);
        $this->expectExceptionMessage('policyCode');

        ContentPipeline::make()->prompt('Write content that violates policy');

        ContentPipeline::assertEventFired(SwarmFailed::class);
    }
}
```

Or using Pest:

```php
use App\Ai\Swarms\ContentPipeline;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

uses(InteractsWithSwarmEvents::class);

it('records a failed run when the content policy guardrail fires', function () {
    expect(fn () => ContentPipeline::make()->prompt('Violating content here'))
        ->toThrow(GuardrailViolation::class, fn ($e) => $e->policyCode === 'prohibited_content');

    ContentPipeline::assertEventFired(SwarmFailed::class);
});
```

**Asserting scope in caught violations:**

```php
it('sets scope to output for output guardrail violations', function () {
    try {
        ContentPipeline::make()->prompt('A task that produces output violating policy');
        test()->fail('Expected GuardrailViolation');
    } catch (GuardrailViolation $e) {
        expect($e->scope)->toBe('output')
            ->and($e->policyCode)->toBe('content_policy_violation');
    }
});
```

**Testing application-level recovery logic with SwarmFake:**

Use the fake to test the `catch (GuardrailViolation $e)` branch in your application code without needing a real swarm runner or live model. Manually throw the exception from within the callable assertion:

```php
use App\Ai\Swarms\ContentPipeline;
use App\Http\Controllers\ContentController;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;

it('returns 422 when a guardrail blocks the run', function () {
    ContentPipeline::fake()->shouldThrow(
        GuardrailViolation::block('content_policy_violation', 'Blocked by policy')
    );

    $response = $this->postJson('/api/content', ['task' => 'Violating task']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'content_policy_violation');
});
```

> **Note:** `SwarmFake::shouldThrow()` API availability depends on your installed version. If your version does not support it, bind a test double that throws `GuardrailViolation` directly, or trigger real execution against a real guardrail in a feature test. See [Testing](testing.md) for the full fake API reference.

For the full testing reference — fake API, lifecycle event assertions, persisted run assertions, and callable matchers — see [Testing](testing.md). For events fired on guardrail failure (`SwarmFailed`, `exception_class` telemetry), see [Events](events.md).
