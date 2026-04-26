# Laravel Swarm

Laravel Swarm brings multi-agent orchestration to [Laravel](https://laravel.com) on top of the official [Laravel AI](https://github.com/laravel/ai) package. Define a swarm once, return the Laravel AI agents that participate in it, and run them through sequential, parallel, or hierarchical topologies using explicit Laravel-style verbs.

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **Repository:** https://github.com/builtbyberry/laravel-swarm

## Requirements

- PHP **8.5+**
- Laravel **13+**
- `laravel/ai` **^0.6**

## When To Use Laravel Swarm

Laravel AI is already a strong fit when one agent can handle the full job, or when you want to compose multi-agent workflow patterns directly yourself. If you like working close to the primitives, Laravel AI gives you the building blocks to do that.

Laravel Swarm is for the next step-up: cases where that workflow should become a reusable, observable, application-level unit. It is a good fit when the real job looks like plan, research, write, review, or classify, route, respond, or run multiple specialists and keep the history of what happened.

### Laravel AI vs Laravel Swarm

Laravel's article, [Building Multi-Agent Workflows with the Laravel AI SDK](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk), shows that Laravel AI already supports patterns like prompt chaining, routing, parallelization, orchestrator-workers, and evaluator-optimizer.

That is the right mental model for Swarm too. Laravel AI gives you the ingredients. Laravel Swarm gives you a reusable workflow abstraction built from those ingredients.

Both are valid choices. Laravel AI is great when you want to compose the workflow yourself from lower-level primitives. Laravel Swarm is great when you want that workflow to live as a reusable, first-class object in your app with a consistent `run()`, `queue()`, `stream()`, and `dispatchDurable()` API, plus persistence, lifecycle events, and test helpers around it.

If you prefer assembling those workflow patterns manually, the Laravel AI article is a good place to start. If you want to define the workflow once and reuse it as an application primitive, Swarm is the better fit.

### Real-World Examples

- `PlannerAgent -> ResearchAgent -> WriterAgent -> EditorAgent` for a content workflow where each handoff has a clear responsibility and you want the run history for later review.
- `TriageAgent -> PolicyLookupAgent -> ResponseDraftAgent -> ReviewAgent` for support operations where repeatability and step-by-step visibility matter as much as the final answer.
- `IntakeAgent -> ExtractionAgent -> RiskReviewAgent -> SummaryAgent` for compliance review where durable artifacts and auditability are part of the actual business requirement.
- `CompanyResearchAgent -> ScoringAgent -> OutreachDraftAgent` for lead enrichment where each agent does a narrow job and the full workflow can be reused across campaigns.
- `RequestIntakeAgent -> PlannerAgent -> SpecialistAgent(s) -> FinalResponseAgent` for internal operations where one request may branch into different specialists but still needs one consistent workflow definition.

See the [examples](examples/README.md) folder for copy-paste Laravel workflow
examples covering sequential, parallel, hierarchical, queued, streamed,
durable, testing, and privacy-sensitive swarms.

### Laravel Swarm Is A Good Fit When...

- the same multi-step AI workflow runs repeatedly in production
- one agent should plan and other agents should execute
- you want workflow history, artifacts, or step-by-step visibility
- you want one workflow definition that can run synchronously, on a queue, or as a stream
- you do not want to rebuild orchestration wiring in every feature

### Laravel Swarm May Be Unnecessary If...

- one agent can do the whole job well
- you are comfortable composing the workflow directly with Laravel AI primitives, as shown in the Laravel article
- you do not need persistence, lifecycle events, or reusable workflow classes
- the workflow is too small or too one-off to justify a swarm abstraction

If your use case feels more like a reusable workflow than a single prompt, the rest of Laravel Swarm gives you three orchestration styles: sequential, parallel, and hierarchical.

## Installation

```bash
composer require builtbyberry/laravel-swarm
```

Laravel Swarm loads its package migrations automatically through the service provider, so the swarm tables are created during your normal migration flow:

```bash
php artisan migrate
```

The package migrations are always loaded and create the default swarm tables
even when the current persistence driver is `cache`. If you want custom table
names, publish the migrations and update them to match `swarm.tables.*`.
Published migrations use Laravel's package migration publishing flow, so their
filenames receive fresh application migration timestamps.

Publish the package configuration if you want to customize defaults:

```bash
php artisan vendor:publish --tag=swarm-config
```

If you published `config/swarm.php` from an earlier version, review the
per-store driver entries before relying on `SWARM_PERSISTENCE_DRIVER`.
The current package config expects these overrides to be unset unless you
intend to override the global driver:

```php
'context' => [
    'driver' => env('SWARM_CONTEXT_DRIVER'),
],
'artifacts' => [
    'driver' => env('SWARM_ARTIFACTS_DRIVER'),
],
'history' => [
    'driver' => env('SWARM_HISTORY_DRIVER'),
],
```

If an older published config hardcodes one of those values to `'cache'`, it
will override the global persistence driver.

If you want to customize the package migrations in your app, publish them explicitly:

```bash
php artisan vendor:publish --tag=swarm-migrations
```

The auto-loaded package migrations always create the default package table names. If you change `swarm.tables.*`, publish the migrations and update the table names there as well.

If you want to customize the generated stub in your app, publish it too:

```bash
php artisan vendor:publish --tag=swarm-stubs
```

After publishing, `make:swarm` will prefer `stubs/swarm.stub` from your application before falling back to the package stub.

## Creating A Swarm

Generate a swarm class in `App\Ai\Swarms`:

```bash
php artisan make:swarm ContentPipeline
```

Swarms implement `BuiltByBerry\LaravelSwarm\Contracts\Swarm`, use the `Runnable` trait, and return the agents that participate in the workflow from `agents()`:

```php
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ContentPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            // new YourResearchAgent,
            // new YourWriterAgent,
        ];
    }
}
```

## How Agents Connect To Swarms

Laravel AI agents stay responsible for their own instructions, tools, and model behavior. A swarm simply decides which agents participate and how they are orchestrated.

```php
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\WriterAgent;
use App\Ai\Agents\EditorAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ArticlePipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ResearchAgent,
            new WriterAgent,
            new EditorAgent,
        ];
    }
}
```

In a sequential swarm, the first agent handles the original task, the next agent receives the previous agent's output, and so on.

## Running A Swarm

Use `run()` when you want synchronous execution and a `SwarmResponse` back immediately:

```php
$response = ArticlePipeline::make()->run('Draft a blog outline about Laravel queues.');

$response->output;
$response->steps;
$response->artifacts;
$response->metadata;
```

Structured task input is also first-class:

```php
$response = ArticlePipeline::make()->run([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]);
```

Use `RunContext` when you need explicit control over the run ID or metadata:

```php
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ArticlePipeline::make()->run(RunContext::from([
    'input' => 'Draft a blog outline about Laravel queues.',
    'data' => ['topic' => 'Laravel queues'],
    'metadata' => ['campaign' => 'content-calendar'],
], 'article-outline-run'));
```

Most applications will not need `RunContext` directly. For a deeper look at strings, arrays, and `RunContext`, see [Structured Input](docs/structured-input.md).

`SwarmResponse` can still be cast to a string:

```php
(string) $response;
```

## Queueing A Swarm

Use `queue()` when the swarm should run in the background:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    if ($event->swarmClass !== ArticlePipeline::class) {
        return;
    }

    // Handle the completed queued run.
});

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    if ($event->swarmClass !== ArticlePipeline::class) {
        return;
    }

    report($event->exception);
});

