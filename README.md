![](https://banners.beyondco.de/Laravel%20Swarm.png?theme=light&packageManager=composer+require&packageName=builtbyberry%2Flaravel-swarm&pattern=aztec&style=style_1&description=Lightweight+orchestration+package+for+coordinating+AI+agents%2C+workflows%2C+and+distributed+task+execution+within+Laravel+applications+built+on+Laravel+AI&md=1&showWatermark=1&fontSize=100px&images=cog) 

# Laravel Swarm

[![Latest Version on Packagist](https://img.shields.io/packagist/v/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![Total Downloads](https://img.shields.io/packagist/dt/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![Tests](https://github.com/builtbyberry/laravel-swarm/actions/workflows/tests.yml/badge.svg)](https://github.com/builtbyberry/laravel-swarm/actions/workflows/tests.yml)
[![Nightly (Laravel dev-main)](https://github.com/builtbyberry/laravel-swarm/actions/workflows/nightly.yml/badge.svg)](https://github.com/builtbyberry/laravel-swarm/actions/workflows/nightly.yml)
[![License](https://img.shields.io/packagist/l/builtbyberry/laravel-swarm.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/builtbyberry/laravel-swarm/php.svg)](https://packagist.org/packages/builtbyberry/laravel-swarm)
[![Documentation](https://img.shields.io/badge/docs-swarm.builtbyberry.com-2563eb.svg)](https://swarm.builtbyberry.com)

> **📚 Full documentation: [swarm.builtbyberry.com](https://swarm.builtbyberry.com)**

Laravel Swarm brings reusable multi-agent orchestration to [Laravel](https://laravel.com) on top of the official [Laravel AI](https://github.com/laravel/ai) package.

Define a swarm once, return the Laravel AI agents that participate in it, and run the workflow synchronously, on a queue, as a stream, or as a checkpointed durable run.

- **Documentation:** [swarm.builtbyberry.com](https://swarm.builtbyberry.com)
- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **Repository:** https://github.com/builtbyberry/laravel-swarm
- **In-repo docs:** [docs/README.md](docs/README.md)
- **Examples:** [examples/README.md](examples/README.md)
- **Upgrading:** [UPGRADING.md](UPGRADING.md)
- **Contributing:** [CONTRIBUTING.md](CONTRIBUTING.md)

## Quick Start

```bash
composer require builtbyberry/laravel-swarm
php artisan swarm:install
php artisan make:swarm:swarm ContentPipeline
```

```php
use App\Ai\Swarms\ContentPipeline;

$response = ContentPipeline::make()->prompt('Draft a launch post about Laravel queues.');

echo $response->output;
```

For background execution, streaming, and durable workflows, see [Choosing An Execution Mode](#choosing-an-execution-mode).

## Requirements

- PHP **8.5+**
- Laravel **13+**
- `laravel/ai` **^0.6**

This package declares `"minimum-stability": "dev"` with `"prefer-stable": true` because `laravel/ai` is still pre-1.0 and ships dev-tagged releases. Composer will not resolve a pre-stable transitive dependency from a stable consuming project, so your application's `composer.json` must also set:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

`prefer-stable` keeps Composer biased toward tagged releases — only dependencies without a stable release (today, `laravel/ai`) resolve to a `dev-` constraint. This requirement will be dropped when `laravel/ai` reaches 1.0; the package will then move to `"minimum-stability": "stable"` and consuming applications will be free to do the same.

Laravel Swarm orchestrates the same Laravel AI agents, providers, and streams as your application. Treat Composer updates to Laravel or `laravel/ai` as integration-test events: run your test suite and any queued, streamed, or durable swarm smoke paths after dependency changes. This package's [changelog](CHANGELOG.md) covers Swarm-owned changes; it does not replace verification against upstream Laravel or Laravel AI releases.

## Installation

Require the package with Composer, then run the interactive installer:

```bash
composer require builtbyberry/laravel-swarm
php artisan swarm:install
```

Tagged releases are available on [Packagist](https://packagist.org/packages/builtbyberry/laravel-swarm). Pin a tagged release for production applications.

`swarm:install` walks you through the full setup in one shot — it publishes `config/swarm.php`, seeds the canonical Swarm `.env` keys with safe defaults, runs the package migrations (or scaffolds `LaravelSwarm::ignoreMigrations()` for a cache-only deployment), warns when `QUEUE_CONNECTION=sync`, and offers to dispatch the targeted sub-installers in the same pass:

- [`swarm:install:durable`](docs/durable-execution.md) — scheduler entries (`swarm:relay`, `swarm:recover`, `swarm:prune`), persistence/queue checks, copy-paste worker snippets.
- [`swarm:install:audit`](docs/audit-evidence-contract.md) — bind a `SwarmAuditSink` (and optional `SwarmAuditSigner` / `ActorResolver` / `CapturePolicy`) inside `AppServiceProvider`.
- [`swarm:install:pulse`](docs/pulse.md) — register the Swarm recorders and dashboard cards (only offered when `laravel/pulse` is installed).
- [`swarm:install:examples`](docs/examples.md) — copy the runnable starter example pack into `app/Ai/`.

For CI and scripted setups, every prompt has a flag override:

```bash
php artisan swarm:install \
    --no-interaction \
    --persistence=database \
    --with-durable --with-audit --with-examples
```

Pass `--without-<name>` to skip a sub-installer in non-interactive mode, `--persistence=cache` for cache-only deployments, `--skip-migrate` to defer migrations, or `--force` to overwrite an existing `config/swarm.php`.

After the install, confirm everything wired up cleanly:

```bash
php artisan swarm:health
php artisan swarm:health --durable
```

`--durable` also verifies the database tables required by `dispatchDurable()` and coordinated multi-worker hierarchical queueing.

Read [Getting Started](docs/getting-started.md) for the full new-user walkthrough — installer flow, post-install verification, and running your first starter swarm in under five minutes.

### Advanced setup (manual)

Prefer to wire things by hand? Every step `swarm:install` performs has a stable manual equivalent. See [Advanced Setup](docs/advanced-setup.md) for the full manual flow — config publish, migrations vs. `ignoreMigrations()`, scheduler entries, audit sink binding, Pulse recorder + dashboard registration, and copying the starter examples by hand.

## Your First Swarm

Generate a swarm class:

```bash
php artisan make:swarm:swarm ContentPipeline
```

See [Generators](docs/generators.md) for the full generator surface, including `make:swarm:agent` and the `--topology` flag.

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

**Guardrails** (input, per-step, final output policy checks) run across these modes at fixed orchestration boundaries; see [docs/guardrails.md](docs/guardrails.md).

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

Schedule the relay, recovery, and pruning for durable execution:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:relay')->everyMinute();   // required: drains the outbox after each checkpoint
Schedule::command('swarm:recover')->everyMinute(); // safety net: redispatches stranded runs
Schedule::command('swarm:prune')->daily();         // retention: removes expired persistence rows
```

Start with [Durable Execution](docs/durable-execution.md), then use the topic guides for [waits and signals](docs/durable-waits-and-signals.md), [retries and progress](docs/durable-retries-and-progress.md), [child swarms](docs/durable-child-swarms.md), and [webhooks](docs/durable-webhooks.md).

## Memory (v0.9.0+)

Swarm Memory is a first-class, scoped, snapshot-replayable memory subsystem. It gives agents and application code a place to read and write structured values that persist across steps, survive queue boundaries, and can be replayed deterministically from a frozen snapshot on a crash-resume.

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

$memory = app(SwarmMemory::class);
$memory->put(MemoryScope::Run, $runId, 'draft_approved', true);
$approved = $memory->get(MemoryScope::Run, $runId, 'draft_approved');
```

`RunContext` writes through to `MemoryScope::Run` automatically via its `ArrayAccess` interface — `$context['key'] = $value` mirrors to memory without any code change.

**Propagation, redaction, and an operator surface (v0.10.0+).** Memory now ships the controls a regulated workload needs:

- **`MemoryPropagationPolicy`** decides which memory entries a worker agent sees at invocation (default: the Run-scoped view, byte-identical to v0.9).
- **`MemoryCapturePolicy`** redacts or drops entries at the write boundary, so PII never reaches a snapshot (default: a no-op).
- **Operator CLI** — `swarm:memory:inspect` (view a run's frozen snapshots), `swarm:memory:dump` (export the full memory + snapshot trail for an audit packet / DSAR), and `swarm:memory:purge` (enforce per-scope retention windows).

See [Swarm Memory](docs/memory.md) for the full reference: scope hierarchy, store drivers, lifecycle events, propagation and capture policies, snapshot inspection, replay semantics (`frozen_view` vs `fresh_execution`), and the `#[MemoryReplay]` / `#[PropagationPolicy]` attributes; and [Compliance & Audit](docs/compliance-audit.md) for the regulated-workload runbook (redaction, retention, audit-packet export). Vector-backed recall ships as the [laravel-swarm-memory-vector](https://github.com/builtbyberry/laravel-swarm-memory-vector) companion package.

**Agent memory tools (v0.11.0+).** Agents can now read and write memory *mid-prompt* as ordinary `laravel/ai` tools — drop the shipped `Recall` and `Remember` tools into any agent's `tools()` array (or expose them via the `HasSwarmMemoryTools` trait). Scope ids resolve from the active run, never the model; reads honour the propagation policy and writes honour the capture policy, so the tools can never surface or persist anything the policies forbid. They are **disabled by default** (`swarm.memory.tools.enabled`) — granting an LLM access to shared memory is an explicit decision. Scaffold custom variants with `php artisan make:memory-tool`. See the [memory recipes](docs/memory-recipes.md) for worked patterns: per-user and tenant-scoped recall, policy-enforced custom tools, recall + redact, and sub-agent memory continuity.

## Topologies

Laravel Swarm supports four topologies.

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

### Static Hierarchical

The route plan is defined in PHP — no coordinator LLM call runs at runtime. Use it when the graph of agents is always the same and only the content changes.

```php
#[Topology(TopologyEnum::StaticHierarchical)]
class ContentSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new Researcher, new Writer, new Editor];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'finish',
            'nodes' => [
                'finish' => ['type' => 'finish', 'output' => ''],
            ],
        ];
    }
}
```

Read [Static Hierarchical Topology](docs/static-hierarchical-topology.md) for the plan schema, streaming modes, step budgets, and execution mode support.

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
- `swarm.limits.*`

Capture defaults are conservative. Prompts, outputs, automatic step artifacts, and rich active-context snapshots are not persisted unless you opt in. When the global persistence driver or a per-store override uses `database`, `swarm.persistence.encrypt_at_rest` defaults to true and seals designated sensitive string columns with Laravel's encrypter.

Use [Persistence And History](docs/persistence-and-history.md), [Maintenance](docs/maintenance.md), [Observability: Logging And Tracing](docs/observability-logging-tracing.md), and [Audit Evidence Contract](docs/audit-evidence-contract.md) before enabling production capture, audit, or retention policies.

## Production Checklist

- Choose `prompt()`, `queue()`, `stream()`, or `dispatchDurable()` intentionally.
- Use database persistence for durable execution, long-lived history, active-run pruning protection, or operational dashboards.
- Set `SWARM_CAPTURE_ACTIVE_CONTEXT=true` for queued and durable swarms.
- Size queue worker timeouts and queue `retry_after` above the longest expected provider call.
- Schedule `swarm:relay` every minute for durable execution AND for the v0.5 audit outbox. The relay drains the durable outbox after each checkpoint and replays failed audit records through the bound sink — a single schedule covers both lanes. Without it, durable runs stall after the first step and queued audit failures accumulate without retry. Use `swarm:relay --type=audit` to drain only the audit lane during focused recovery. See [Durable Execution](docs/durable-execution.md) and [Audit Evidence Contract](docs/audit-evidence-contract.md) for the full relay reference.
- Run `php artisan migrate` on database persistence to create `swarm_audit_outbox` — required for the v0.5 default `SWARM_AUDIT_FAILURE_POLICY=queue` (sink failures persist for retry instead of being silently dropped). Cache persistence detects the missing outbox and falls back to log-and-swallow automatically.
- Schedule `swarm:recover` every five minutes for durable execution and coordinated multi-worker hierarchical queueing. Recovery redispatches runs whose workers died between checkpoint and dispatch. See [Maintenance](docs/maintenance.md).
- Schedule `swarm:prune` daily for database retention cleanup, or set `SWARM_PREVENT_PRUNE=true` when retention is managed outside the package.
- Treat operational swarm tables as TTL-based runtime storage, not immutable compliance archives.
- Bind `SwarmAuditSink` for regulated evidence export.
- Bind `SwarmTelemetrySink` for logs, metrics, or tracing correlation.
- Avoid secrets in metadata. Capture redaction does not sanitize arbitrary developer-supplied metadata. Set `SWARM_MAX_METADATA_BYTES` to enforce a hard size cap.
- Build run inspection around `run_id`, lifecycle events, `SwarmHistory`, and durable runtime state.
- Bookmark the [Operator Runbook: Audit Outbox Triage](docs/operator-runbook-audit-outbox.md) before going live. It is the 3 a.m. decision tree for dead-letter rows, stale pending rows, and sink outages — and it assumes the reader has not just re-read the reference docs.
- Use `php artisan swarm:trace <run_id>` (v0.7+) to reconstruct a single run's audit chain across run history, the audit outbox, and the bound sink. Read-only, on-demand, with `--json` for monitoring and `--include-payloads` for full envelopes. See [Audit Evidence Contract](docs/audit-evidence-contract.md#reading-the-audit-chain), including the **Security and retention** subsection covering how the command unseals encrypted-at-rest data on output.

### Audit Extension Points

Regulated deployments can replace four audit contracts in the container:

- `ActorResolver` — resolves the human or system actor recorded on each evidence envelope.
- `CapturePolicy` — decides which run fields are captured, redacted, or omitted before evidence is emitted.
- `SinkFailureHandler` — handles `SwarmAuditSink` write failures (log, retry, halt, or escalate).
- `SwarmAuditSigner` — produces the cryptographic signature attached to each envelope for tamper-evident export.

One optional extension contract (v0.7+):

- `ReadableSwarmAuditSink` — extends `SwarmAuditSink` with `forRun(string $runId): iterable<array>` so the sink can participate in `swarm:trace`. Opt-in; the shipped `NoOpSwarmAuditSink` does not implement it and existing custom sinks remain valid.

Two environment knobs control strict-mode behavior:

- `SWARM_AUDIT_ACTOR_REQUIRED=true` — fail closed when no actor can be resolved for a run.
- `SWARM_AUDIT_FAILURE_POLICY=halt` — halt run progression when the audit sink rejects an envelope.

The full `SWARM_AUDIT_FAILURE_POLICY` matrix (since v0.5): `swallow` (drop silently — v0.4 default), `log` (log and continue), `queue` (persist to `swarm_audit_outbox` for retry — v0.5 default), `dead_letter` (persist directly to dead-letter status, no retry), `halt` (fail the run). The audit outbox is monitored by default via `swarm:health` (or `swarm:health --audit` for focused investigation).

See [Audit Evidence Contract](docs/audit-evidence-contract.md) for the full reference. When responding to an audit-outbox page, see the [Operator Runbook: Audit Outbox Triage](docs/operator-runbook-audit-outbox.md).

## Documentation

The full documentation site is at **[swarm.builtbyberry.com](https://swarm.builtbyberry.com)** — searchable, versioned, and the recommended starting point.

The same content is mirrored in this repository; start with the [in-repo documentation index](docs/README.md) when working offline.

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
