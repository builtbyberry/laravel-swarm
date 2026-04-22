# Laravel Swarm

Multi-agent swarm orchestration for [Laravel](https://laravel.com) on top of the official [Laravel AI](https://github.com/laravel/ai) package. Laravel Swarm coordinates multiple `Agent` instances into sequential, parallel, or hierarchical topologies with rich responses, lifecycle events, persistence hooks, and queue support.

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **Repository:** https://github.com/builtbyberry/laravel-swarm

## Requirements

- PHP **8.5+** (see `composer.json`; use `composer install --ignore-platform-reqs` only when your local CLI is temporarily behind).
- Laravel **13+**
- `laravel/ai` **^0.6** (this tracks the current Laravel AI 0.x line; when Laravel AI ships `^1.0`, bump the constraint accordingly).

## Installation

```bash
composer require builtbyberry/laravel-swarm
```

Publish configuration:

```bash
php artisan vendor:publish --tag=swarm-config
```

Optional stub publishing (for customizing generator stubs in your app):

```bash
php artisan vendor:publish --tag=swarm-stubs
```

## Usage

Generate a swarm class (placed in `App\Ai\Swarms` by default):

```bash
php artisan make:swarm ContentPipeline
```

Implement `agents()` to return your Laravel AI agents, pick a `#[Topology]` attribute, and use the `Runnable` trait:

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

Run synchronously or queue explicitly:

```php
$response = ContentPipeline::make()->run('Draft a blog outline about Laravel queues.');
// (string) $response === $response->output

ContentPipeline::make()
    ->queue('Draft a blog outline about Laravel queues.')
    ->then(function (\BuiltByBerry\LaravelSwarm\Responses\SwarmResponse $response) {
        // ...
    })
    ->catch(function (\Throwable $e) {
        // ...
    });
```

### Facade

Applications may alias `Swarm` to `BuiltByBerry\LaravelSwarm\Facades\Swarm` (registered by the package) to resolve the underlying `SwarmRunner` from the container.

### Fakes (testing)

```php
ContentPipeline::fake(['first', 'second']);

ContentPipeline::assertRan('first task');
ContentPipeline::assertNeverQueued();
```

### Responses and events

Swarm runs return a `SwarmResponse` with the final output plus step details, artifacts, and metadata. Lifecycle events are dispatched for swarm start, step start, step completion, swarm completion, and failures so applications can observe runs without coupling to internal runtime objects.

## Configuration

See `config/swarm.php` for defaults and environment hooks (`SWARM_*`).

## Local package scripts

From the package root:

- `./bin/setup-package.sh` — `composer install`, Pint, Pest.
- `./bin/setup-dev.sh` — creates a sibling Laravel 13 app and path-requires this package (set `SWARM_DEV_APP_NAME` to override the directory name).

## Development

```bash
composer install
./vendor/bin/pint
./vendor/bin/pest tests/Feature tests/Unit
```

## License

MIT
