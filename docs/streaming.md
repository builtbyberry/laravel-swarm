# Streaming

Use `stream()` when a browser, CLI, or other client needs **live typed progress**
while a **sequential** swarm runs. The method returns a lazy
`StreamableSwarmResponse` that yields the same kinds of events whether you
iterate in PHP, return the response from a controller, or replay persisted
events later.

For how context, history, and replay rows are stored, see
[Persistence And History](persistence-and-history.md). For checkpointed
execution across jobs, see [Durable Execution](durable-execution.md) — that
mode is separate from `stream()`.

## When To Use `stream()`

- You want step lifecycle events plus final-agent text, reasoning, and tool
  stream events for SSE or custom progress UIs.
- A single HTTP request should own the full sequential workflow while emitting
  progress.
- You may later need **persisted replay** of the exact emitted timeline (opt-in).

Use `prompt()` when the caller only needs the final aggregate result. Use
`queue()` or `dispatchDurable()` when the work should outlive the request or
needs background or checkpointed execution.

## Topology: Sequential and Static-Hierarchical

Streaming is supported for **sequential** and **static-hierarchical** swarms.
Static-hierarchical streams worker nodes live, fan parallel groups out in
`concurrent` or `sequential` mode, and honor [bounded loops](static-hierarchical-topology.md#bounded-loops)
— a looped worker re-streams up to `max_iterations` before falling through to its
exit. Dynamic (router-driven) hierarchical workflows use other execution modes
(`prompt()`, `queue()`, `dispatchDurable()`).

## Consuming Stream Events

Iterate in PHP:

```php
use App\Ai\Swarms\ArticlePipeline;

foreach (ArticlePipeline::make()->stream([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]) as $event) {
    if ($event->type() === 'swarm_text_delta') {
        // $event->delta
    }
}
```

Return from a route for Laravel AI-style SSE (`data:` lines by default):

```php
return ArticlePipeline::make()->stream([
    'topic' => 'Laravel queues',
]);
```

For Laravel 13 named SSE events, each swarm stream event exposes
`toStreamedEvent()` for use with `response()->eventStream()`. See the
[Streaming Progress](../examples/streaming-progress/README.md) example.

Like Laravel AI stream responses, a **completed** stream can be iterated again
in the same PHP process without re-running the swarm (in-memory replay).

## Broadcasting Stream Events

Laravel Swarm also exposes Laravel AI-style broadcast helpers for the same typed
stream events:

```php
use App\Ai\Swarms\ArticlePipeline;
use Illuminate\Broadcasting\PrivateChannel;

ArticlePipeline::make()->broadcast(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.article-pipeline'),
);

ArticlePipeline::make()->broadcastNow(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.article-pipeline'),
);

ArticlePipeline::make()
    ->broadcastOnQueue(
        ['topic' => 'Laravel queues'],
        new PrivateChannel('swarm.article-pipeline'),
    )
    ->onQueue('ai-streams');
```

`broadcast()` consumes the stream immediately and broadcasts each
`SwarmStreamEvent` through Laravel broadcasting. `broadcastNow()` uses immediate
delivery. `broadcastOnQueue()` dispatches a worker that streams the swarm once,
broadcasts each event immediately from the worker, and records completion
through normal swarm history and lifecycle events.

These are stream-event helpers, not lifecycle broadcasting for every topology.
They are sequential-only for the same reason `stream()` is sequential-only. For
prompt, queued, durable, parallel, or hierarchical operational feeds, listen to
Laravel Swarm lifecycle events and broadcast your own application events.

Broadcast helpers do not retry or buffer transport delivery. If Laravel
broadcasting throws while the helper is consuming the stream, live `broadcast()`
/ `broadcastNow()` rethrow the transport exception and `broadcastOnQueue()` lets
the queued job fail. If delivery fails before terminal completion is yielded,
run history is marked failed.

If delivery fails while broadcasting the terminal `swarm_stream_end` event, the
helper or queued job still fails, but swarm execution has already completed:
history remains completed, and persisted replay may include the terminal event.
Use Laravel's broadcast and queue infrastructure for transport retries.

## Stream Event Types

Swarm streams emit typed events, including:

| Type | Role |
| --- | --- |
| `swarm_stream_start` | Run metadata and captured input. |
| `swarm_step_start` | Step lifecycle start with captured step input. |
| `swarm_text_delta` / `swarm_text_end` | Final-agent text chunks and close marker. |
| `swarm_reasoning_delta` / `swarm_reasoning_end` | Final-agent reasoning stream events. |
| `swarm_tool_call` / `swarm_tool_result` | Final-agent tool invocation and results. |
| `swarm_step_end` | Step completion with captured or limited output and usage metadata. |
| `swarm_stream_end` | Terminal completion with final output and aggregate usage. |
| `swarm_stream_error` | Terminal failure payload for live failure and persisted replay. |

**Provenance:** For upstream final-agent streamed provider events, Laravel Swarm
preserves upstream event **IDs** and **timestamps** in typed replay. **Invocation
IDs** are passed through when the upstream provider includes them.

### Tool calls (including MCP tools)

Swarm's tool model is **pure passthrough**. A `laravel/ai` `ToolCall` /
`ToolResult` is carried as an opaque object through tool-call capture, the step
memory snapshot, and the streamed `swarm_tool_call` / `swarm_tool_result`
events — Swarm does not interpret the tool's arguments or result. That means the
**MCP client/server tools** added in `laravel/ai` 0.8 are supported with no
MCP-specific configuration: an MCP-backed tool's call and result flow through the
stream and the durable snapshot exactly like any other tool, including a
**structured** (non-scalar) MCP result, which is preserved intact.

A tool's `result` and its `arguments` are both typed `mixed`, so at the edges
either can be a value JSON cannot represent (for example, a binary-ish MCP result
with invalid UTF-8). Such a value **degrades safely at the tool boundary**: that
one field is replaced by a typed placeholder and a **class-only** breadcrumb is
logged (the tool name and exception class, never the payload bytes). An
unencodable result becomes
`{"__swarm_unencodable_tool_result__": true, "tool": "<name>"}`; unencodable call
arguments become `{"__swarm_unencodable_tool_arguments__": true, "tool": "<name>"}`.
The run is never crashed by a single unencodable tool value, and the strict
encoder the audit/durable/resume stores depend on is left untouched. The
placeholder is a faithful record that the original value was not representable —
it is not a tamper signal, and the strict read path stays strict. (A tool result
is the field that realistically carries such a value; arguments share the same
type and degrade path for safety.)

## Persisted Replay

In-memory replay is always available after a successful synchronous stream
completes. **Database-backed replay** of the exact emitted sequence is **opt-in**.

Enable it per response:

```php
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;

return ArticlePipeline::make()
    ->stream(['topic' => 'Laravel queues'])
    ->storeForReplay();
```

Or globally with `SWARM_STREAM_REPLAY_ENABLED=true` / `swarm.streaming.replay.enabled`.

Replay write failures default to failing the stream so history does not remain
`running` after replay persistence breaks. Set
`SWARM_STREAM_REPLAY_FAILURE_POLICY=continue` if live streaming should continue
and replay should be disabled for the rest of that response when the replay store
cannot be written. When `continue` is used, any replay events already written for
that run are discarded so a later replay cannot return a partial timeline.

Replay later by run ID:

```php
return SwarmHistory::replay($runId);
```

`SwarmHistory::replay($runId)` is lazy: database-backed replay reads events in
stored order as the response is iterated. If the original stream **failed**,
replay emits stored events through `swarm_stream_error` and completes **without**
re-throwing the original exception (informational playback).

Configuration for replay storage drivers and prefixes lives under
`swarm.streaming.replay` in `config/swarm.php`. See
[Persistence And History — Replaying Stream Events](persistence-and-history.md#replaying-stream-events).

## Crash-Replay Durability

Persisted replay above re-yields a stream that **completed**. A separate
guarantee covers the stream that **did not**: a non-durable streamed run whose
generator is abandoned mid-stream — a worker crash, a dropped HTTP connection, a
`break` out of the loop before `swarm_stream_end`.

Two things make such a run recoverable:

- **Crash-safe tool-call capture.** Each agent the runner streams freezes a
  memory snapshot keyed by `(run_id, step_index)` before its invocation. Tool
  calls observed during the invocation are appended to that snapshot. If the
  generator is torn down mid-stream, any tool call still in flight (a
  `swarm_tool_call` whose `swarm_tool_result` never arrived) is still flushed
  into the snapshot with a `null` result. No partial or lost pairs: the frozen
  snapshot is a faithful record of every tool the agent invoked before the
  tear-down.
- **Byte-identical resume.** Re-running the same swarm with the **same run id**
  resumes from the frozen snapshot instead of re-reading live memory. The final
  streamed step detects the prior frozen snapshot, serves the agent the frozen
  memory view (so a value some other run has since changed cannot leak in), and
  rebuilds the tool-call record from scratch. A deterministic agent therefore
  re-emits the same upstream text, reasoning, and tool events it produced before
  the crash.

> **Scope — the whole pipeline.** Byte-identical resume covers every step of a
> multi-step sequential `stream()`. The **final, streamed** step replays from its
> frozen snapshot (above). Each **non-final** step is checkpointed when it
> completes, and on resume a completed non-final step is **skipped**: its
> provider is not re-invoked and its tool side effects do not re-fire (a primer
> step that writes to memory does not run again). Its recorded output is
> rehydrated into the next step's prompt, so the downstream stream is
> byte-identical to the original run. A step that crashed *before* it completed
> has no checkpoint and re-executes on resume.
>
> This is same-process, single-`stream()` resume. It is **not** exactly-once
> execution of external side effects across process boundaries — for
> checkpointed, cross-process execution use `dispatchDurable()`
> ([Durable Execution](durable-execution.md)).
>
> Note that the swarm's own lifecycle events — `swarm_step_start` /
> `swarm_step_end` and the `step.started` / `step.completed` audit records —
> are re-emitted for a skipped step on each resume attempt (the agent's
> *upstream* text/tool events are not). Treat these framework step events as
> per-attempt, not exactly-once: a consumer that bills or counts per
> `step.completed` should key on `(run_id, step_index)` to dedupe across resumes.
>
> A skipped step still re-runs its **step guardrails** against the rehydrated
> output (parity with a fresh run), so step guardrails must be **deterministic** —
> a guardrail that depends on wall-clock or external state (e.g. a rate or
> time-window rule) can reject on resume a step the original attempt passed.

> **Sequential only.** This skip-on-resume optimisation applies to sequential
> `stream()`. A **static-hierarchical** streamed run re-executes every reachable
> worker on resume — each runs under its frozen snapshot, so the memory it sees
> is deterministic, but its provider *is* re-invoked and usage / `step.completed`
> telemetry / stream events are re-emitted across the crashed and resumed
> attempts. The byte-identical-memory guarantee holds; the per-step *skip* does
> not. For cross-process idempotent checkpointing of hierarchical work, use
> `dispatchDurable()` ([Durable Execution](durable-execution.md)).

The frozen view is scoped per-invocation on the run's internal active-run frame
rather than rebound globally, so two streams running concurrently in one process
(for example under Octane) each resume against their own frozen snapshot with no
cross-run interference.

```php
$runId = (string) Str::uuid();

// First attempt — abandoned mid-stream by a worker crash.
ArticlePipeline::make()->stream(RunContext::from($task, $runId));

// Resume on a fresh worker: same run id replays from the frozen snapshot.
return ArticlePipeline::make()->stream(RunContext::from($task, $runId));
```

This resume behaviour is governed by the memory replay mode
(`#[MemoryReplay]` on the swarm, or `swarm.memory.replay_mode`, default
`frozen_view`). Setting it to `fresh_execution` opts a swarm out: a re-run then
freezes a new snapshot from live memory rather than replaying the frozen one,
**and disables per-step checkpoint storage** — so non-final steps re-execute on
resume (the pre-multi-step-resume behaviour). `fresh_execution` is therefore the
single kill switch for the whole crash-replay/resume mechanism. See
[Memory](memory.md) for the replay-mode contract.

Crash-replay durability requires the database persistence driver (the snapshot
table). It is **not** full durable-mode streaming: for checkpointed execution
that survives process boundaries by design, use `dispatchDurable()`
([Durable Execution](durable-execution.md)). Crash-replay closes the gap for the
non-durable `stream()` path so an interrupted run is not silently unrecoverable.

> **Octane note.** The snapshot and stream-step-checkpoint stores probe their
> backing table once per worker and cache the result, so a long-lived Octane
> worker booted *before* you run the migrations will treat the tables as absent
> (multi-step resume silently disabled) until it is recycled. Recycle workers
> after migrating.

## Capture And Redaction

Capture flags under `swarm.capture.*` apply to streamed payloads the same way as
other modes. When **output capture** is disabled, output-bearing fields in text,
reasoning, and tool events are redacted consistently in **live** and **replayed**
streams. Tool payloads keep **keys** while values become `[redacted]`.

A custom `CapturePolicy` returning `CaptureDecision::Skip` for outputs emits
`null` output/delta on the stream events (the raw events round-trip the `null`,
so iterating a replay preserves the Skip-vs-empty distinction). The convenience
`StreamedSwarmResponse` rebuilt from those events coerces a Skipped output to
`''` — its `output` is a non-null `string` — and sets
`metadata['output_skipped'] = true` on the response and each affected step so
you can still tell a deliberate omission from a genuinely empty output:

```php
SwarmHistory::replay($runId)->then(function (StreamedSwarmResponse $response) {
    if ($response->metadata['output_skipped'] ?? false) {
        // output was deliberately omitted by a Skip policy, not empty
    }
});
```

Treat streamed prompts, outputs, reasoning, and tool arguments as sensitive in
production unless you have explicitly chosen capture settings for your risk
profile.

## Payload Limits And Overflow

`swarm.limits.max_output_bytes` applies to persisted **stream replay** event
payloads as well as step and history surfaces. When overflow strategy is `fail`
during streaming, earlier deltas may still be emitted before an oversized
terminal payload is detected; the stream then fails, and events after the
failure point are not emitted or persisted for replay.

Full detail: [Persistence And History — Payload Limits](persistence-and-history.md#payload-limits).

## Failures And Lifecycle

If the final streamed agent fails, live execution yields a `swarm_stream_error`
event, marks run history failed, dispatches `SwarmFailed`, and **re-throws** the
underlying exception to the caller.

## Timeouts

`#[Timeout]` and `swarm.timeout` are **best-effort** orchestration deadlines.
Laravel Swarm checks them before and between agent steps; they do **not**
hard-cancel an in-flight provider request or a streamed response mid-call.

## Testing

Fakes intercept `stream()`, `broadcast()`, and `broadcastNow()` as streamed
calls; assertions record after the stream is iterated, returned, or consumed by
the broadcast helper. `broadcastOnQueue()` records in the queued bucket. See
[Testing](testing.md#asserting-basic-interaction).

## Related

- [Persistence And History](persistence-and-history.md) — storage, replay rows, limits, prune
- [Testing](testing.md) — `assertStreamed()`, fakes
- [Streaming Progress example](../examples/streaming-progress/README.md) — routes and SSE patterns
