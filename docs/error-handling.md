# Error Handling

Every swarm run can fail at multiple points — input validation, agent execution, guardrail enforcement, provider timeouts, or infrastructure failures. Understanding the failure model helps you handle errors gracefully, design resilient swarms, and choose the right execution mode for the guarantees your workflow requires.

## Where Failures Can Occur

- **Input guardrail** — before the first agent runs; blocks dispatch and records a preflight failure row
- **Agent execution** — during an individual agent's LLM call; provider errors, tool call failures, and malformed responses surface here
- **Step guardrail** — after an agent completes, before that step is recorded; blocks before the output is persisted
- **Output guardrail** — after the last agent, before the run is marked completed; blocks before `SwarmCompleted` fires
- **Timeout** — orchestration deadline exceeded; checked between steps, not mid-generation
- **Lease loss** — a durable or queued run lost its database lease; handled by recovery for durable runs
- **Provider error** — network or API error from the AI provider; surfaces as a `SwarmStreamProviderException` in stream mode or as a plain exception in other modes

## What Happens on Failure

Regardless of execution mode, Laravel Swarm applies a consistent terminal failure path when any of the above occurs:

1. Run history is written with a `failed` status.
2. `SwarmFailed` fires with the run ID, swarm class, topology, exception, duration, and execution mode.
3. Artifacts and context from completed steps are preserved. Steps that already checkpointed (durable) or recorded (all modes) are not lost.
4. The run ID remains valid for inspection via `SwarmHistory` and, for durable runs, `DurableSwarmManager::inspect()`.

For input guardrail blocks on dispatch paths (`queue()`, `broadcastOnQueue()`, `dispatchDurable()`), the failure row is written before the exception propagates — so history is always present even if the run never started executing agents.

## Exception Taxonomy

All swarm exceptions extend `BuiltByBerry\LaravelSwarm\Exceptions\SwarmException`, which extends `RuntimeException`. Catch the base class when you want to handle any swarm error in a single block.

---

### `GuardrailViolation`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation`

**When thrown:** by any guardrail class (input, step, or output) when the guardrail wants to block the run. Thrown by calling `GuardrailViolation::block()` inside your guardrail's `validate()` method.

**Public properties:**

| Property | Type | Description |
|---|---|---|
| `$policyCode` | `string` | Stable, machine-readable policy identifier. Use this in application code — not `$e->code`, which is the inherited PHP integer property. |
| `$reason` | `string` | Human-readable explanation of why the run was blocked. Keep this operator-facing; avoid raw prompts or secrets. |
| `$metadata` | `array<string, mixed>` | Optional operator-facing context. Persisted as `guardrail_metadata` in run context. Default `[]`. |
| `$scope` | `?string` | Optional string identifying which guardrail phase or instance blocked the run. Persisted as `guardrail_scope`. Default `null`. |

**Catching it:**

```php
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;

try {
    $response = ContentPipelineSwarm::make()->prompt($input);
} catch (GuardrailViolation $e) {
    return response()->json(['error' => $e->reason, 'code' => $e->policyCode], 422);
}
```

See [Guardrails](guardrails.md) for the full behavior contract, inheritance rules, and parallel failure policy.

---

### `LostSwarmLeaseException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException`

**When thrown:** when a queued swarm run loses its database execution lease at runtime — for example, when a duplicate worker picks up a job that another worker already holds. This is a runtime race condition, not a configuration error.

**Public properties:** none beyond the inherited `message`.

**Catching it:** In most applications, `LostSwarmLeaseException` surfaces as a failed queue job rather than a caught exception. Listen to `SwarmFailed` and inspect `$event->exceptionClass` to distinguish lease loss from other failures:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Exceptions\LostSwarmLeaseException;

