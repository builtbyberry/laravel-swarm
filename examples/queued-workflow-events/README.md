# Queued Workflow Events

Shows the normal background execution path for a swarm.

Use this pattern when one queued job can comfortably own the full swarm run.

This example teaches:

- `queue()` dispatches the swarm to Laravel's queue;
- completion and failure are handled with lifecycle events;
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
