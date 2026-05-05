# Observability Correlation Contract

Laravel Swarm emits **structured telemetry** for operators who need consistent
`run_id` correlation across sync runs, queue workers, durable steps, and stream
boundaries. Telemetry is routed through the `SwarmTelemetrySink` contract, which
defaults to a no-op implementation. Applications bind a custom sink to forward
records to logs, metrics, APM, or tracing adapters **without** adding OpenTelemetry
or other vendor SDKs to the core package.

## Operational Telemetry vs Audit Evidence

- **Audit evidence** (`SwarmAuditSink`) is optimized for compliance-style signals:
  operator commands, webhook idempotency, prune outcomes, durable checkpoint
  internals, and similar categories. See [Audit Evidence Contract](audit-evidence-contract.md).
- **Observability telemetry** (`SwarmTelemetrySink`) is optimized for **runtime
  correlation**: lifecycle mirrors, package queue job boundaries, stream and
  broadcast event cadence, and durable wait/signal/progress signals that help
  operators stitch logs and traces across processes.

The two sinks share the same **envelope shape** (`schema_version`, `category`,
`occurred_at`) via an internal helper, but they are **bound and configured
independently** (`swarm.audit.*` vs `swarm.observability.*`). Sink failures never
propagate into swarm execution.

## Enabling And Configuring

Bind your implementation in a service provider:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