// In your EventServiceProvider or AppServiceProvider
Event::listen(SwarmFailed::class, function (SwarmFailed $event) {
    if ($event->exceptionClass === LostSwarmLeaseException::class) {
        Log::warning('Swarm lease lost', ['run_id' => $event->runId]);
    }
});
```

---

### `LostDurableLeaseException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException`

**When thrown:** when a durable step job cannot acquire or renew its database lease. This typically means another worker already holds the lease (duplicate dispatch) or the lease expired and `swarm:recover` already redispatched. The durable execution engine catches this and avoids double-advancing a run.

**Public properties:** none beyond the inherited `message`.

**Catching it:** This exception is handled internally by the durable runner. It will not normally propagate to application code. If you see it in logs, check for queue `retry_after` values shorter than your `SWARM_DURABLE_STEP_TIMEOUT` — that configuration gap causes jobs to become visible again before the current worker finishes.

---

### `MissingQueueLeaseSchemaException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\MissingQueueLeaseSchemaException`

**When thrown:** when the configured history table is missing the `execution_token` and `leased_until` columns required for queued execution lease management. This is a hard configuration error, not a runtime race condition. It surfaces during queued swarm dispatch when database-backed persistence is enabled.

**Public properties:** none beyond the inherited `message`.

**Catching it:** This exception means migrations have not been run or the wrong migration set was published. Fix the schema rather than catching this in application code:

```bash
php artisan migrate
```

Distinct from `LostSwarmLeaseException`: missing schema is a configuration error; lease loss is a runtime condition.

---

### `NonQueueableSwarmException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException`

**When thrown:** when a swarm that cannot be safely container-resolved is dispatched via `queue()` or a parallel execution path. Laravel Swarm validates queueability before dispatch to prevent cryptic serialization failures inside queue workers.

**Public properties:** none beyond the inherited `message`.

**Catching it:** This exception fires at the call site, before any queue job is dispatched. Fix the swarm class to be container-resolvable (constructor-injectable dependencies only, no runtime instance state) rather than catching it:

```php
// Wrong: swarm stores runtime state that can't serialize
class ReportSwarm implements Swarm
{
    use Runnable;

    public function __construct(private readonly User $user) {} // not container-resolvable
}

// Right: pass per-run data in the task payload
$response = ReportSwarm::make()->queue(['user_id' => $user->id]);
```

---

### `SwarmStreamProviderException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException`

**When thrown:** when the AI provider returns a stream-level error during a `stream()`, `broadcast()`, `broadcastNow()`, or `broadcastOnQueue()` run. This wraps the upstream provider error with swarm-specific context.

**Public properties:**

| Property | Type | Description |
|---|---|---|
| `$eventId` | `string` | The stream event ID at the point of failure. |
| `$invocationId` | `?string` | Provider-level invocation ID when available. |
| `$recoverable` | `bool` | Whether the provider indicated the error is transient. Does not guarantee a retry will succeed. |
| `$metadata` | `array<string, mixed>` | Additional structured context from the provider error. |
| `$timestamp` | `int` | Unix timestamp when the error was captured. |
| `$providerErrorType` | `?string` | Provider-specific error type string when present. |

**Catching it:**

```php
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmStreamProviderException;

try {
    foreach (ArticlePipeline::make()->stream($input) as $event) {
        // process events
    }
} catch (SwarmStreamProviderException $e) {
    if ($e->recoverable) {
        // Schedule a retry or enqueue the request
    } else {
        Log::error('Non-recoverable provider error', [
            'event_id' => $e->eventId,
            'provider_error_type' => $e->providerErrorType,
        ]);
    }
}
```

---

### `SwarmTimeoutException`

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException`

