# Laravel Swarm

[![Latest Version on Packagist](https://img.shields.io/packagist/v/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![Total Downloads](https://img.shields.io/packagist/dt/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![Tests](https://github.com/builtbyberry/laravel-swarm/actions/workflows/tests.yml/badge.svg)](https://github.com/builtbyberry/laravel-swarm/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/builtbyberry/laravel-swarm/php.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)

Laravel Swarm brings reusable multi-agent orchestration to [Laravel](https://laravel.com) on top of the official [Laravel AI](https://github.com/laravel/ai) package.

Define a swarm once, return the Laravel AI agents that participate in it, and run the workflow synchronously, on a queue, as a stream, or as a checkpointed durable run.

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **Repository:** https://github.com/builtbyberry/laravel-swarm
- **Documentation:** [docs/README.md](docs/README.md)
- **Examples:** [examples/README.md](examples/README.md)
- **Upgrading:** [UPGRADING.md](UPGRADING.md)
- **Contributing:** [CONTRIBUTING.md](CONTRIBUTING.md)

## Requirements

- PHP **8.5+**
- Laravel **13+**
- `laravel/ai` **^0.6**

This package declares `"minimum-stability": "dev"` with `"prefer-stable": true`. Keep `prefer-stable` enabled in consuming applications unless you intentionally want Composer to resolve unstable transitive releases.

Laravel Swarm orchestrates the same Laravel AI agents, providers, and streams as your application. Treat Composer updates to Laravel or `laravel/ai` as integration-test events: run your test suite and any queued, streamed, or durable swarm smoke paths after dependency changes. This package's [changelog](CHANGELOG.md) covers Swarm-owned changes; it does not replace verification against upstream Laravel or Laravel AI releases.

## Installation

Require the package with Composer:

```bash
composer require builtbyberry/laravel-swarm
```

Tagged releases are available on [Packagist](https://packagist.org/packages/builtbyberry/laravel-swarm). Pin a tagged release for production applications. If you need to test the development branch before the next tag, require it explicitly:

```bash
composer require builtbyberry/laravel-swarm:dev-main
```

Laravel Swarm loads its package migrations automatically. If your application
only uses cache persistence and should not create swarm tables, opt out before
running migrations by calling `LaravelSwarm::ignoreMigrations()` in
`AppServiceProvider::register()`:

```php
use BuiltByBerry\LaravelSwarm\LaravelSwarm;

public function register(): void
{
    LaravelSwarm::ignoreMigrations();
}
```

Otherwise, run your application migrations:

```bash
php artisan migrate
```

Publish the configuration when you want to change defaults:

```bash
php artisan vendor:publish --tag=swarm-config
```

Check the configured stores before deploying a new environment:

```bash
php artisan swarm:health
php artisan swarm:health --durable
```

`--durable` also verifies the database tables required by `dispatchDurable()` and coordinated multi-worker hierarchical queueing.

## Your First Swarm

Generate a swarm class:

```bash
php artisan make:swarm ContentPipeline
```

Swarms live in `App\Ai\Swarms`, implement `BuiltByBerry\LaravelSwarm\Contracts\Swarm`, use the `Runnable` trait, and return their participating Laravel AI agents from `agents()`:

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ArticleEditor;
use App\Ai\Agents\ArticlePlanner;
use App\Ai\Agents\ArticleWriter;
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
            new ArticlePlanner,
            new ArticleWriter,
            new ArticleEditor,
        ];
    }
}
```

In a sequential swarm, the first agent receives the original task. Each later agent receives the previous agent's output.

## Running A Swarm

Use `prompt()` when the caller can wait for the full workflow result:

```php
use App\Ai\Swarms\ContentPipeline;

$response = ContentPipeline::make()->prompt('Draft a launch post about Laravel queues.');

$response->output;
$response->steps;
$response->usage;
$response->artifacts;
$response->metadata;
```

Structured task input is supported:

```php
$response = ContentPipeline::make()->prompt([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'launch post',
]);
```

`run()` remains available as a compatibility alias for `prompt()`.

`SwarmResponse` casts to a string for simple use cases and implements `toArray()` / `JsonSerializable` for JSON responses:

```php
return response()->json($response);
```

`toArray()` intentionally omits the live `RunContext` so an API response does not accidentally re-emit prompt or input data. Read `$response->context` directly when your application needs the in-process context.

## Choosing An Execution Mode

| Method | Returns | Use when |
| --- | --- | --- |
| `prompt()` | `SwarmResponse` | The request can wait for the full result. |
| `run()` | `SwarmResponse` | Existing code still calls the compatibility alias. |
| `queue()` | `QueuedSwarmResponse` | One background job can own the workflow. |
| `stream()` | `StreamableSwarmResponse` | A sequential workflow should emit live progress or token events. |
| `broadcast()` / `broadcastNow()` | `StreamableSwarmResponse` | A sequential workflow should stream and broadcast typed events immediately. |
| `broadcastOnQueue()` | `QueuedSwarmResponse` | A worker should stream and broadcast typed events. |
| `dispatchDurable()` | `DurableSwarmResponse` | The workflow needs checkpointing, recovery, operator controls, or branch jobs. |

`queue()` and `dispatchDurable()` return dispatch handles with a `runId`. Listen for lifecycle events or inspect persisted history for eventual results.

`stream()` and the broadcast helpers support sequential swarms only. Use lifecycle events and application-owned broadcasts for queued, durable, parallel, or hierarchical operations feeds.

## Queueing A Swarm

Use `queue()` when the workflow should run in the background:

```php
use App\Ai\Swarms\ContentPipeline;

$response = ContentPipeline::make()
    ->queue([
        'topic' => 'Laravel queues',
        'audience' => 'intermediate developers',
    ])
    ->onConnection('redis')
    ->onQueue('ai');

$response->runId;
```

Queued swarms are re-resolved from Laravel's container on the worker. Keep swarm definitions stateless across the queue boundary, and pass per-run data in the task payload:

```php
// Do this.
ContentPipeline::make()->queue(['draft_id' => $draft->id]);

// Do not rely on runtime constructor state crossing the queue boundary.
(new ContentPipeline($draft->id))->queue('Review the draft');
```

Queued and durable task payloads should use plain data: strings, integers, floats, booleans, null, and arrays containing only those values. Do not pass models, closures, resources, or runtime service objects.

With the shipped conservative defaults, queued and durable swarms require active context capture:

```env
SWARM_CAPTURE_ACTIVE_CONTEXT=true
```

You may still leave input, output, and artifact capture disabled for redacted history.

## Streaming A Swarm

Use `stream()` when a browser, CLI, or custom consumer needs live typed events from a sequential swarm:

```php
foreach (ContentPipeline::make()->stream(['topic' => 'Laravel queues']) as $event) {
    if ($event->type() === 'swarm_text_delta') {
        // $event->delta
    }
}
```

Return the response directly from a route for Laravel AI-style SSE output:

```php
return ContentPipeline::make()->stream([
    'topic' => 'Laravel queues',
]);
```

Broadcast the same typed stream events through Laravel broadcasting:

```php
use Illuminate\Broadcasting\PrivateChannel;

ContentPipeline::make()->broadcast(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.content-pipeline'),
);

ContentPipeline::make()->broadcastNow(
    ['topic' => 'Laravel queues'],
    new PrivateChannel('swarm.content-pipeline'),
);

ContentPipeline::make()
    ->broadcastOnQueue(
        ['topic' => 'Laravel queues'],
        new PrivateChannel('swarm.content-pipeline'),
    )
    ->onQueue('ai-streams');
```

Persisted stream replay is opt in:

```php
$stream = ContentPipeline::make()
    ->stream(['topic' => 'Laravel queues'])
    ->storeForReplay();
```

Replay later with `SwarmHistory::replay($runId)`. See [Streaming](docs/streaming.md) for event schemas, replay behavior, capture, limits, and failure handling.

## Durable Execution

Use `dispatchDurable()` when the workflow is too important or too long-lived for one queue job:

```php
$response = ContentPipeline::make()
    ->dispatchDurable([
        'topic' => 'Laravel queues',
        'audience' => 'intermediate developers',
    ])
    ->onQueue('swarm-durable');

$response->runId;
```

Durable execution requires database-backed swarm persistence and advances the workflow through checkpointed jobs. Sequential durable swarms run one agent per job. Parallel durable swarms and hierarchical parallel groups use independent branch jobs and join before continuing.

Durable responses also expose operator helpers:

```php
$response->inspect();
$response->pause();
$response->resume();
$response->cancel();
$response->signal('approval_received', ['approved' => true], idempotencyKey: 'approval-123');
```

Schedule recovery for durable execution and coordinated multi-worker hierarchical queueing:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

Start with [Durable Execution](docs/durable-execution.md), then use the topic guides for [waits and signals](docs/durable-waits-and-signals.md), [retries and progress](docs/durable-retries-and-progress.md), [child swarms](docs/durable-child-swarms.md), and [webhooks](docs/durable-webhooks.md).

## Topologies

Laravel Swarm supports three topologies.

### Sequential

Agents run in order. Each agent receives the previous agent's output.

```php
#[Topology(TopologyEnum::Sequential)]
class ContentPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new Planner, new Writer, new Editor];
    }
}
```

### Parallel

Agents run concurrently and each receives the original task.

Parallel agents must be stateless and container-resolvable by class because Laravel concurrency resolves them inside worker processes.

```php
#[Topology(TopologyEnum::Parallel)]
class ResearchSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new MarketResearcher, new CompetitorResearcher, new SeoResearcher];
    }
}
```

### Hierarchical

The first agent is the coordinator. It returns a Laravel AI structured output route plan. Laravel Swarm validates the plan as a DAG and executes selected worker, parallel, and finish nodes.

```php
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

