# Streaming

For provider-native search/fetch activity, privacy and durable attempt behavior, see
[provider-tool events](provider-tool-events.md).

For final and per-step sources, capture rules, and migration requirements, see [citation evidence](citations.md).

For the completed step's bounded native response projection and the distinction
between live access and capture-shaped replay, see [native step results](native-step-results.md).


Use `stream()` when a browser, CLI, or other client needs **live typed progress**
while a swarm runs. The method returns a lazy
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
- A single HTTP request should own the supported workflow while emitting live
  progress, including explicitly enabled process-backed parallel branches.
- You may later need **persisted replay** of the exact emitted timeline (opt-in).

Use `prompt()` when the caller only needs the final aggregate result. Use
`queue()` or `dispatchDurable()` when the work should outlive the request or
needs background or checkpointed execution.

## Topology: Sequential, Parallel, Static-Hierarchical, and Hierarchical

Streaming is supported for **sequential**, **static-hierarchical**, and
**hierarchical** (dynamic, coordinator-generated plan) swarms. Top-level
**parallel** live multiplexing is available as a default-off, process-only
capability; see [Parallel Live Multiplexing](#parallel-live-multiplexing).

Sequential swarms emit step lifecycle events for every agent and stream native
progress from the final agent. Static-hierarchical swarms stream worker nodes.
Static-hierarchical fans parallel groups out in `concurrent` or `sequential`
mode and honors [bounded loops](static-hierarchical-topology.md#bounded-loops).

For **hierarchical** swarms the coordinator step always runs **synchronously**
— `laravel/ai` does not support streaming `HasStructuredOutput` agents — so
only structural causal-log events are emitted for it:
`SwarmNodeOpened` (role `coordinator`, id `__coordinator__`),
`SwarmStepStart` / `SwarmStepEnd` (step index 0), `SwarmNodeChildrenDecided`
(the plan's `startAt` as the sole child), and `SwarmNodeClosed`. Worker nodes
then stream normally from step index 1 with `__coordinator__` as their initial
parent. Budget accounting via `#[MaxAgentSteps(N)]` counts the coordinator as
step 0, so at most `N - 1` worker steps may execute.

### Parallel live multiplexing

Enable top-level parallel streaming only after every serving worker can use
Laravel's `process` concurrency driver:

```dotenv
SWARM_PARALLEL_STREAMING_ENABLED=true
```

Each branch uses the native Laravel AI stream for its agent. Swarm multiplexes
the resulting typed events onto the caller's one stream without rewriting native
event or invocation IDs. Every branch event additionally carries:

- `branch_id`: the stable authored slot (`parallel:0`, `parallel:1`, ...);
- `node_id`: the branch's current run-structure node (the same slot today, but a
  separate identity namespace for future branch chains);
- `attempt_id`: a request-local UUID for this live branch attempt; and
- `branch_sequence`: a zero-based, strictly increasing order within that attempt.

The tuple `(run_id, branch_id, attempt_id, branch_sequence)` is the transport-
neutral Swarm identity. Native IDs remain provider provenance and may repeat in
different branches. Arrival order across branches is scheduler-dependent and is
**not** a global causal order. Group or render by branch and order only by
`branch_sequence`. Completed steps and the final combined output remain in the
authored `agents()` order, regardless of which branch finished first.

The transport uses authenticated, versioned, length-prefixed loopback frames.
Branch-event and terminal-outcome frames are byte-bounded and atomic; a frame is
never split. The parent
acknowledges each event only after the consumer asks for the next event, applying
backpressure all the way to that branch. The absolute swarm deadline continues
while a consumer is paused. Branch count, event-or-terminal frame bytes, and cancellation
grace are bounded by `swarm.streaming.parallel.*`.

If one branch fails, disconnects, violates the protocol, exceeds a bound, or
misses the deadline, the run emits its normal terminal stream error, records the
failure, cancels and reaps sibling processes, and rethrows the original branch
failure when it can be reconstructed. Events already yielded remain partial
observations. A branch is recorded successful only after its own authenticated
native outcome and step guardrail pass; run success waits for every branch. If
the consumer abandons the stream, all active branches
are stopped and reaped. There is no buffered-completion fallback presented as
live streaming.

The process driver and loopback process transport are required. `sync`, `fork`,
and custom concurrency drivers expose only buffered completion and therefore
fail before agent invocation. Use `prompt()` for a truthful buffered aggregate,
or run the streaming endpoint where the process transport is available.

Request-local tenant state does not cross a process boundary by implication.
Put the tenant identifier in `RunContext` and, when the application's tenancy
bootstrap depends on Laravel Context, in Laravel's `Context` before starting the
stream. Swarm hydrates both before resolving the child agent; the application
remains responsible for using that identity in its container/service-provider
tenancy initialization and for preventing cross-tenant data access.

Capture/redaction, citations, native step results, inclusive usage aggregation,
replay, and broadcast use their existing owners. Child processes return bounded
outcomes; the parent alone applies guardrails and writes canonical history,
snapshots, replay, and terminal state, so usage is aggregated once for one live
attempt. A Laravel queue or application retry restarts the whole stream and can
repeat provider cost or effects unless the application supplies idempotency; this path
adds no branch-level retry or deduplication contract. Persisted
replay and broadcast envelopes retain the branch identity fields. Durable
streaming continues to use its existing node/attempt-epoch causal log; live
`attempt_id` does not replace or reinterpret durable epochs.

There is intentionally no transaction spanning a child process socket, replay
store, history store, and broadcast transport. Each yielded event is an observed
fact and may be persisted or delivered before a later branch fails. Terminal
run success is written only after every branch succeeds; completed branch step
evidence is written by the parent as each branch passes its guardrail. The frame
byte limit protects branch-to-parent transport; it
is not a promise that an application's WebSocket/SSE infrastructure accepts that
envelope size, so configure downstream limits separately.

The process-concurrency lane exercises actual simultaneous PHP processes,
interleaved branches that reuse native event/invocation IDs, output before a slow
branch finishes, event-level backpressure, absolute deadlines while a consumer
is paused, partial branch failure with sibling reaping, consumer abandonment,
capture/redaction, citations, usage, native results, and persisted replay. The
protocol unit lane separately rejects oversized, truncated, malformed,
unauthenticated frames and duplicate branch handshakes/connections. These are provider-free deterministic
tests; they do not assert a paid provider's network timing.

Before enabling the writer in a serving environment, run:

```bash
php artisan swarm:health --parallel-streaming
```

The `Parallel live streaming` row must report `ok`. This probe launches the
actual provider-free child bootstrap and authenticated loopback handshake. Once
the feature flag is enabled, bare `swarm:health` includes the same check and
exits nonzero when the process transport is unavailable.

`max_branches` is a per-stream branch-process admission limit, not a raw
file-descriptor or application-wide ceiling. Each branch owns process pipes and
a loopback socket. Budget aggregate capacity as concurrent live streams times
`max_branches`, allow several descriptors per branch, and enforce the resulting
application-wide limit through the serving HTTP/queue concurrency controls or an
application rate limiter.

Swarm tool-call serialization omits opaque provider continuation fields, including
`reasoning_encrypted_content` and `thought_signature`. Native conversation replay
owns those values; persisted Swarm event playback is not a provider continuation
request. This does not remove values from the original native DTO in memory.

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

For a top-level parallel swarm, group by branch and attempt rather than
concatenating scheduler-dependent arrival order:

```php
$branches = [];

foreach (ResearchSwarm::make()->stream('Compare the options') as $event) {
    if ($event->type() !== 'swarm_text_delta' || $event->branchId === null) {
        continue;
    }

    $attempt = $event->attemptId;
    $branches[$event->branchId][$attempt][$event->branchSequence] = $event->delta;
}

foreach ($branches as &$attempts) {
    foreach ($attempts as &$events) {
        ksort($events); // Order only inside this branch attempt.
    }
}
```

`branchId` is the authored slot, `attemptId` scopes one live attempt, and
`branchSequence` is meaningful only within that pair. Use the terminal combined
response when authored branch order, rather than live arrival, is required.

Return from a route for Laravel AI-style SSE (`data:` lines by default):

```php
return ArticlePipeline::make()->stream([
    'topic' => 'Laravel queues',
]);
```

The response sends `Cache-Control: no-cache, no-transform` and
`X-Accel-Buffering: no` to discourage intermediary buffering. Those headers are
advisory: verify end-to-end flushing through the application server, FastCGI or
reverse proxy, and any CDN before claiming client-visible live delivery.

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
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Str;

$runId = (string) Str::uuid();
$channel = new PrivateChannel("tenants.{$tenantId}.swarm.{$runId}");
$context = RunContext::from([
    'input' => 'Draft an article about Laravel queues.',
    'data' => ['topic' => 'Laravel queues'],
], runId: $runId);

// Choose exactly one delivery verb for this run.
ArticlePipeline::make()->broadcast($context, $channel);
// ArticlePipeline::make()->broadcastNow($context, $channel);
// ArticlePipeline::make()->broadcastOnQueue($context, $channel)
//     ->onQueue('ai-streams');
```

The application must authorize that private channel by both tenant membership
and permission to inspect that exact run ID. Define the corresponding
application policy in `routes/channels.php`. A constant cross-tenant channel is
not a safe default for streamed prompts, tool events, citations, or output.

`broadcast()` consumes the stream immediately and broadcasts each
`SwarmStreamEvent` through Laravel broadcasting. `broadcastNow()` uses immediate
delivery. `broadcastOnQueue()` dispatches a worker that streams the swarm once,
broadcasts each event immediately from the worker, and records completion
through normal swarm history and lifecycle events.

These are stream-event helpers, not lifecycle broadcasting for every topology.
They use the same supported topology paths as `stream()` in
[SwarmRunner](../src/Runners/SwarmRunner.php), including enabled process-backed
top-level parallel multiplexing.
For workflow operational feeds across all modes, listen to Laravel Swarm
lifecycle events and broadcast your own application events.

Broadcast helpers do not retry or buffer transport delivery. If Laravel
broadcasting throws while the helper is consuming the stream, live `broadcast()`
/ `broadcastNow()` rethrow the transport exception and `broadcastOnQueue()` lets
the queued job fail. If delivery fails before terminal completion is yielded,
run history is marked failed.

If delivery fails while broadcasting the terminal `swarm_stream_end` event, the
helper or queued job still fails, but swarm execution has already completed:
history remains completed, and persisted replay may include the terminal event.
Use Laravel's broadcast and queue infrastructure for transport retries.

### Suppressing event types from broadcast

High-frequency events — `swarm_text_delta`, `swarm_reasoning_delta` — can flood a
broadcast channel while adding little to a UI that only needs step boundaries and
the final output. Declare `laravel/ai`'s `#[WithoutBroadcasting]` attribute on the
swarm to keep those event types out of the broadcast, naming the
`SwarmStreamEvent` classes to skip:

```php
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Laravel\Ai\Attributes\WithoutBroadcasting;

#[WithoutBroadcasting(SwarmTextDelta::class, SwarmReasoningDelta::class)]
class ArticlePipeline implements Swarm
{
    use Runnable;

    // ...
}
```

The attribute is honored by all three broadcast helpers — `broadcast()`,
`broadcastNow()`, and `broadcastOnQueue()`. It is a **static, per-class opt-out**:
it names event *types* to skip, not a dynamic size threshold.

Suppression affects **broadcast delivery only**. The suppressed events still flow
through the returned stream and through crash-replay persistence exactly as
before — so the final output is fully assembled, `stream()` consumers are
unaffected, and a persisted replay still contains every event. Suppressed events
also emit no `broadcast.event` telemetry, since nothing was broadcast for them
(the broadcast sequence index still advances past them, so surviving events keep
their true stream position). To drop an event type from the stream *and* replay,
not just the broadcast, use the context-growth governor instead (see
`swarm.context_growth`).

## Stream Event Types

Swarm streams emit typed events, including:

| Type | Role |
| --- | --- |
| `swarm_stream_start` | Run metadata and captured input. |
| `swarm_step_start` | Step lifecycle start with captured step input. |
| `swarm_text_delta` / `swarm_text_end` | Final-agent text chunks and close marker. |
| `swarm_reasoning_delta` / `swarm_reasoning_end` | Final-agent reasoning stream events. |
| `swarm_tool_call` / `swarm_tool_result` | Final-agent tool invocation and results. |
| `swarm_provider_tool_event` | Captured native [provider-tool activity](provider-tool-events.md) with identity and explicit data availability. |
| `swarm_provider_tool_attempt_invalidated` | Durable control marker invalidating older provider activity for one node. |
| `swarm_citation` | Native source occurrence with captured [citation evidence and availability](citations.md). |
| `swarm_step_end` | Step completion with captured or limited output, usage, [step citation evidence](citations.md), and a capture-shaped [native step result](native-step-results.md). |
| `swarm_stream_end` | Terminal completion with final output, aggregate usage, and [final-output citation evidence](citations.md). |
| `swarm_stream_error` | Terminal failure payload for live failure and persisted replay. |
| `swarm_node_opened` | A run-structure node opening; self-identifying (`node_id == id`), with its `parent_node_id` and `role`. Recorded before any event tagged with that node id. |
| `swarm_node_children_decided` | A deciding node declaring its children in chosen order (`child_node_ids`); `node_id` is the deciding node's id. |
| `swarm_node_closed` | A run-structure node closing, with its `result`; the terminal bracket for every event tagged with that node id. |

Every substantive event also carries a nullable `node_id` tagging the
run-structure node it belongs to (absent/null = a top-level event with no
enclosing node). The three `swarm_node_*` events make a run's structure
first-class on the causal log — "structure as payload".

Parallel branch events add optional wire keys. They are absent on older rows and
non-parallel live paths; when present, `branch_id` and `attempt_id` are strings
and `branch_sequence` is an integer. The corresponding PHP object properties are
nullable for compatibility. Consumers must not use array arrival order as a
cross-branch causal order.

**Provenance:** For upstream final-agent streamed provider events, Laravel Swarm
preserves upstream event **IDs** and **timestamps** in typed replay. **Invocation
IDs** are passed through when the upstream provider includes them.

These native identities describe provider events. Swarm `run_id` and `node_id`
describe orchestration; they are not substitutes for native invocation or tool
identities. Missing invocation IDs stay absent. Swarm does not invent generation
IDs or join native tool-invocation IDs to streamed provider call IDs by comparing
arguments. Native event subscribers remain application-owned; Swarm does not
install a second global native-event collector or add native event usage a second
time to step usage.

### Tool calls (including MCP tools)

Swarm's tool model is **pure passthrough**. A `laravel/ai` `ToolCall` /
`ToolResult` is carried as an opaque object through tool-call capture, the step
memory snapshot, and the streamed `swarm_tool_call` / `swarm_tool_result`
events — Swarm does not interpret the tool's arguments or result. That means the
**MCP client/server tools** added in `laravel/ai` 0.8 are supported with no
MCP-specific configuration: an MCP-backed tool's call and result flow through the
stream and the durable snapshot exactly like any other tool, including a
**structured** (non-scalar) MCP result, which is preserved intact.

Streamed tool results preserve native `denied` and `failed` flags under full,
redacted and skipped capture, including database replay. Redaction removes payload
values, not these outcome flags. The native streamed `successful`
classification remains unchanged; error text still follows the capture policy
(unchanged under Full, redacted under Redact, absent under Skip). Swarm does not
classify an error-looking result string as an exception. In official Laravel AI, tool validation errors and caught nested
`AgentTool` failures can be ordinary text results, while a max-step result can be
failed without invoking the tool. Unsupported native approvals still fail at the
[approval boundary](native-outcome-boundary.md).

Laravel AI 1.0 can emit several cumulative results for the same tool call.
`swarm_tool_result.preliminary` marks these progress observations. Replace the
displayed progress for that call rather than concatenating cumulative payloads.
The matching pending call stays pending until a non-preliminary result arrives;
only that final result enters the memory snapshot, once. Partial payloads are not
stored in a separate accumulation buffer. Ordinary abandonment retains the
existing unpaired-call cleanup; abrupt process termination does not guarantee
cleanup or a final result. Native nested-agent partials remain observations on
the parent tool call, not new Swarm child runs.

Top-level `denied` preserves the native event's classification independently of
`tool_result.denied`, `failed`, `successful` and captured error text. Both new
top-level fields serialize as booleans. When reading older/malformed rows,
`preliminary` defaults to false and `denied` falls back only to a boolean nested
denied value, otherwise false. Truthy strings and numbers do not become true.
That legacy fallback cannot reconstruct an original event-level classification.

Preliminary and final function-tool results use the same capture policy: Full
keeps values, Redact keeps the established structure with redacted values, and
Skip retains event identity/outcome metadata with empty arguments and null
result/error. Provider-tool whole-payload withholding and size budgets do not
apply to function-tool events. Event storage and replay still retain the emitted
sequence; bounded pending-call state is not a global replay-volume limit.

Durable function-tool calls/results use a separate scoped storage identity so
repeated native IDs across nodes and attempts cannot redirect a void edge. Public
native IDs stay unchanged. The existing
`swarm_provider_tool_attempt_invalidated` node/epoch marker also excludes stale
function-tool events, including late events without a prior anchor. Hot reads,
cold graduation and snapshots preserve the stored identity. Legacy raw-ID void
targets remain readable; previously ambiguous historical collisions cannot be
reconstructed from missing provenance.

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

The v0.27.0 reader preserves preliminary and event-level denied flags across
cache, database and tiered replay and the broadcast helpers. An older reader may
drop them and treat partial progress as final evidence. Retain a compatible
reader after these events have been written; see
[v0.27.0 reader rollback](../UPGRADING.md#preliminary-tool-results-and-reader-rollback).

The v0.26.0 reader accepts historical result rows without the additive `denied`
and `failed` booleans, defaulting each to false. An older reader can parse new
rows while silently dropping those flags: that is wire compatibility, **not** a
safe semantic downgrade. Once corrected evidence has been persisted, retain a
reader that preserves it. See [the downgrade restriction](../UPGRADING.md#streamed-tool-result-evidence-and-downgrades).

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

Persisted replay re-yields stored events. Re-executing an abandoned live stream
with the same run id is a different operation: it can reuse selected memory
snapshots and completed non-final sequential checkpoints, but it may invoke
providers and tools again.

- **Tool-call capture:** the streaming accumulator records observed tool pairs
  in the memory snapshot and attempts to flush incomplete pairs with a null
  result during generator teardown. A hard process death or failed persistence
  is not a guaranteed flush or a receipt for an external effect.
- **FrozenView:** when a prior snapshot is available, selected memory reads use
  that frozen view. The final streamed agent is still invoked again; identical
  memory does not guarantee identical provider output, event IDs or external
  effects. FreshExecution opts out of frozen replay.
- **Completed sequential steps:** a non-final step can be skipped only when its
  checkpoint was successfully written and remains readable. Its output/usage
  are rehydrated into the next step. Checkpoint writes are best-effort; missing
  or unreadable checkpoints and unfinished work permit re-execution. The final
  step is never checkpoint-skipped.

[SequentialRunner](../src/Runners/SequentialRunner.php) owns these checkpoint
conditions; [StreamingCrashReplayTest](../tests/Feature/StreamingCrashReplayTest.php)
and the [R19 execution evidence](ai-0112-preservation-evidence.md#retained-swarm-responsibilities)
characterize them. They do not establish exactly-once arbitrary tool effects.
Durable execution adds cross-job state checkpoints and fences, but unfinished
provider/tool work may still repeat there too.

A skipped step re-emits Swarm step lifecycle/audit events and re-runs its step
guardrails against the rehydrated output; its native invocation is skipped.
Treat those framework events as attempt observations and keep guardrails
deterministic if resumed output must pass the same policy.

The non-final skip optimization is sequential-only. Static-hierarchical workers
execute again under their applicable frozen snapshots; their provider calls,
usage and events may repeat. See
[StaticHierarchicalStreamRunner](../src/Runners/StaticHierarchicalStreamRunner.php)
and [its crash-replay tests](../tests/Feature/StaticHierarchicalStreamCrashReplayTest.php).
For cross-job orchestration recovery use [Durable Execution](durable-execution.md),
with the [same external-effect limits](ai-0112-preservation-evidence.md#supported-combinations-and-operational-limits).

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

Native stream exhaustion, native completion callbacks and synchronous tool
observers run before Swarm marks the affected step complete. If they throw, Swarm
fails the run without publishing a successful terminal event. Native provider
failover before any output remains available; after output or tool effects, do
not assume the attempt can be replayed safely. Inspect effects before an
operator-controlled restart. Discarding an unfinished consumer marks that run
failed. On the process-backed parallel path it also terminates and reaps local
branch processes, but process termination does not prove cancellation of a
provider request or remote side effect that was already accepted.

The public Swarm `then()` callback is a different stage: it observes an already
completed Swarm response. If that observer throws, completed history and replay
remain successful.

## Timeouts

`#[Timeout]` and `swarm.timeout` are **best-effort** orchestration deadlines.
Ordinary execution paths check them before and between agent steps and do not
hard-cancel an in-flight provider request. Process-backed parallel streaming
also enforces the absolute deadline continuously while polling frames and waiting
for acknowledgements; on expiry it terminates and reaps local branch processes.
That local cleanup does not prove cancellation of a provider request or remote
side effect already accepted outside the process.

## Testing

Fakes intercept `stream()`, `broadcast()`, and `broadcastNow()` as streamed
calls; assertions record after the stream is iterated, returned, or consumed by
the broadcast helper. `broadcastOnQueue()` records in the queued bucket. See
[Testing](testing.md#asserting-basic-interaction).

## Related

- [Persistence And History](persistence-and-history.md) — storage, replay rows, limits, prune
- [Testing](testing.md) — `assertStreamed()`, fakes
- [Streaming Progress example](../examples/streaming-progress/README.md) — routes and SSE patterns
