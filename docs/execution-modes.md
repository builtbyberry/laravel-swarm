# Execution Modes

Every swarm class supports six execution modes through the `Runnable` trait: `prompt()` (and its alias `run()`), `queue()`, `stream()`, `broadcast()` / `broadcastNow()`, `broadcastOnQueue()`, and `dispatchDurable()`. The mode you choose determines whether the run is synchronous or background, whether it streams tokens to the caller, and whether it can recover from partial failures mid-run. Most of the modes mirror the Laravel AI agent API deliberately — if you know how to run an agent, the same verbs work on swarms.

## Comparison Table

| Method | Return Type | Runs in Background | Streaming | Checkpointing | Recovery | Complexity |
|---|---|---|---|---|---|---|
| `prompt()` / `run()` | `SwarmResponse` | No | No | No | No | Low |
| `queue()` | `QueuedSwarmResponse` | Yes | No | No | No (restarts) | Low |
| `stream()` | `StreamableSwarmResponse` | No | Yes | No | No | Medium |
| `broadcast()` / `broadcastNow()` | `StreamableSwarmResponse` | No | Yes (push) | No | No | Medium |
| `broadcastOnQueue()` | `QueuedSwarmResponse` | Yes | Yes (push) | No | No | Medium |
| `dispatchDurable()` | `DurableSwarmResponse` | Yes | No | Yes | Yes | High |

**Streaming** column means typed token events are emitted while the run progresses. **Checkpointing** means per-step state is persisted so the run can be resumed after a worker death. **Recovery** means a crashed or stalled run can be automatically advanced by `swarm:recover` without re-running completed steps.

## Decision Tree

```mermaid
flowchart TD
    A[Start] --> B{Can the caller wait?}
    B -- Yes --> C{Need real-time token stream?}
    B -- No --> D{Need recovery if server dies?}
    C -- Yes --> E[stream() or broadcast()]
    C -- No --> F[prompt()]
    D -- Yes --> G[dispatchDurable()]
    D -- No --> H[queue()]
```

`broadcast()` and `broadcastNow()` run in-process like `stream()` but push events to Laravel Echo / Reverb / Pusher rather than returning them to the HTTP caller. `broadcastOnQueue()` moves that same push to a background worker.

## Deep Dive On Each Mode

### `prompt()` / `run()`

```php
$response = ContentPipelineSwarm::make()->prompt([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
]);

$response->output; // string
$response->steps;  // array of SwarmStep
```

**Returns:** `SwarmResponse`

**When to use:**
- Synchronous API endpoints where the client can wait and the total runtime is within your server timeout budget.
- Webhook handlers that must return a result in the same request.
- CLI commands where a human is watching progress.
- Tests — `prompt()` is the easiest mode to assert against.

**When NOT to use:** If the provider is slow or the swarm has many agents, `prompt()` holds an HTTP worker for the full duration. Any provider timeout becomes an exception propagated directly to the caller.

**Gotchas:**
- Blocks the HTTP worker until all agents complete.
- Provider timeouts and agent errors surface as exceptions at the call site.
- `#[Timeout]` is a best-effort orchestration deadline — it checks before and between agent steps but does not hard-cancel an in-flight provider request.

`run()` is a compatibility alias; both methods call the same path.

### `queue()`

```php
$response = ContentPipelineSwarm::make()->queue([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
]);

// Chain queue configuration using the underlying PendingDispatch:
ContentPipelineSwarm::make()
    ->queue(['topic' => 'Laravel queues'])
    ->onQueue('ai-processing')
    ->delay(now()->addSeconds(10));
```

**Returns:** `QueuedSwarmResponse` — wraps a `PendingDispatch` and exposes the run ID. Unknown method calls are proxied to the underlying `PendingDispatch`, so `onQueue()`, `delay()`, and similar Laravel queue fluency all work.

**When to use:**
- Background processing where the caller does not need to wait for a result.
- Email generation, report building, content moderation, anything fire-and-forget.
- Any swarm that would push an HTTP request past a comfortable timeout.

**When NOT to use:**
- When you need to stream tokens to a connected client (use `stream()` or a broadcast helper).
- When the workflow is long-running enough that a mid-job server restart would be expensive to replay from the beginning (use `dispatchDurable()`).