**When thrown:** when the orchestration deadline set by `#[Timeout]` is exceeded. See [Timeout Behavior](#timeout-behavior) for the exact semantics.

**Public properties:** none beyond the inherited `message`.

---

### `SwarmException` (base class)

**Full class:** `BuiltByBerry\LaravelSwarm\Exceptions\SwarmException`

**Extends:** `RuntimeException`

Catch `SwarmException` when you want a single handler for any swarm-originated failure, then inspect the concrete type when you need to branch:

```php
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;

try {
    $response = ComplianceReviewSwarm::make()->prompt($document);
} catch (GuardrailViolation $e) {
    return response()->json(['blocked' => true, 'code' => $e->policyCode], 422);
} catch (SwarmTimeoutException $e) {
    return response()->json(['error' => 'Review timed out. Try a shorter document.'], 408);
} catch (SwarmException $e) {
    report($e);
    return response()->json(['error' => 'Review failed. Please try again.'], 500);
}
```

## Timeout Behavior

Timeout is not an exception path during execution — it is a best-effort orchestration deadline declared with `#[Timeout]` on the swarm class:

```php
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;

#[Timeout(seconds: 120)]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;
}
```

**How the deadline works:**

- The deadline is checked **between steps**, not mid-generation. An in-progress LLM call is never hard-cancelled.
- When the deadline is exceeded at a step boundary, the step that was in progress completes normally, and then the run fails with `SwarmTimeoutException`.
- Run history is written with a `failed` status and `SwarmFailed` fires.
- For `prompt()`, the exception propagates to the caller.
- For `queue()`, the job fails with `SwarmTimeoutException`.
- For durable runs, the per-step timeout and overall run timeout are tracked separately. The `timed_out_at` column is set and `swarm:recover` can release timed-out waits.

`#[Timeout]` accepts a positive integer number of seconds. Passing zero or a negative value throws `SwarmException` at class load time.

## Failure Behavior Per Execution Mode

### `prompt()`

The exception propagates directly to the call site. Wrap in try/catch in your controller, action, or command:

```php
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;

try {
    $response = ContentModerationSwarm::make()->prompt($userInput);
} catch (GuardrailViolation $e) {
    return response()->json(['error' => $e->reason, 'policy' => $e->policyCode], 422);
} catch (SwarmException $e) {
    report($e);
    return response()->json(['error' => 'Processing failed.'], 500);
}
```

### `queue()`

The queue job fails. Laravel's standard job retry configuration applies if `tries` is set on the job or the queue connection. `SwarmFailed` fires on the final failure (after retries are exhausted). There is no checkpoint recovery — if the job fails mid-run, queue retry restarts the swarm from the beginning.

Do not use `then()` / `catch()` callbacks on the response for real workloads; listen to `SwarmCompleted` and `SwarmFailed` lifecycle events instead.

### `stream()`

The stream terminates. A `swarm_stream_error` event is yielded, run history is marked failed, and `SwarmFailed` fires. Partial events already delivered to the client (SSE bytes already flushed) cannot be recalled. The exception is re-thrown to the caller after the stream terminates, so a try/catch around the `foreach` loop will see it. Recovery is not available — a failed stream must be re-submitted as a new run.

### `dispatchDurable()`

The failed step is checkpointed. The `DurableRetry` policy applies (if configured) and `swarm:recover` redispatches due retries without replaying completed steps. `SwarmFailed` fires when the run reaches a terminal failed state. See [Durable Recovery](#durable-recovery) below.

## Queue Retry vs Durable Retry

These are distinct mechanisms and should not be confused.

**Queue retry** — Laravel re-dispatches the entire `InvokeSwarm` job from the beginning. Every agent in the swarm runs again. This is the only failure recovery available for `queue()` runs. Configure it through standard Laravel queue settings (`tries`, `backoff`, etc. on the connection or job):

```php
// In the connection or queue config, or via job-level settings
'tries' => 3,
'backoff' => [10, 60, 300],
```

**Durable retry (`#[DurableRetry]`)** — a per-step retry within the durable execution engine. When a durable step fails, the retry policy determines whether and when to re-run that single step. Completed steps are not re-run. The run resumes from the failed step after the backoff window. Configure it on the swarm class:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;

#[DurableRetry(maxAttempts: 3, backoffSeconds: [10, 60, 300])]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;
}
```

`maxAttempts` counts failed executions. Mark exceptions that should not be retried as non-retryable to avoid burning retry budget on deterministic failures:

```php
use App\Exceptions\InvalidComplianceDocument;

#[DurableRetry(
    maxAttempts: 3,
    backoffSeconds: [10, 60, 300],
    nonRetryable: [InvalidComplianceDocument::class],
)]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;
}
```

**Precedence:** For durable runs, both mechanisms may be active simultaneously. The durable step retry fires first. If the step exceeds its `maxAttempts`, the durable run transitions to failed. If the queue job itself fails before a checkpoint is written (e.g. OOM, SIGKILL before the step can checkpoint), the queue job retry fires and re-runs that step job. The two mechanisms are complementary, not competing — durable retries operate within a single job execution; queue retries handle the case where the job itself never completed.

See [Durable Retries And Progress](durable-retries-and-progress.md) for the full policy API, agent-specific policies, and `ConfiguresDurableRetries`.

## Handling GuardrailViolation in Application Code

A realistic controller example combining both guardrail and general swarm error handling:

```php
use App\Ai\Swarms\ContentPipelineSwarm;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmTimeoutException;

