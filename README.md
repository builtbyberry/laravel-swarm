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

Publish the package configuration:

```bash
php artisan vendor:publish --tag=swarm-config
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
foreach (ArticlePipeline::make()->stream('Draft a blog outline about Laravel queues.') as $event) {
    // ['event' => 'step', ...] or ['event' => 'token', ...]
}
```

Streaming is currently supported for sequential swarms only.

## Topologies

### Sequential

Agents run in order. Each agent receives the previous agent's output.

### Parallel

Agents run at the same time and each receives the original task.

### Hierarchical

Hierarchical topology is available but currently routes through sequential execution. Full coordinator-based routing is planned for a future release.

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
- context persistence
- artifact persistence
- run history persistence
- queue connection and queue name

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