**Gotchas:**
- One Laravel queue job owns one full swarm run. If the job fails partway through, all progress is lost — queue retries restart from the beginning, not from the last completed agent.
- Do not use serialized closures with `then()` / `catch()` on the response for real workloads — they can capture excess state, fail serialization, or embed sensitive data in queue payloads. Listen to `SwarmCompleted` and `SwarmFailed` lifecycle events instead.
- Queued swarms are re-resolved from the container; do not rely on runtime instance state. Pass per-run data in the task payload or a `RunContext`.

#### Queue retry & timeout

Queued swarm jobs are attempted **once** by default, regardless of the queue worker's `--tries` flag.

**Why once?** A queued run holds no checkpoint. A retry does not resume from the last completed agent — it re-executes the entire swarm from step 0: re-dispatching all tool calls and re-spending all LLM tokens. Silently inheriting a worker-wide `--tries=3` means a transient worker crash restarts a full, potentially expensive run three times over. Swarm asserts the safe default here because only Swarm knows these runs are not checkpointed.

**Contrast with durable jobs.** `dispatchDurable()` jobs derive explicit tries/timeout/backoff from `swarm.durable.job.*` and are safe to retry because each attempt advances from the last persisted checkpoint, not from the beginning.

**Opting in.** If your swarms are idempotent and the token cost of a full restart is acceptable, raise the limit via config or env:

```env
SWARM_QUEUE_TRIES=3
```

```php
// config/swarm.php (or override at runtime)
'queue' => [
    'tries' => 3,
],
```

**Timeout.** The package does not impose a job-level timeout on queued runs. A low ceiling would kill legitimately long LLM pipelines. The safety concern for queued swarms is retries, not timeout. To impose an explicit ceiling, set `SWARM_QUEUE_TIMEOUT` (seconds):

```env
SWARM_QUEUE_TIMEOUT=600
```

### `stream()`

```php
// Iterate in PHP
foreach (ContentPipelineSwarm::make()->stream([
    'topic' => 'Laravel queues',
]) as $event) {
    if ($event->type() === 'swarm_text_delta') {
        echo $event->delta;
    }
}

// Return directly from a controller for SSE
return ContentPipelineSwarm::make()->stream([
    'topic' => 'Laravel queues',
]);
```

**Returns:** `StreamableSwarmResponse` — a lazy iterable that yields typed `SwarmStreamEvent` objects.

**When to use:**
- SSE endpoints where a browser or CLI client needs to see tokens as they arrive.
- Streaming chat or copilot UIs backed by a single HTTP connection.
- Any workflow where step lifecycle events (`swarm_step_start`, `swarm_step_end`) provide meaningful UX progress.

**Topology constraint — sequential only.** Parallel and hierarchical swarms involve coordinating multiple concurrent agents, which does not map to a single ordered event stream. If you need streaming output from a multi-agent workflow, use a sequential swarm and compose the agents in order.

**When NOT to use:**
- Parallel or hierarchical topologies.
- When the client might disconnect mid-stream — events already emitted to the transport cannot be recalled, and if the run fails after partial emission there is no automatic recovery.
- When you need the run to outlive the HTTP request (use `broadcastOnQueue()` or `dispatchDurable()`).

**Gotchas:**
- A failed stream yields a `swarm_stream_error` event, marks run history failed, dispatches `SwarmFailed`, and re-throws the underlying exception to the caller. Partial events already sent to the client cannot be unsent.
- In-memory replay (iterating the same response object again) is always available after the stream completes. Database-backed replay is opt-in via `->storeForReplay()` or `SWARM_STREAM_REPLAY_ENABLED=true`.
- Replay write failures default to failing the stream. Set `SWARM_STREAM_REPLAY_FAILURE_POLICY=continue` if live streaming should proceed even when the replay store cannot be written; in that mode, any replay events already written for the run are discarded so partial playback is never served.

See [Streaming](streaming.md) for the full event type reference, replay configuration, and SSE patterns.

### `broadcast()` and `broadcastOnQueue()`

```php
use Illuminate\Broadcasting\PrivateChannel;

// In-process: consumes stream immediately, broadcasts each event
ContentPipelineSwarm::make()->broadcast(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.'.$userId),
);

// `broadcastNow()` forces immediate (synchronous) delivery
ContentPipelineSwarm::make()->broadcastNow(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.'.$userId),
);

// Background: dispatch a worker that streams and broadcasts
ContentPipelineSwarm::make()
    ->broadcastOnQueue(
        ['topic' => 'Laravel queues'],
        new PrivateChannel('swarm.'.$userId),
    )
    ->onQueue('ai-streams');
```

