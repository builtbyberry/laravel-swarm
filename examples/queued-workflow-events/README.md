# Queued Workflow Events

Shows the normal background execution path for a swarm.

Use this pattern when one queued job can comfortably own the full swarm run.

This example teaches:

- `queue()` dispatches the swarm to Laravel's queue;
- controllers can return `202` with a `run_id` immediately;
- a short-lived pending record bridges the time before history is written;
- completion and failure are handled with lifecycle events;
- polling persisted status is the reliable fallback even if you also broadcast
  real-time updates;
- callbacks are compatibility-only, not the recommended queued pattern.

## Queue A Swarm

```php
use App\Ai\Swarms\ContentPipeline;

ContentPipeline::make()->queue([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
]);
```

Run a worker for the queue connection used by the job:

```bash
php artisan queue:work
```

Make sure the worker timeout and the queue connection's `retry_after` are sized
for real provider latency. A queued swarm is one Laravel job, so it is still
bounded by normal queue visibility and worker timeout rules.

## Return A Run Id

For browser workflows, return a `202` response with the run id and let the UI
poll a run inspector endpoint while events arrive.

```php
use App\Ai\Swarms\ContentPipeline;
use App\Support\PendingSwarmRunStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function store(Request $request, PendingSwarmRunStore $pendingRuns): JsonResponse
{
    $topic = $request->validate([
        'topic' => ['required', 'string', 'min:3', 'max:200'],
    ])['topic'];

    $response = ContentPipeline::make()->queue([
        'topic' => $topic,
    ]);

    $pendingRuns->put($response->runId, $topic, ContentPipeline::class, 'sequential');

    return response()->json([
        'run_id' => $response->runId,
        'status' => 'queued',
        'run_url' => route('swarm.runs.show', $response->runId),
    ], 202);
}
```

See [Run Inspector](../run-inspector/README.md) for the pending store and status
endpoint.

## Listen For Completion

```php
use App\Ai\Swarms\ContentPipeline;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    if ($event->swarmClass !== ContentPipeline::class) {
        return;
    }

    logger()->info('Content pipeline completed', [
        'run_id' => $event->runId,
        'output' => $event->output,
    ]);
});

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    if ($event->swarmClass !== ContentPipeline::class) {
        return;
    }

    report($event->exception);
});
```

Prefer events for real queued execution. Callback chaining is compatibility
only because queued closures can capture unexpected application state.

Events are not a replacement for persisted status. Use events for notifications
and live updates, and keep a run inspector endpoint as the fallback source of
truth for browser refreshes, reconnects, and missed broadcasts.