Read [Hierarchical Routing](docs/hierarchical-routing.md) for the route plan schema, validation rules, queue behavior, and durable branch coordination.

## Testing

Use `fake()` to intercept swarm execution in application tests:

```php
use App\Ai\Swarms\ContentPipeline;

ContentPipeline::fake(['first response']);

expect((string) ContentPipeline::make()->prompt('Draft an intro'))->toBe('first response');

ContentPipeline::assertPrompted('Draft an intro');
ContentPipeline::assertNeverQueued();
```

Fakes cover prompt, queue, stream, broadcast, and durable dispatch intent:

```php
ContentPipeline::assertQueued(['draft_id' => 42]);
ContentPipeline::assertStreamed('Draft an intro');
ContentPipeline::assertDispatchedDurably(['document_id' => 100]);
```

Use database-backed feature tests when you need to prove durable leases, checkpoints, retries, branch joins, wait release, recovery, or webhook idempotency. `SwarmFake` records intent; it does not execute the durable runtime.

See [Testing](docs/testing.md) and [Testing Swarms](examples/testing-swarms/README.md).

## Configuration

Laravel Swarm stores defaults in `config/swarm.php`.

Common settings include:

- `swarm.topology`
- `swarm.timeout`
- `swarm.max_agent_steps`
- `swarm.persistence.driver`
- `swarm.capture.*`
- `swarm.queue.*`
- `swarm.durable.*`
- `swarm.streaming.replay.*`
- `swarm.observability.*`
- `swarm.audit.*`