**Returns:**
- `broadcast()` / `broadcastNow()` return `StreamableSwarmResponse`
- `broadcastOnQueue()` returns `QueuedSwarmResponse`

**When to use:**
- Pushing typed stream events to clients connected over Laravel Echo, Reverb, or Pusher rather than holding an open SSE connection.
- Situations where the HTTP request can return immediately while a background worker delivers streaming progress to a connected front-end.

**Key difference between `broadcast()` and `broadcastOnQueue()`:**
- `broadcast()` consumes the stream in the current process (the HTTP worker) and broadcasts each event synchronously. The HTTP request is held open until the swarm completes.
- `broadcastOnQueue()` dispatches a queue job. The HTTP response returns immediately, and a worker handles streaming and broadcasting. This is the right default for production when the client uses WebSockets and you do not want to tie up HTTP workers.
- `broadcastNow()` is `broadcast()` with forced immediate delivery — it bypasses any configured queue for the broadcast transport itself.

**Topology constraint — sequential only.** Same reason as `stream()`: broadcast helpers emit an ordered event stream and require a single sequential execution path.

**Gotchas:**
- Broadcast helpers do not retry or buffer transport delivery. If Laravel broadcasting throws during event delivery, `broadcast()` / `broadcastNow()` rethrow the exception and `broadcastOnQueue()` lets the queued job fail.
- If delivery fails before the terminal `swarm_stream_end` event, run history is marked failed. If delivery fails on the terminal event itself, swarm execution has already completed — history remains completed and persisted replay may include the terminal event.
- Use Laravel's broadcast and queue infrastructure (retries, dead-letter queues) for transport-level reliability.

See [Streaming](streaming.md) for the event type reference and broadcast configuration.

### `dispatchDurable()`

```php
$response = ContentPipelineSwarm::make()->dispatchDurable([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'full draft',
]);

$runId = $response->runId; // store this — you will use it to track, pause, or cancel

// Operator controls are available on the response object
$response->pause();
$response->resume();
$response->cancel();
$detail = $response->inspect(); // DurableRunDetail
```

**Returns:** `DurableSwarmResponse` — contains the `runId` (a string UUID) and exposes operator controls (`pause()`, `resume()`, `cancel()`, `signal()`, `inspect()`).

**When to use:**
- Multi-step workflows that must survive server restarts, deploy cycles, or worker pool fluctuations.
- Operator-controlled workflows where a human may need to pause, resume, or cancel a run mid-execution.
- Long-running processes measured in minutes to hours where restarting from the beginning is expensive.
- Production-critical workflows (billing pipelines, compliance checks, large data migrations) where partial progress must not be discarded.

**Topology:** Sequential, parallel, and hierarchical swarms are all supported. For hierarchical swarms, the coordinator runs first and returns the route plan; Laravel Swarm persists that plan and advances one routed worker node per durable job. Parallel groups create durable branch jobs with independent leases, then join before continuing.

**When NOT to use:**
- Simple background tasks that complete in a few seconds or where a full restart is acceptable. Use `queue()` — it is simpler and has no operational overhead.
- When you need token streaming to a connected client (durable execution does not emit a real-time token stream; use lifecycle events and application-owned broadcasts for progress UIs).

**Key requirements:**
- `swarm.persistence.driver` must be `database` — durable state cannot be held only in cache.
- Package migrations must be published and run before dispatching durable runs.
- Schedule `swarm:recover` frequently (every minute recommended) so stalled runs are automatically advanced after worker failures.
- Schedule `swarm:prune` to retire expired run records.

**Gotchas:**
- `DurableSwarmResponse` does not support `then()` / `catch()` callbacks. Listen to `SwarmCompleted` and `SwarmFailed` lifecycle events instead.
- `dispatchDurable()` adds operational overhead: the relay schedule, recovery monitoring, and prune jobs must be wired up or stalled runs accumulate silently.
- `swarm:recover` can be inspected with `swarm:status` — the **Phase** column shows `parallel_join` when a hierarchical coordinated run is waiting on parallel branches.

See [Durable Execution](durable-execution.md) for checkpointing internals, operator controls, signal/wait patterns, and the runtime architecture.

