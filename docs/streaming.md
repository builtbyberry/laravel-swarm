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

## Topology: Sequential Only

Streaming is supported for **sequential** swarms only. Parallel and hierarchical
workflows use other execution modes (`prompt()`, `queue()`, `dispatchDurable()`).

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
broadcasts each event immediately from the worker, and passes the final
`StreamedSwarmResponse` to queued `then()` callbacks.

These are stream-event helpers, not lifecycle broadcasting for every topology.
They are sequential-only for the same reason `stream()` is sequential-only. For
prompt, queued, durable, parallel, or hierarchical operational feeds, listen to
Laravel Swarm lifecycle events and broadcast your own application events.

Broadcast helpers do not retry or buffer transport delivery. If Laravel
broadcasting throws while the helper is consuming the stream, live `broadcast()`
/ `broadcastNow()` rethrow the transport exception and `broadcastOnQueue()` lets
the queued job fail. If delivery fails before terminal completion is yielded,
run history is marked failed and queued `then()` callbacks do not run.

If delivery fails while broadcasting the terminal `swarm_stream_end` event, the
helper or queued job still fails, but swarm execution has already completed:
history remains completed, and persisted replay may include the terminal event.
Queued `then()` callbacks still do not run because the broadcast job failed. Use
Laravel's broadcast and queue infrastructure for transport retries.

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

## Capture And Redaction

Capture flags under `swarm.capture.*` apply to streamed payloads the same way as
other modes. When **output capture** is disabled, output-bearing fields in text,
reasoning, and tool events are redacted consistently in **live** and **replayed**
streams. Tool payloads keep **keys** while values become `[redacted]`.

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