ArticlePipeline::make()->queue('Draft a blog outline about Laravel queues.');
```

`queue()` always queues. `run()` always runs synchronously.

Queued swarms remain the lightweight queue mode. One queued job represents one swarm run, and database-backed queued runs use lease-based ownership so duplicate deliveries do not replay work while an active worker still owns the run.

This is the right fit for normal background swarm work in Laravel. It is not durable multi-job orchestration, and it is not intended to replace a workflow engine for very long-lived pipelines.

Queued `then()` and `catch()` callbacks remain available for compatibility, but
they are deprecated for real queued execution. Those closures are serialized
into the queue payload, which can capture more application state than intended,
fail serialization unexpectedly, or leak sensitive data into queue storage.

```php
ArticlePipeline::make()
    ->queue('Draft a blog outline about Laravel queues.')
    ->then(function (\BuiltByBerry\LaravelSwarm\Responses\SwarmResponse $response) {
        //
    })
    ->catch(function (\Throwable $exception) {
        //
    });
```

Like Laravel AI, the queued swarm response proxies the underlying pending dispatch, so you may continue chaining queue configuration methods such as `onConnection()` and `onQueue()` before the job is actually dispatched.

Queued swarms are Laravel-native workflow definitions: the worker re-resolves the swarm from the container before execution. For queued execution, treat the swarm as a stateless definition apart from container-injected dependencies. Runtime instance state is not preserved across the queue boundary. Pass dynamic execution data in the task payload instead.

Because queued swarms are validated for container resolution, topology, timeouts,
step limits, and parallel worker safety before dispatch, constructors and DI
setup should stay cheap and side-effect free in normal Laravel style.

Database-backed queued runs are also prune-safe while active. A `running` run keeps its history, context, and artifact rows until it reaches a terminal state, even when their retention window has elapsed.

The practical boundary to keep in mind is the same one Laravel developers already know from long-running queue jobs: a single queued run is still bounded by worker timeouts, queue visibility windows, deploy interrupts, and other normal job-lifecycle limits. Keep the worker timeout and queue connection `retry_after` comfortably above the provider calls and total swarm duration you expect. If one provider call or one swarm run regularly stretches beyond that envelope, that is a sign the lightweight queue mode is no longer the right operational model for that workflow.

Pass structured task data the same way you would with `run()`:

```php
ArticlePipeline::make()
    ->queue([
        'topic' => 'Laravel queues',
        'audience' => 'intermediate developers',
        'goal' => 'blog outline',
    ]);