$this->app->bind(SwarmTelemetrySink::class, MyAppTelemetrySink::class);
```

### Config Keys

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `swarm.observability.enabled` | `SWARM_OBSERVABILITY_ENABLED` | `true` | Master switch: when `false`, `SwarmTelemetryDispatcher::emit` is a no-op. |
| `swarm.observability.listen_to_events` | `SWARM_OBSERVABILITY_LISTEN_EVENTS` | `true` | When `false`, the package does not subscribe `SwarmTelemetryEventListener` to lifecycle or queue events. Direct `stream.event` / `broadcast.event` hooks still respect `enabled`. |
| `swarm.observability.failure_policy` | `SWARM_OBSERVABILITY_FAILURE_POLICY` | `swallow` | `swallow` or `log` when the sink throws. |
| `swarm.observability.metadata_allowlist` | `SWARM_OBSERVABILITY_METADATA_ALLOWLIST` | `[]` | Comma-separated allowlist of top-level metadata keys whose values may appear in telemetry payloads (same pattern as audit). |
| `swarm.observability.categories.include` | — | `null` | When a non-empty array, only these categories are emitted. |
| `swarm.observability.categories.exclude` | — | `null` | When a non-empty array, listed categories are suppressed. |

## Payload Envelope

Every record includes:

| Field | Notes |
| --- | --- |
| `schema_version` | `"1"` for the initial telemetry schema. |
| `category` | Telemetry category (see below). |
| `occurred_at` | ISO-8601 timestamp. |

Telemetry **does not** embed raw prompts, agent outputs, or exception messages.
Lifecycle-derived payloads use `metadata_keys` plus allowlisted `metadata` only.
`SwarmFailed` telemetry carries `exception_class` but not the `Throwable` message.
`SwarmChildFailed` telemetry carries `failure_keys` (key names only), not nested
failure payloads. `SwarmProgressRecorded` telemetry carries `progress_keys`, not
progress values.

## Categories (schema v1)

### Listener-driven (lifecycle + queue)

Emitted by `SwarmTelemetryEventListener` when `listen_to_events` is `true`:

| Category | Source |
| --- | --- |
| `run.started` / `run.completed` / `run.failed` | `SwarmStarted`, `SwarmCompleted`, `SwarmFailed` |
| `step.started` / `step.completed` | `SwarmStepStarted`, `SwarmStepCompleted` |
| `durable.paused` / `durable.resumed` / `durable.cancelled` | `SwarmPaused`, `SwarmResumed`, `SwarmCancelled` |
| `wait.started` / `wait.timed_out` | `SwarmWaiting`, `SwarmWaitTimedOut` |
| `signal.received` | `SwarmSignalled` |
| `progress.recorded` | `SwarmProgressRecorded` (`progress_keys` only) |
| `child.started` / `child.completed` / `child.failed` | Child lifecycle events |
| `job.started` / `job.completed` / `job.failed` | Laravel queue events for package jobs only (see below) |

### Direct hooks (stream / broadcast)

| Category | Source |
| --- | --- |
| `stream.event` | `SequentialStreamRunner` — one record per typed `SwarmStreamEvent` (`event_type`, `sequence_index`, `duration_ms` since stream start, `is_replay`). |
| `broadcast.event` | `BroadcastSwarm` job — same shape plus `channel_names`. |

### Package queue jobs (listener filter)

Only these job classes produce `job.*` telemetry:

- `BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm`
- `BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm`
- `BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm`
- `BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch`
- `BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm`

Payloads include `job_class`, `job_id`, `attempt`, `queue_connection`,
`queue_name`, and `run_id` (resolved from the job payload or durable run row for
advance jobs).

Package jobs emit first-class worker-attempt timing from inside their `handle()`
methods:

| Field | Categories | Meaning |
| --- | --- | --- |
| `duration_ms` | `job.completed`, `job.failed` | Required for package handler attempts. Measures only the current worker execution attempt, not queue wait time and not previous retries. |
| `queue_wait_ms` | `job.started`, `job.completed`, `job.failed` | Nullable. Measures package enqueue timestamp to worker start when the timestamp is available. |
| `total_elapsed_ms` | `job.completed`, `job.failed` | Nullable. Measures package enqueue timestamp to terminal event for this attempt when the timestamp is available. |
| `attempt` | all `job.*` | Queue attempt number when the worker exposes it; direct handler calls report `1`. |
| `job_id` | all `job.*` | Queue UUID or broker job id when available. |

Retries are attempt-scoped: each attempt emits its own `job.started` and terminal
`job.completed` or `job.failed` record, and `duration_ms` always measures only
that worker attempt. By contrast, `queue_wait_ms` and `total_elapsed_ms` are
measured from the package job's stored enqueue or release timestamp. On retries,
those fields therefore represent time since the current serialized job instance
was enqueued or released, not a broker-native "time waiting for this exact
attempt only" metric. Durable advance jobs may represent a single checkpointed
step or branch, so job timing should not be interpreted as whole-run durable
duration. Use `run.*` lifecycle telemetry and durable history for whole-run
timing.

The Laravel queue failure listener remains subscribed as a fallback for package
job failures that occur before a handler can emit telemetry. In that fallback,
`duration_ms` is `null`; normal package handler failures emit non-null
`duration_ms` and suppress the fallback duplicate.

### Intentionally omitted (audit-only)

These remain **audit** categories only (lower noise for tracing backends):

`command.*`, `webhook.*`, `durable.checkpointed*`, `durable.completed`,
`durable.failed`, `durable.pause_requested`, `durable.cancel_requested`,
`wait.created` (durable wait registration — use audit or durable tables for
compliance evidence).

## OpenTelemetry Adapter Sketch

Core Swarm stays SDK-free. A typical adapter binds `SwarmTelemetrySink` and maps
flat categories to spans or structured logs:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;

final class OtelSwarmTelemetrySink implements SwarmTelemetrySink
{
    public function emit(string $category, array $payload): void
    {
        // Example: start or annotate a span using run_id as a span attribute.
        // Never throw — the dispatcher already isolates failures, but sinks
        // should still be defensive.
        $runId = $payload['run_id'] ?? null;
        // ...
    }
}
```

Use `run_id` as the primary correlation attribute; add `parent_run_id` and
`child_run_id` when present for nested durable runs.

For queue jobs, map `job.started` to a worker-attempt span start. Use
`duration_ms` for worker execution metrics, `queue_wait_ms` for queue saturation
alerts, and `total_elapsed_ms` for end-to-end package job latency.

## Related Documentation

- [Observability: Logging And Tracing](observability-logging-tracing.md) — Laravel
  events, queue context, and Pulse.
- [Audit Evidence Contract](audit-evidence-contract.md) — compliance-oriented
  evidence surface (separate sink).