## Mixing Modes

The `Runnable` trait is stateless — it holds no run-time data between calls. The swarm class itself only defines the agent list, topology, and attributes like `#[Timeout]` and `#[MaxAgentSteps]`. The mode is chosen entirely at the call site, so the same swarm class can be invoked with different modes from different places in the application without any conflict.

A common pattern is to use `prompt()` in tests (simple, no queue infrastructure required) and `dispatchDurable()` in production:

```php
// In a feature test
ContentPipelineSwarm::fake();
ContentPipelineSwarm::make()->prompt(['topic' => 'Test topic']);
ContentPipelineSwarm::assertPrompted(fn ($task) => $task['topic'] === 'Test topic');

// In production (controller or action)
$response = ContentPipelineSwarm::make()->dispatchDurable([
    'topic' => $request->topic,
    'audience' => $request->audience,
    'goal' => $request->goal,
]);

session()->put('pipeline_run_id', $response->runId);
```

The swarm class definition does not change between these call sites. Only the runner path changes.

A similar split works for `queue()` in staging (simpler infrastructure) and `dispatchDurable()` in production, or `prompt()` in a CLI tool and `queue()` in a web endpoint.

## Migration Path

### Start with `prompt()`

The simplest starting point. No queue workers, no database persistence requirements, no operational overhead. Good for most use cases during development and for workloads that complete within your HTTP timeout budget.

```php
$response = ContentPipelineSwarm::make()->prompt([
    'topic' => $topic,
    'audience' => $audience,
]);

return response()->json(['output' => $response->output]);
```

### Move to `queue()` when you need background processing

When the swarm starts hitting HTTP timeouts or you want to return a response to the user while work continues in the background, swap to `queue()`. Listen to lifecycle events for completion.

```php
// Dispatch and return immediately
ContentPipelineSwarm::make()
    ->queue(['topic' => $topic, 'audience' => $audience])
    ->onQueue('ai-processing');

return response()->json(['status' => 'processing']);

// In an event listener or notification class
class ContentPipelineListener
{
    public function handle(SwarmCompleted $event): void
    {
        if ($event->swarmClass !== ContentPipelineSwarm::class) {
            return;
        }

        // Notify the user, store the result, trigger downstream work
        $event->response->output;
    }
}
```

### Move to `dispatchDurable()` when you need recovery or operator controls

When the swarm runs long enough that server restarts become a real concern, or when you need to pause, resume, or cancel runs from an operator interface, swap `queue()` for `dispatchDurable()`. The task payload does not change.

```php
// Before (queue)
ContentPipelineSwarm::make()
    ->queue(['topic' => $topic, 'audience' => $audience]);

// After (durable) — same task, different method, richer response
$response = ContentPipelineSwarm::make()->dispatchDurable([
    'topic' => $topic,
    'audience' => $audience,
]);

// Store the run ID for tracking and operator controls
PipelineRun::create([
    'user_id' => $user->id,
    'run_id' => $response->runId,
]);
```

Make sure the following is in place before switching to durable execution:

```php
// config/swarm.php
'persistence' => [
    'driver' => 'database', // required for durable execution
],

// routes/console.php
Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

## Production Mode Selection

| Scenario | Recommended Mode |
|---|---|
| User-facing synchronous flows (short runs, within HTTP timeout) | `prompt()` |
| Background processing, notifications, report generation | `queue()` |
| Streaming chat or copilot UI (SSE) | `stream()` |
| Streaming to WebSocket clients (Echo / Reverb / Pusher) | `broadcastOnQueue()` |
| Critical workflows: billing, compliance, data migration | `dispatchDurable()` |
| Long-running multi-step pipelines (minutes to hours) | `dispatchDurable()` |
| Operator-controlled workflows (pause/resume/cancel) | `dispatchDurable()` |

When in doubt, start with `prompt()` or `queue()` and migrate to `dispatchDurable()` only when recovery or operator controls become a real requirement. Durable execution is the right tool for workflows where losing mid-run progress is unacceptable — it is not the default for every background task.

## Related

- [Streaming](streaming.md) — stream event types, SSE patterns, persisted replay, broadcast helpers
- [Durable Execution](durable-execution.md) — checkpointing internals, operator controls, runtime architecture
- [Testing](testing.md) — fakes, assertions, and mode-specific test patterns
