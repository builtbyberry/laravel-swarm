# Error Handling

Every swarm run can fail at multiple points — input validation, agent execution, guardrail enforcement, provider timeouts, or infrastructure failures. Understanding the failure model helps you handle errors gracefully, design resilient swarms, and choose the right execution mode for the guarantees your workflow requires.

## Where Failures Can Occur

- **Input guardrail** — before the first agent runs; blocks dispatch and records a preflight failure row
- **Agent execution** — during an individual agent's LLM call; provider errors, tool call failures, and malformed responses surface here
- **Step guardrail** — after an agent completes, before that step is recorded; blocks before the output is persisted
- **Output guardrail** — after the last agent, before the run is marked completed; blocks before `SwarmCompleted` fires
- **Timeout** — orchestration deadline exceeded; ordinary modes check at step boundaries, while process-backed top-level parallel streams also enforce it during live multiplexing
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

**When thrown:** when a swarm that cannot be safely container-resolved is dispatched via `queue()` or a parallel execution path, or when `queue()` / `broadcastOnQueue()` cannot resolve an encrypter from a valid `APP_KEY`. Laravel Swarm validates queueability before dispatch to prevent cryptic serialization failures inside queue workers.

**Public properties:** none beyond the inherited `message`.

**Catching it:** This exception fires at the call site, before any queue job is dispatched. Set a valid `APP_KEY` for encrypted queued jobs. Otherwise, fix the swarm class to be container-resolvable (constructor-injectable dependencies only, no runtime instance state) rather than catching it:

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

- Ordinary prompt, queue, sequential-stream, hierarchical, and durable paths check the deadline at their documented step boundaries; an in-progress remote LLM call is not hard-cancelled.
- Opt-in top-level parallel live streaming also checks the absolute deadline while polling sockets and waiting for consumer acknowledgement. It terminates and reaps local branch processes on expiry, but cannot guarantee cancellation of remote provider work already accepted.
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

Ordinary `InvokeSwarm` / `BroadcastSwarm` jobs use `swarm.queue.tries` (default 1), overriding the worker's tries default. A retry may restart uncheckpointed workflow work and repeat effects. `SwarmFailed` is emitted by the [runner failure path](../src/Runners/SwarmRunner.php), not only after Laravel exhausts retries. Generated hierarchical `multi_worker` execution has separate branch/join recovery; its [resume job](../src/Jobs/ResumeQueuedHierarchicalSwarm.php) uses the durable advance retry profile. [Unsupported native approval outcomes](native-outcome-boundary.md) are nonretryable regardless of these settings.

