# Observability: Logging And Tracing

Laravel Swarm does **not** ship OpenTelemetry or a hard-wired logging stack. It
dispatches **lifecycle events** and supports **optional Laravel Pulse** cards for
aggregate metrics. Your application owns how logs, traces, and dashboards are
wired — this guide shows a Laravel-native path that stays **dependency-free**
with respect to tracing vendors.

## Correlation Fields

Use these fields consistently across HTTP requests, queue workers, and durable
steps so operators can follow one logical run:

| Field | Source | Notes |
| --- | --- | --- |
| `run_id` | `$event->runId` (or `childRunId` on child events) | Stable identifier for the swarm run; appears in history, durable tables, and Artisan commands. |
| `swarm_class` | `$event->swarmClass` | FQCN of the swarm. |
| `topology` | `$event->topology` when present | String topology value (`sequential`, `parallel`, `hierarchical`). |
| `execution_mode` | `$event->executionMode` when present | `run`, `queue`, `stream`, or `durable` (see `BuiltByBerry\LaravelSwarm\Enums\ExecutionMode`). Synchronous `prompt()` uses `run` for compatibility. |
| `parent_run_id` | Child swarm events only | Links a durable child run back to its parent. |

Child events (`SwarmChildStarted`, `SwarmChildCompleted`, `SwarmChildFailed`) use
`parentRunId` and `childRunId` instead of a single `runId`.

## Lifecycle Events

Subscribe to the events your operators care about. All live under
`BuiltByBerry\LaravelSwarm\Events\`:

| Event | Typical use |
| --- | --- |
| `SwarmStarted` | Open span, set log context, increment active-run gauge. |
| `SwarmStepStarted` / `SwarmStepCompleted` | Step-level logs or spans (includes `index`, `agentClass`). |
| `SwarmCompleted` | Close span as OK, emit duration, clear context. |
| `SwarmFailed` | Close span as error; includes `exception` and `exceptionClass`. |
| `SwarmPaused` / `SwarmResumed` / `SwarmCancelled` | Operator and audit trails. |
| `SwarmWaiting` / `SwarmWaitTimedOut` | Durable wait instrumentation. |
| `SwarmSignalled` | External signal handling (`accepted` flag). |
| `SwarmProgressRecorded` | Durable progress checkpoints (`branchId`, `progress`). |
| `SwarmChildStarted` / `SwarmChildCompleted` / `SwarmChildFailed` | Nested durable swarms. |

Terminal events for the **parent** run include `SwarmCompleted`, `SwarmFailed`,
and `SwarmCancelled`. Treat `SwarmFailed` as the error path for exceptions
during orchestration.

## Example: Structured Logs From Events

Register listeners in your application’s `AppServiceProvider` (or dedicated
provider). **Keep listeners cheap**; heavy work belongs on a queue listener of
your own.

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

Event::listen(SwarmStarted::class, function (SwarmStarted $event): void {
    Log::info('swarm.started', [
        'swarm_run_id' => $event->runId,
        'swarm_class' => $event->swarmClass,
        'swarm_topology' => $event->topology,
        'swarm_execution_mode' => $event->executionMode,
    ]);
});

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    Log::info('swarm.completed', [
        'swarm_run_id' => $event->runId,
        'swarm_class' => $event->swarmClass,
        'swarm_topology' => $event->topology,
        'swarm_execution_mode' => $event->executionMode,
        'duration_ms' => $event->durationMs,
    ]);
});

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    Log::error('swarm.failed', [
        'swarm_run_id' => $event->runId,
        'swarm_class' => $event->swarmClass,
        'swarm_topology' => $event->topology,
        'swarm_execution_mode' => $event->executionMode,
        'duration_ms' => $event->durationMs,
        'exception_class' => $event->exceptionClass,
        'message' => $event->exception->getMessage(),
    ]);
});
```

Wrap **optional** vendor tracing in `try` / `catch` so a broken tracer never
fails the swarm:

```php
Event::listen(SwarmStarted::class, function (SwarmStarted $event): void {
    try {
        // Example: your OpenTelemetry wrapper — not provided by this package.
        // app(MyTracer::class)->startRunSpan($event);
    } catch (Throwable $e) {
        Log::warning('swarm.trace_hook_failed', [
            'swarm_run_id' => $event->runId,
            'message' => $e->getMessage(),
        ]);
    }
});
```

Swarm execution does not depend on listener success; failed listeners are still
reported by Laravel’s event dispatcher and should be monitored like any other
application listener.

## Telemetry Sink (Structured Correlation)

For a **single binding** that receives normalized correlation payloads (including
`schema_version`, `category`, and `occurred_at`), bind `SwarmTelemetrySink`. The
package subscribes a listener that mirrors lifecycle events and package queue
job boundaries, and emits per-event stream/broadcast telemetry from the runtime.
See [Observability Correlation Contract](observability-correlation-contract.md)
for categories, redaction rules, and configuration (`swarm.observability.*`).

## Queue And Job Context

Queued swarms run inside normal Laravel queue jobs owned by this package:
`InvokeSwarm`, `BroadcastSwarm`, `AdvanceDurableSwarm`, `AdvanceDurableBranch`,
and `ResumeQueuedHierarchicalSwarm`. To attach **queue** metadata to the same log
lines, combine Swarm events with Laravel’s queue events, for example
`Illuminate\Queue\Events\JobProcessing`, and merge `job->uuid()` (or your broker’s
id) into your logging context for the duration of the job. When using the
default `SwarmTelemetrySink` binding, `job.started` / `job.completed` /
`job.failed` telemetry records already carry `run_id`, `job_class`, queue
connection, and queue name for these job classes.

If you use **Laravel Horizon**, configure tags or metadata that include
`swarm_run_id` from your job payload or from the first `SwarmStarted` event you
see in that process.

## Durable Runs Across Processes

`dispatchDurable()` advances through multiple worker processes. The shared key
remains **`run_id`**: log it on every durable step boundary you care about
(`SwarmStepStarted`, `SwarmProgressRecorded`, `SwarmWaiting`, job processing).
Use `SwarmHistory`, `swarm:status`, and durable tables for ground truth when
reconciling logs across hosts.

## Pulse

For **aggregate** throughput and latency (not per-run log correlation), enable
the package Pulse recorders described in [Pulse](pulse.md).

## First-Class OpenTelemetry

A dedicated integration package could ship opinionated span boundaries and
semantic conventions. Core Swarm intentionally stays free of tracing SDKs so
applications choose versions and exporters.
