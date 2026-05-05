# Streaming Broadcasts

Shows how to broadcast typed swarm stream events through Laravel broadcasting.

Use this pattern when a sequential swarm should update a browser over Reverb,
Pusher, Soketi, or another Laravel broadcast driver while the workflow runs.

This example teaches:

- `broadcast()` consumes the stream and broadcasts events now;
- `broadcastNow()` uses immediate broadcast delivery;
- `broadcastOnQueue()` dispatches a worker that streams and broadcasts events;
- broadcast helpers are stream-event helpers, not lifecycle broadcasts for every
  topology.

## Prerequisites

- Laravel AI is configured in your application.
- The swarm is sequential.
- Laravel broadcasting is configured.
- A queue worker is running when using `broadcastOnQueue()`.
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true` is set when queueing stream broadcasts.

## Stream And Broadcast Now

```php
use App\Ai\Swarms\ContentPipeline;
use Illuminate\Broadcasting\PrivateChannel;

ContentPipeline::make()->broadcast(
    [
        'topic' => 'Laravel broadcasting',
        'audience' => 'application developers',
    ],
    new PrivateChannel('swarm.content-pipeline'),
);
```

Use `broadcastNow()` when your application wants immediate delivery through the
current process:

```php
ContentPipeline::make()->broadcastNow(
    ['topic' => 'Laravel broadcasting'],
    new PrivateChannel('swarm.content-pipeline'),
);
```

## Queue The Broadcast Stream

Use `broadcastOnQueue()` when a worker should own the stream and broadcast
delivery:

```php
$response = ContentPipeline::make()
    ->broadcastOnQueue(
        ['topic' => 'Laravel broadcasting'],
        new PrivateChannel('swarm.content-pipeline'),
    )
    ->onConnection('redis')
    ->onQueue('ai-streams');

$response->runId;
```

`broadcastOnQueue()` records in the queued fake bucket, so tests should use
`assertQueued()`:

```php
ContentPipeline::fake();

ContentPipeline::make()->broadcastOnQueue(
    ['topic' => 'Laravel broadcasting'],
    new PrivateChannel('swarm.content-pipeline'),
);

ContentPipeline::assertQueued(['topic' => 'Laravel broadcasting']);
```

## Client Shape

Laravel Swarm broadcasts typed `SwarmStreamEvent` payloads. Your browser should
subscribe to your private channel and update UI from the event type and payload.
The exact listener names depend on your Laravel broadcasting client and how it
names PHP broadcast events:

```js
Echo.private('swarm.content-pipeline')
    .listen('BuiltByBerry\\\\LaravelSwarm\\\\Streaming\\\\Events\\\\SwarmTextDelta', (event) => {
        if (event.type === 'swarm_text_delta') {
            // event.delta
        }
    });
```

Use lifecycle events and application-owned broadcasts for queued, durable,
parallel, or hierarchical operations feeds.

## What Happened

The helper consumed the same lazy stream returned by `stream()`. Every emitted
`SwarmStreamEvent` was sent through Laravel broadcasting. If broadcast delivery
fails before terminal completion, the run history is marked failed. If delivery
fails while sending the terminal `swarm_stream_end` event, swarm execution has
already completed but the helper or queued job still fails.

## Related Documentation

- [Streaming](../../docs/streaming.md)
- [Queued Workflow Events](../queued-workflow-events/README.md)
- [Operations Dashboard](../operations-dashboard/README.md)