Queued whole-workflow `then()` / `catch()` callbacks are opt-in — see [Terminal Workflow Callbacks](#terminal-workflow-callbacks). With `swarm.callbacks.enabled` off (the default) they throw `BadMethodCallException`; listen to `SwarmCompleted` and `SwarmFailed` lifecycle events instead. Stream callbacks remain supported.

### `stream()`

The stream terminates. A `swarm_stream_error` event is yielded, run history is marked failed, and `SwarmFailed` fires. Partial events already delivered to the client (SSE bytes already flushed) cannot be recalled. The exception is re-thrown to the caller after the stream terminates, so a try/catch around the `foreach` loop will see it. You may also register an in-process `catch()` handler on the [StreamableSwarmResponse](../src/Responses/StreamableSwarmResponse.php) (see [Terminal Workflow Callbacks](#terminal-workflow-callbacks)); it runs on the failure and the original exception still propagates — `catch()` is a handler, not a suppressor. Recovery is not available — a failed stream must be re-submitted as a new run.

### `dispatchDurable()`

The failed step is checkpointed. The `DurableRetry` policy applies (if configured) and `swarm:recover` redispatches due retries without replaying completed steps. `SwarmFailed` fires when the run reaches a terminal failed state. See [Durable Recovery](#durable-recovery) below.

## Terminal Workflow Callbacks

Terminal callbacks are a convenience over the `SwarmCompleted` / `SwarmFailed` lifecycle
events for the **whole workflow** — not a per-agent hook. They are off by default; enable
with `swarm.callbacks.enabled=true`, which requires the database persistence driver and an
`APP_KEY` in every process that registers or delivers a callback (callbacks are signed with it).

```php
// Queued or durable: then() on completion, catch() on failure.
ReportSwarm::make()
    ->queue(['user_id' => $user->id])
    ->then(fn (SwarmTerminalContext $ctx) => Log::info("run {$ctx->runId} completed"))
    ->catch(fn (SwarmTerminalContext $ctx) => Log::warning("run {$ctx->runId} failed: {$ctx->exceptionClass}"));

// Streaming: catch() handles a failed stream in-process.
ArticlePipeline::make()->stream($input)
    ->catch(fn (Throwable $e) => report($e))
    ->each(fn ($event) => broadcast(...));
```

### Callbacks versus events

| | Terminal callbacks | Lifecycle events |
|---|---|---|
| Enable | `swarm.callbacks.enabled` (DB driver) | always on |
| Registered | fluently, per run | globally, per listener |
| Queue/durable delivery | at-least-once via `swarm:relay` | synchronous, in the settling process |
| Payload | `SwarmTerminalContext` summary | full event object |

Reach for events when you need a global, always-on hook or the full event payload; reach for
callbacks when you want to attach behavior to *this* run at the call site.

### Semantics

- **`then` is armed once for a settled completion. `catch` is armed once for a settled failure.**
  Neither is armed on cancellation, an intermediate agent success, or a recoverable
  error. Delivery remains at-least-once, so an armed callback may execute again after a crash or
  expired lease. The permanent `UnsupportedNativeApprovalException` boundary is a terminal
  failure: `catch` is armed, `then` never.
- **Queue and durable callbacks are delivered asynchronously, at-least-once**, by
  `swarm:relay --type=callback` after the run settles — in a separate process from the run.
  Never exactly-once: a crash after the callback runs but before its delivery row is removed
  re-delivers it. **Make your callbacks idempotent.** Schedule `swarm:relay` for them to fire
  at all. Relay reservations and queue-dispatch failures do not consume delivery attempts;
  an attempt begins only when a delivery job atomically acquires its current claim token and
  starts processing the callback. Duplicate or stale jobs with an obsolete token are no-ops.
  The stream `catch()` runs in-process, synchronously, on the failing iteration.
- **A queue/durable callback receives a `SwarmTerminalContext`**, not the full `SwarmResponse`:
  a lightweight, always-available summary (run id, swarm class, topology, and, for `catch`, the
  settled exception class/message) built from the terminal record. Capture is off by default and
  payloads are sensitive, so the full response is not reconstructed at delivery time — read it
  from `SwarmHistory` by run id when you need it. The stream `catch()` receives the live `Throwable`.
- **A callback must be a serializable closure** (it is signed and sealed for later delivery in
  another process); capturing a non-serializable binding (a database handle, an open resource)
  throws at registration. Payload authorization is the closure signature — a tampered delivery
  row is never invoked, it is dead-lettered. Signing uses `APP_KEY`: a delivery row that is not a
  signed closure is rejected: the signed wrapper may be restored for verification, but foreign
  payload objects and unsigned closure bodies are never constructed or invoked. In a process with
  no `APP_KEY`, `then()` / `catch()` throw `SwarmException` at registration, and a delivering
  process with no `APP_KEY` never runs a callback.
  A registration failure rejects only that callback: the queued or durable run already exists and
  is still dispatched if the caller catches the exception, with no callback attached.
- **A callback's own failure is isolated.** It runs after the workflow has already settled, in a
  separate process, so it can neither replay completed model or tool effects nor change the
  recorded result. A failing queue/durable callback is retried up to `swarm.callbacks.max_attempts`
  and then dead-lettered. A failure releases the claim and becomes eligible again after
  `swarm.callbacks.retry_backoff_seconds`; a throwing stream `catch()` is reported and swallowed
  so it cannot mask the workflow's own error.

### Operating the delivery outbox

Callback deliveries are persisted in `swarm_callback_deliveries`.

- **Schedule the relay.** Delivery only happens through `swarm:relay` — a plain `queue()`
  app that had no reason to schedule the relay before **must schedule it now** once callbacks
  are enabled, or callbacks never fire (and their rows are eventually pruned as orphans once
  the run's history expires). When eligible work ages, `swarm:health` asks "is
  swarm:relay scheduled?"; a clean row reports counts without that warning.
- **Deliver:** `swarm:relay --type=callback` (or a bare `swarm:relay`, which drains every lane).
- **Queue routing.** `swarm.callbacks.queue.connection` and `swarm.callbacks.queue.name` route
  `DeliverSwarmCallback` jobs; null values use the application's defaults. A worker must consume
  that connection/queue. `swarm:health` fails when an enabled callback connection is absent from
  `queue.connections` (and reports a note while callbacks are disabled). The reservation window
  must account for the selected queue's delay as well as callback execution.
- **Kill switch.** Setting `swarm.callbacks.enabled=false` stops new registration and deliveries
  that have not yet started: the relay lane goes inert, and already-dispatched jobs no-op when they
  reach the delivery-time flag check. A delivery already past that check continues; the switch does
  not interrupt an executing closure. Terminal runs still atomically settle rows registered before
  the switch, so re-enabling resumes those pending rows instead of stranding them. A flag-off,
  callback-free database terminal write performs one indexed existence check outside a transaction,
  plus one callback-table existence probe on the first such write after the table exists; later writes
  reuse that positive result. An installation without the callback table re-probes once per terminal
  write so a migration becomes visible without a worker restart. It then retains that writer's
  pre-feature transaction behavior, except `recordPreflightFailure()` always uses one transaction
  to make its write-once check atomic. The indexed
  check cannot be removed safely: another process may have registered the row before the switch was
  turned off. This database-driver write-once terminal-history guarantee does not apply to the cache
  history driver.
- **Reservation window.** A claimed-but-undelivered row is re-claimed after
  `swarm.callbacks.reservation_timeout_seconds` (falling back to the durable relay timeout). If a
  pending reservation expires, its token is retained and another job is dispatched with that token,
  so an earlier job delayed in the same queue backlog can still acquire the row; the compare-and-set
  permits only one of those jobs to acquire it. A stale `delivering` lease receives a new token, so
  the earlier executing lease can never acknowledge or mutate its replacement. Concurrent callback
  execution can overlap only when a delivering lease expires while its original worker is still
  running. Size this timeout **longer than the longest callback execution**, including queue delay,
  and keep callbacks idempotent.
- **Inspect:** `swarm:health` reports registered, pending, delivering, and dead-letter counts even
  while delivery is disabled. It warns on dead letters, expired pending reservations, stale
  in-flight deliveries, and eligible work older than
  `swarm.callbacks.stale_warning_threshold_seconds` (zero means twice the reservation timeout).
- **Dead-letters are not auto-recovered.** A callback that exhausts `swarm.callbacks.max_attempts`
  moves to `dead_letter` and stops being delivered; there is no requeue command (unlike the audit
  lane). Lowering that cap also dead-letters any eligible pending or stale-delivering row whose
  recorded delivery acquisitions already meet the new cap; it does not grant one extra attempt.
  If guaranteed delivery matters, listen to `SwarmCompleted` / `SwarmFailed` instead — those
  are the reliable path. A dead-letter caused by an **`APP_KEY` rotation** (which invalidates every
  in-flight callback's signature) is expected: rotate with no pending callbacks, or accept their loss.
  The dead-letter log context carries the specific fixed package reason category, never an exception
  message. The categories tied to signing and sealing include:
  `callback signature verification failed` — the delivering key is not the one that signed the row
  (a rotation, or a row stored unsigned), or the row was tampered with;
  `no APP_KEY signing key is configured` — the delivering process has no `APP_KEY`;
  `callback payload could not be decrypted` — at-rest encryption could not open the row, usually a
  rotation; `callback payload is not a serialized closure` — the row held something else entirely.
  In those rejected rows, foreign payload objects and unsigned closure bodies are never constructed
  or invoked. A process with no `APP_KEY` cannot register a callback at all — registration throws,
  with at-rest encryption on or off. With at-rest
  encryption on (the default), a delivering process with no `APP_KEY` cannot seal a dead-letter
  reason either: the delivery attempt errors and leaves the row `delivering` until its lease expires
  and a key-bearing worker recovers it.
- **Audit evidence.** Each successful delivery emits one `callback.delivered` record, signed when
  audit signing is configured, with `delivery_id`, `run_id`, `slot`, and `attempts`; it never
  includes the closure, terminal context, or callback result. Dead letters are recorded in the
  application log with the delivery id, run id, slot, attempts, a static reason category, and the
  exception class when one exists; that package-owned log line never includes the exception message.
  The delivery row stores the message only in sealed `last_error`. Separately, callback exceptions
  and closure decryption/signature exceptions are reported as their original `Throwable` through
  Laravel's application exception handler before retry or dead-letter handling, matching other
  failing jobs and outbox lanes. The application handler can therefore send the original message to
  its configured logs or error tracker.
- **Callbacks run without ambient request/tenant state.** A delivered callback runs later, in the
  relay/worker process, with no HTTP request and no ambient tenant context. Capture everything the
  closure needs (ids, not `tenant()` globals) at registration.
- **Prune:** `swarm:prune` removes non-delivering records for terminal, expired runs. A positive
  `swarm.callbacks.dead_letter_retention_days` may remove dead letters sooner; null disables only
  that age-based policy, so a dead letter is still removed when its run history expires. It never
  orphan-prunes a `delivering` row: a live lease may still be executing, and an expired lease remains
  reclaimable by the relay. It honors `swarm.retention.prevent_prune`.
- **Rollback / drain:** undelivered rows are lost on the down-migration, and draining only moves
  rows into delivery jobs. To revert safely: stop dispatching new runs, run
  `swarm:relay --type=callback --drain-until-empty`, **wait for the callback queue workers to
  finish** the dispatched `DeliverSwarmCallback` jobs, confirm health has no pending, delivering,
  or dead-letter rows, then stop or restart every long-lived worker before running the down migration
  so no process retains positive table readiness. If rollback is urgent, disable registration at
  the application boundary first; the kill switch also stops the drain you are trying to finish.

## Queue Retry vs Durable Retry

These are distinct mechanisms and should not be confused.

**Queue retry** — Laravel re-delivers an `InvokeSwarm` job. Ordinary queueing has no per-step recovery cursor, so work may start over; terminal history and coordinated leases can suppress a duplicate. Configure ordinary job attempts with `swarm.queue.tries` / `SWARM_QUEUE_TRIES`, only for idempotent workloads. Generated hierarchical `multi_worker` joins instead have the [coordinated recovery path](hierarchical-routing.md#queue). Neither retry mechanism guarantees exactly-once external effects.

```env
SWARM_QUEUE_TRIES=3
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

Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)));
```

The five-minute interval is the canonical recovery schedule. Keep the explicit
scheduler mutex as defense in depth and configure the command-owned finite lease
for manual and supervisor invocations; see [Command overlap leases](maintenance.md#command-overlap-leases).

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
