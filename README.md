# Laravel Swarm

Laravel Swarm brings multi-agent orchestration to [Laravel](https://laravel.com) on top of the official [Laravel AI](https://github.com/laravel/ai) package. Define a swarm once, return the Laravel AI agents that participate in it, and run them through sequential, parallel, or hierarchical topologies using explicit Laravel-style verbs.

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **Repository:** https://github.com/builtbyberry/laravel-swarm

## Requirements

- PHP **8.5+**
- Laravel **13+**
- `laravel/ai` **^0.6**

## Installation

```bash
composer require builtbyberry/laravel-swarm
```

Laravel Swarm loads its package migrations automatically through the service provider, so the swarm tables are created during your normal migration flow:

```bash
php artisan migrate
```

Publish the package configuration if you want to customize defaults:

```bash
php artisan vendor:publish --tag=swarm-config
```

If you want to customize the package migrations in your app, publish them explicitly:

```bash
php artisan vendor:publish --tag=swarm-migrations
```

If you want to customize the generated stub in your app, publish it too:

```bash
php artisan vendor:publish --tag=swarm-stubs
```

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

`SwarmResponse` can still be cast to a string:

```php
(string) $response;
```

## Queueing A Swarm

Use `queue()` when the swarm should run in the background:

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

`queue()` always queues. `run()` always runs synchronously.

## Streaming A Swarm

Use `stream()` when you want step and token events for server-sent events or other real-time updates:

```php
try {
    foreach (ArticlePipeline::make()->stream('Draft a blog outline about Laravel queues.') as $event) {
        // ['event' => 'step', ...] or ['event' => 'token', ...]
    }
} catch (\Throwable $exception) {
    //
}
```

Swarm streams emit `step` events for agent lifecycle progress and `token` events for streamed final-agent output.

Streaming is currently supported for sequential swarms only.

If the final streamed agent fails, the generator re-throws the underlying exception from that agent. Wrap the stream loop in `try/catch`; `SwarmFailed` is dispatched and run history is marked failed before the exception is re-thrown.

## Topologies

### Sequential

Agents run in order. Each agent receives the previous agent's output.

### Parallel

Agents run at the same time and each receives the original task.

### Hierarchical

In a hierarchical swarm, the first agent acts as the coordinator and decides which downstream agents should run next.

```php
use App\Ai\Agents\PlannerAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\WriterAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Topology(TopologyEnum::Hierarchical)]
class ArticlePlanningSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new PlannerAgent,
            new ResearchAgent,
            new WriterAgent,
        ];
    }

    public function route(string $coordinatorOutput, array $agents, RunContext $context): array
    {
        return [
            [
                'agent_class' => ResearchAgent::class,
                'input' => 'Research the claims in this plan and collect source notes: '.$coordinatorOutput,
                'metadata' => ['stage' => 'research'],
            ],
            [
                'agent_class' => WriterAgent::class,
                'input' => 'Write the draft using this approved plan: '.$coordinatorOutput,
                'metadata' => ['stage' => 'draft'],
            ],
        ];
    }
}
```

Route instructions may contain:

- `agent` or `agent_class`
- `input`
- optional `metadata`

Hierarchical behavior:

- the first agent returned from `agents()` is the coordinator
- workers are selected from the remaining agents returned by `agents()`
- routed workers execute sequentially in the order returned by `route()`
- an empty route completes successfully with the coordinator output
- missing `route()` fails fast for hierarchical swarms
- unknown routed classes throw an explicit exception naming the missing class

## Testing

Use `fake()` to intercept swarm execution in tests:

```php
ArticlePipeline::fake(['first', 'second']);

expect((string) ArticlePipeline::make()->run('draft intro'))->toBe('first');

ArticlePipeline::assertRan('draft intro');
ArticlePipeline::assertNeverQueued();
```

Fakes can also use a callback:

```php
ArticlePipeline::fake(fn (string $task) => 'handled: '.$task);
```

## Configuration

Laravel Swarm stores its defaults in `config/swarm.php`, including:

- default topology
- timeout
- max agent steps
- global persistence driver
- context persistence
- artifact persistence
- run history persistence
- queue connection and queue name

Persistence defaults support both cache-backed and durable database-backed storage:

- `swarm.persistence.driver` sets the default persistence driver for all swarm stores
- `swarm.context.driver`, `swarm.artifacts.driver`, and `swarm.history.driver` can override the global driver individually
- `cache` uses the configured cache store and honors TTL settings
- `database` stores durable run data in the package tables loaded by the service provider

TTL settings apply to cache-backed persistence. Database-backed persistence is durable until your application deletes the records.

## Responses, Events, And Persistence

Swarm runs return a `SwarmResponse` with:

- `output`
- `steps`
- `usage`
- `artifacts`
- `metadata`

Laravel Swarm also dispatches lifecycle events for:

- swarm started
- step started
- step completed
- swarm completed
- swarm failed

Built-in persistence supports both cache and database drivers for:

- run context
- artifacts
- run history

The database driver stores these records in package-managed tables while preserving the same contract read shapes as the cache-backed stores.

If you need to customize how swarm state is stored, bind your own implementations for the persistence contracts:

- `ContextStore`
- `ArtifactRepository`
- `RunHistoryStore`

These are advanced extension points. Most applications can use the package defaults.

## Local package scripts

From the package root:

- `./bin/setup-package.sh` — `composer install`, Pint, Pest
- `./bin/setup-dev.sh` — creates a sibling Laravel 13 app and path-requires this package (set `SWARM_DEV_APP_NAME` to override the directory name)

## Development

```bash
composer install
./vendor/bin/pint
./vendor/bin/pest tests/Feature tests/Unit
```

## License

MIT