```

Queued structured payloads are serialized as plain queue-safe data and rebuilt into a `RunContext` on the worker. Use strings, integers, floats, booleans, null, and arrays containing only those values. Do not rely on objects, enums, closures, resource handles, or other runtime values crossing the queue boundary.

What not to do:

```php
// Do not put per-execution state on a queued swarm instance.
(new ArticlePipeline($draftId))->queue('Review the draft');
```

If you call `queue()` on a swarm instance that relies on runtime constructor state, or on a swarm class the container cannot resolve for queued execution, Laravel Swarm throws immediately with guidance before dispatching the job.

For more detail on structured queue payloads, see [Structured Input](docs/structured-input.md).

For database-backed retention, pruning, and active-run persistence behavior, see [Persistence And History](docs/persistence-and-history.md) and [Maintenance](docs/maintenance.md).

## Durable Execution

Use `dispatchDurable()` when the workflow is too important or too long-lived
for a single queue job:

```php
ArticlePipeline::make()->dispatchDurable([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]);
```

Durable runs are checkpointed and advance one step per job. Sequential durable
runs execute one agent per job. Hierarchical durable runs execute the
coordinator first, persist the validated route plan, and then advance one routed
worker node per job.

This is intentionally a separate execution mode:

- `queue()` is lightweight background execution
- `dispatchDurable()` is checkpointed durable execution

Durable execution supports sequential and hierarchical swarms in this release
and requires database-backed swarm persistence. Hierarchical durable parallel
groups execute branch workers sequentially in declaration order for v1; durable
fan-out/fan-in is intentionally deferred.

Durable responses do not support `then()` or `catch()`. Durable runs are
event-driven. Listen to `SwarmCompleted` and `SwarmFailed` instead of
serializing callbacks into the queue payload.

`dispatchDurable()` still uses Laravel's pending-dispatch lifecycle. In Tinker
or manual testing, holding onto the response can delay queue insertion until
the response object is released:

```php
$response = ArticlePipeline::make()->dispatchDurable([
    'topic' => 'Atomic lease test',
]);

$runId = $response->runId;

unset($response);
gc_collect_cycles();
```

For the durable runtime model, operator commands, and recovery scheduling, see
[Durable Execution](docs/durable-execution.md).

## Streaming A Swarm

Use `stream()` when you want step and token events for server-sent events or other real-time updates:

```php
try {
    foreach (ArticlePipeline::make()->stream([
        'topic' => 'Laravel queues',
        'audience' => 'intermediate developers',
        'goal' => 'blog outline',
    ]) as $event) {
        // ['event' => 'step', 'agent' => 'WriterAgent', 'status' => 'running']
        // or ['event' => 'token', 'token' => 'Drafting the outline...']
    }
} catch (\Throwable $exception) {
    //
}
```

Swarm streams emit `step` events for agent lifecycle progress and `token` events for streamed final-agent output.

Streaming is currently supported for sequential swarms only.

If the final streamed agent fails, the generator re-throws the underlying exception from that agent. Wrap the stream loop in `try/catch`; `SwarmFailed` is dispatched and run history is marked failed before the exception is re-thrown.

`#[Timeout]` and `swarm.timeout` are best-effort orchestration deadlines. Laravel Swarm checks them before and between agent steps, but they do not hard-cancel an in-flight provider request or streamed response mid-call.

## Topologies

### Sequential

Agents run in order. Each agent receives the previous agent's output.

### Parallel

Agents run at the same time and each receives the original task.
Parallel agents must be stateless, container-resolvable Laravel AI agents
because Laravel's concurrency workers serialize callbacks and resolve the agent
class inside the worker process.