class ContentController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $input = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'audience' => ['required', 'string'],
        ]);

        try {
            $response = ContentPipelineSwarm::make()->prompt($input);

            return response()->json(['output' => $response->output]);
        } catch (GuardrailViolation $e) {
            return response()->json([
                'error' => $e->reason,
                'code' => $e->policyCode,
            ], 422);
        } catch (SwarmTimeoutException $e) {
            return response()->json([
                'error' => 'Content generation timed out. Try a more focused topic.',
            ], 408);
        } catch (SwarmException $e) {
            report($e);

            return response()->json([
                'error' => 'Content generation failed. Please try again.',
            ], 500);
        }
    }
}
```

Use `$e->policyCode` — not `$e->code` — to read the guardrail policy identifier. PHP's `Exception::$code` is an inherited integer property; `policyCode` is the unambiguous string field on `GuardrailViolation`.

For input guardrails that run at dispatch time on `queue()` and `broadcastOnQueue()`, the exception propagates synchronously before the job is placed on the queue. Wrap the dispatch call — not a job completion handler — in try/catch when you need caller feedback:

```php
try {
    ContentPipelineSwarm::make()
        ->queue($input)
        ->onQueue('ai-processing');
} catch (GuardrailViolation $e) {
    return response()->json(['error' => $e->reason, 'code' => $e->policyCode], 422);
}
```

Note: for `queue()` and `broadcastOnQueue()`, input guardrails run twice — once at dispatch time (synchronous feedback) and once inside the job (authoritative check). Design guardrail side effects accordingly. See [Guardrails](guardrails.md) for the full two-phase semantics.

## Durable Recovery

`swarm:recover` is the safety net for durable runs that stall due to worker crashes, lease expiry, or missed relay dispatches. It:

- Redispatches runs where the lease has expired and the status is `pending` or `running`
- Releases branch parents that are waiting on branches that have all reached terminal state
- Dispatches due retries (runs where `next_retry_at` has passed)
- Releases timed-out durable waits

Recovery is safe to run repeatedly — it is idempotent. It does not replay completed steps. Schedule it frequently:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

For production durable workflows, a one-minute schedule is recommended:

```php
Schedule::command('swarm:recover')->everyMinute();
```

Manual recovery is also available:

```bash
# Recover all stalled runs
php artisan swarm:recover

# Recover a specific run
php artisan swarm:recover --run-id=<run-id>

# Recover runs for a specific swarm class, bounded
php artisan swarm:recover --swarm='App\Ai\Swarms\ComplianceReviewSwarm' --limit=25
```

If `swarm:recover` is not scheduled, a run can stay permanently in `running` status after a worker exits between checkpointing a step and dispatching the next job. Do not depend on manual recovery in production.

For the full durable operational contract, see [Durable Execution](durable-execution.md).

## Testing Failure Scenarios

`SwarmFake` intercepts all execution modes and records dispatch intent. Because fakes bypass the runner entirely, guardrails and exceptions from agents do not fire when a swarm is faked. Test guardrail logic as plain PHP units using `RunContext::from()` and `GuardrailStepContext` directly:

```php
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

it('blocks content that exceeds length policy', function () {
    $guardrail = new ContentLengthGuardrail(maxChars: 500);
    $context = RunContext::from(str_repeat('a', 600));

    expect(fn () => $guardrail->validate($context))
        ->toThrow(GuardrailViolation::class);
});

it('sets the correct policy code', function () {
    $guardrail = new ContentLengthGuardrail(maxChars: 500);
    $context = RunContext::from(str_repeat('a', 600));

    try {
        $guardrail->validate($context);
    } catch (GuardrailViolation $e) {
        expect($e->policyCode)->toBe('content.length.exceeded');
        expect($e->reason)->toContain('500');
    }
});
```

To assert that `SwarmFailed` fires in a real (non-faked) execution, use `InteractsWithSwarmEvents` and exercise the run with actual persistence:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;

class ComplianceReviewSwarmFailureTest extends TestCase
{
    use InteractsWithSwarmEvents;

    public function test_swarm_failed_fires_on_guardrail_violation(): void
    {
        // Exercise with real runner, not a fake
        try {
            ComplianceReviewSwarm::make()->prompt('banned content');
        } catch (GuardrailViolation) {
            // expected
        }

        ComplianceReviewSwarm::assertEventFired(SwarmFailed::class);
    }

    public function test_swarm_failed_carries_guardrail_exception_class(): void
    {
        try {
            ComplianceReviewSwarm::make()->prompt('banned content');
        } catch (GuardrailViolation) {
            // expected
        }

        ComplianceReviewSwarm::assertEventFired(
            SwarmFailed::class,
            fn ($event) => $event->exceptionClass === GuardrailViolation::class,
        );
    }
}
```

For `SwarmFailed` assertions with fakes, note that fakes do not execute runners and do not fire lifecycle events. Use `assertEventFired()` only with real execution or feature tests with database persistence. See [Testing](testing.md) for the full fake assertion API and when to choose each testing style.

For durable failure testing (lease clearing, retry scheduling, and branch join behavior), use feature-style tests with database persistence and migrations loaded rather than fakes. Bind test doubles for `DurableJobDispatcher` before resolving `DurableSwarmManager` when you need to assert dispatch counts or inject controlled failures. See [Testing](testing.md#database-backed-durable-execution).
