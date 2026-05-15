## Minimal Setup

Get structured logs, Pulse metrics, and OpenTelemetry-ready tracing spans in about 10 minutes.

**Step 1 — Enable observability**

Tell the package to route telemetry records (it defaults to `true`, so this is only needed if you previously turned it off):

```
SWARM_OBSERVABILITY_ENABLED=true
```

**Step 2 — Bind a telemetry sink**

In `AppServiceProvider::register()`, replace the default `NoOpSwarmTelemetrySink` with your own implementation of `SwarmTelemetrySink`. The single `emit()` method receives every lifecycle and queue-job event as a normalized payload with `schema_version`, `category`, `occurred_at`, and correlation fields.

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

$this->app->bind(SwarmTelemetrySink::class, function () {
    return new AppSwarmTelemetrySink(); // your class implementing SwarmTelemetrySink
});
```

The package ships `NoOpSwarmTelemetrySink` as the default — nothing is emitted until you bind a real sink. See the [Queue And Job Context](#queue-and-job-context) section below for a minimal `AppSwarmTelemetrySink` example, and the [Observability Correlation Contract](observability-correlation-contract.md) for the full category list and payload schema.

**Step 3 — Register Pulse recorders** (if Pulse is installed)

Add the recorders to the `recorders` array in `config/pulse.php` so the Swarm cards appear on your Pulse dashboard:

```php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;

'recorders' => [
    SwarmRuns::class          => ['enabled' => env('PULSE_SWARM_RUNS_ENABLED', true)],
    SwarmStepDurations::class => ['enabled' => env('PULSE_SWARM_STEP_DURATIONS_ENABLED', true)],
],
```

See [Pulse](pulse.md) for card setup and configuration options.

**What you get after these three steps:** your `SwarmTelemetrySink` receives a structured record for every swarm lifecycle event and package queue-job boundary; the Pulse dashboard shows run totals, failure rates, topology usage, and step durations; and the correlation fields (`run_id`, `swarm_class`, `topology`, `execution_mode`) are ready to attach to any OpenTelemetry span you open in your sink.

---

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
connection, queue name, attempt, job id, and timing fields for these job classes.

In a custom `SwarmTelemetrySink`, queue categories can be mapped directly to
worker-attempt spans and metrics:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

final class AppSwarmTelemetrySink implements SwarmTelemetrySink
{
    public function emit(string $category, array $payload): void
    {
        if ($category === 'job.started') {
            // Open or annotate a worker-attempt span keyed by run_id/job_id.
            // queue_wait_ms is a queue saturation signal.
            return;
        }

        if ($category === 'job.completed' || $category === 'job.failed') {
            // duration_ms is worker execution time for this attempt.
            // total_elapsed_ms is enqueue-to-terminal latency when available.
            // exception_class is present for failed attempts.
            return;
        }
    }
}
```

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