### Hierarchical

In a hierarchical swarm, the first agent acts as the coordinator and returns a
structured route plan. Laravel Swarm validates that plan as a DAG and then
executes the selected worker nodes directly.

```php
use App\Ai\Agents\DraftAgent;
use App\Ai\Agents\PolicyAgent;
use App\Ai\Agents\SupportCoordinator;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Hierarchical)]
class SupportRoutingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new SupportCoordinator,
            new PolicyAgent,
            new DraftAgent,
        ];
    }
}
```

The coordinator must implement Laravel AI structured output and return a plan
with `start_at` and `nodes`. Worker nodes, parallel nodes, and finish nodes are
supported. `run()` executes parallel groups concurrently. `queue()` and
`dispatchDurable()` execute parallel groups sequentially in declaration order in
v1. Plans are still validated with parallel-safe dependency rules, so branch
nodes cannot depend on sibling branch outputs. Parallel groups must define
`next` and join into a subsequent node before the workflow can finish.
`#[MaxAgentSteps]` counts the coordinator plus each reachable worker node and
fails before worker execution when a plan exceeds the limit.

For the full routing contract, plan shape, and validation rules, see
[Hierarchical Routing](docs/hierarchical-routing.md).

## Testing

Use `fake()` to intercept swarm execution in tests:

```php
ArticlePipeline::fake(['first', 'second']);

expect((string) ArticlePipeline::make()->run('draft intro'))->toBe('first');

ArticlePipeline::assertRan('draft intro');
ArticlePipeline::assertRan(['draft_id' => 42]);
ArticlePipeline::assertNeverQueued();
```

Fakes can also use a callback:

```php
ArticlePipeline::fake(fn ($task) => 'handled');
```

Faked streams stay lightweight and deterministic:

```php
ArticlePipeline::fake(['streamed-output']);

$events = iterator_to_array(ArticlePipeline::make()->stream('draft intro'));

ArticlePipeline::assertStreamed('draft intro');
```

Array assertions use subset matching, so you only need to assert on the keys you care about.

For test assertions against persisted history and lifecycle events, see [Testing](docs/testing.md).

## Configuration

Laravel Swarm stores its defaults in `config/swarm.php`, including topology, timeout, max agent steps, persistence drivers, and queue settings.

`swarm.persistence.driver` sets the default persistence driver for all swarm stores. Individual stores can override it via `swarm.context.driver`, `swarm.artifacts.driver`, and `swarm.history.driver`. The `cache` driver is lightweight and respects TTL settings. The `database` driver stores records durably in package-managed tables.

`swarm.tables.*` changes the table names used by the database repositories at runtime. If you change them, publish and update the migrations too.

Timeout settings are best-effort orchestration deadlines, not hard cancellation of in-flight provider calls.

## Responses, Events, And Persistence

Every swarm run returns a `SwarmResponse` with `output`, `steps`, `usage`, `artifacts`, and `metadata`. The response casts to a string for simple use cases.

Laravel Swarm dispatches lifecycle events at each stage of execution — swarm started, step started, step completed, swarm completed, and swarm failed. `SwarmStarted`, `SwarmCompleted`, and `SwarmFailed` include an `executionMode` payload so listeners can distinguish `run`, `stream`, `queue`, and `durable` invocations.

Run context, artifacts, and run history are persisted automatically using the configured driver. The database driver stores records durably in package-managed tables. The cache driver is lighter and respects TTL settings.

For persistence drivers, history inspection, and the `SwarmHistory` facade and inspection commands, see [Persistence And History](docs/persistence-and-history.md).

To customize how swarm state is stored, bind your own implementations against the `ContextStore`, `ArtifactRepository`, or `RunHistoryStore` contracts. Most applications can use the package defaults.

## Documentation

- [Structured Input](docs/structured-input.md)
- [Persistence And History](docs/persistence-and-history.md)
- [Durable Execution](docs/durable-execution.md)
- [Testing](docs/testing.md)
- [Hierarchical Routing](docs/hierarchical-routing.md)
- [Pulse](docs/pulse.md)
- [Examples](examples/README.md)

## Local Development

From the package root:

- `./bin/setup-package.sh` — `composer install`, Pint, Pest
- `./bin/setup-dev.sh` — creates a sibling Laravel 13 app and path-requires this package (set `SWARM_DEV_APP_NAME` to override the directory name)

```bash
composer install
./vendor/bin/pint
./vendor/bin/pest tests/Feature tests/Unit
```

## License

MIT