Capture defaults are conservative. Prompts, outputs, automatic step artifacts, and rich active-context snapshots are not persisted unless you opt in. When `swarm.persistence.driver` is `database`, `swarm.persistence.encrypt_at_rest` defaults to true and seals designated sensitive string columns with Laravel's encrypter.

Use [Persistence And History](docs/persistence-and-history.md), [Maintenance](docs/maintenance.md), [Observability: Logging And Tracing](docs/observability-logging-tracing.md), and [Audit Evidence Contract](docs/audit-evidence-contract.md) before enabling production capture, audit, or retention policies.

## Production Checklist

- Choose `prompt()`, `queue()`, `stream()`, or `dispatchDurable()` intentionally.
- Use database persistence for durable execution, long-lived history, active-run pruning protection, or operational dashboards.
- Set `SWARM_CAPTURE_ACTIVE_CONTEXT=true` for queued and durable swarms.
- Size queue worker timeouts and queue `retry_after` above the longest expected provider call.
- Schedule `swarm:recover` for durable execution and coordinated multi-worker hierarchical queueing.
- Schedule `swarm:prune` for database retention cleanup, or set `SWARM_PREVENT_PRUNE=true` when retention is managed outside the package.
- Treat operational swarm tables as TTL-based runtime storage, not immutable compliance archives.
- Bind `SwarmAuditSink` for regulated evidence export.
- Bind `SwarmTelemetrySink` for logs, metrics, or tracing correlation.
- Avoid secrets in metadata. Capture redaction does not sanitize arbitrary developer-supplied metadata.
- Build run inspection around `run_id`, lifecycle events, `SwarmHistory`, and durable runtime state.

## Documentation

Start with the [documentation index](docs/README.md).

- [Structured Input](docs/structured-input.md)
- [Streaming](docs/streaming.md)
- [Hierarchical Routing](docs/hierarchical-routing.md)
- [Persistence And History](docs/persistence-and-history.md)
- [Durable Execution](docs/durable-execution.md)
- [Durable Runtime Architecture](docs/durable-runtime-architecture.md)
- [Durable Waits And Signals](docs/durable-waits-and-signals.md)
- [Durable Retries And Progress](docs/durable-retries-and-progress.md)
- [Durable Child Swarms](docs/durable-child-swarms.md)
- [Durable Webhooks](docs/durable-webhooks.md)
- [Observability: Logging And Tracing](docs/observability-logging-tracing.md)
- [Observability Correlation Contract](docs/observability-correlation-contract.md)
- [Audit Evidence Contract](docs/audit-evidence-contract.md)
- [Testing](docs/testing.md)
- [Pulse](docs/pulse.md)
- [Maintenance](docs/maintenance.md)
- [Public Surface Coverage](docs/public-surface.md)
- [Examples](examples/README.md)

## Local Development

From the package root:

```bash
composer install
composer lint
composer analyse
composer test
```

If you run PHPStan directly, use:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

`composer format` rewrites files with Pint. Use `composer lint` when you need a non-mutating formatting check.

## License

MIT
