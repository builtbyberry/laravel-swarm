# Lifecycle Events

Laravel Swarm fires standard Laravel events at key points in every run. Listen to them with `EventServiceProvider` or closures. They are the primary way to hook into swarm execution for dashboards, notifications, and audit trails — the package intentionally does not ship its own dashboard layer, leaving your application in full control of how run data is surfaced.

## How Events Fit Into Laravel

Swarm events are plain PHP objects dispatched through Laravel's event system. Register listeners exactly as you would for any application event:

```php
// app/Providers/EventServiceProvider.php

use App\Listeners\RunTracker;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;

protected $listen = [
    SwarmStarted::class   => [RunTracker::class],
    SwarmCompleted::class => [RunTracker::class],
    SwarmFailed::class    => [RunTracker::class],
];
```

Closures registered in a service provider also work:

```php
// app/Providers/AppServiceProvider.php

use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmStarted::class, function (SwarmStarted $event): void {
    logger()->info('Swarm started', ['run_id' => $event->runId]);
});
```

**Dispatch context:**

- For `prompt()` and `stream()` runs, events are dispatched synchronously in the HTTP request or CLI context.
- For `queue()` and `dispatchDurable()` runs, events are dispatched within the queue worker process that executes the job. Your listeners run inside that worker, not in the originating request.

## Firing Guarantees

Events fire **after** the corresponding history write succeeds. If a history write fails — for example because the database is unavailable — the event will not fire even though the underlying operation may have completed.

Events are **not** fired if the swarm never enters the runner. A run that fails input validation before execution starts produces no lifecycle events.

## Ordering Contract

The following ordering guarantees hold within a single run:

- `SwarmStarted` always fires before `SwarmCompleted` or `SwarmFailed`.
- `SwarmStepStarted` always fires before `SwarmStepCompleted` for the same step index.
- There is no ordering guarantee between events from **different** runs.
- For durable runs: events fire in the worker context, so if jobs execute across multiple workers, events from one run may arrive interleaved with events from another. Use the `runId` field to correlate events that belong together. See [Run ID as Correlation Anchor](#run-id-as-correlation-anchor).

## Full Event Catalog

All event classes live under the `BuiltByBerry\LaravelSwarm\Events` namespace.

---

### `SwarmStarted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmStarted`

**When it fires:** immediately after the swarm runner records the start of a run, before any agent step executes.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Stable identifier for this run. Use it to correlate all subsequent events. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string: `sequential`, `parallel`, or `hierarchical`. |
| `$input` | `string` | The string representation of the task passed to the swarm. |
| `$metadata` | `array<string, mixed>` | Arbitrary key/value pairs attached to the run context. |
| `$executionMode` | `string\|null` | Execution mode: `run`, `queue`, `stream`, or `durable`. Synchronous `prompt()` uses `run`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;

Event::listen(SwarmStarted::class, function (SwarmStarted $event): void {
    RunTracker::open(
        runId: $event->runId,
        swarm: $event->swarmClass,
        topology: $event->topology,
        mode: $event->executionMode,
    );
});
```

---

### `SwarmCompleted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmCompleted`

**When it fires:** after the swarm runner records a successful completion, once all steps have finished without error.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$output` | `string` | Final output text produced by the swarm. |
| `$durationMs` | `int` | Wall-clock duration of the run in milliseconds. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$artifacts` | `array<int, SwarmArtifact>` | Any artifacts captured during the run. Each `SwarmArtifact` has `name`, `content`, `metadata`, and `stepAgentClass`. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    RunTracker::close(
        runId: $event->runId,
        durationMs: $event->durationMs,
        artifactCount: count($event->artifacts),
    );
});
```

---

### `SwarmFailed`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmFailed`

**When it fires:** after the swarm runner records a failure due to an unhandled exception during orchestration.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$exception` | `Throwable` | The exception that caused the failure. |
| `$exceptionClass` | `string` | Class name of the exception (readonly, set in the constructor). Useful when the full `Throwable` cannot be serialized or logged directly. |
| `$durationMs` | `int` | Duration until failure in milliseconds. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    logger()->error('Swarm failed', [
        'run_id'          => $event->runId,
        'exception_class' => $event->exceptionClass,
        'message'         => $event->exception->getMessage(),
        'duration_ms'     => $event->durationMs,
    ]);

    NotificationService::alertOnCall($event->runId, $event->exception);
});
```

---

### `SwarmStepStarted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted`

**When it fires:** immediately before a single agent step executes, after the runner records the step start.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$index` | `int` | Zero-based position of this step in the run's step sequence. |
| `$agentClass` | `string` | Fully qualified class name of the agent being invoked. |
| `$input` | `string` | Input passed to this agent step. |
| `$metadata` | `array<string, mixed>` | Run context metadata at the time of step start. |
| `$topology` | `string\|null` | Topology string. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;

Event::listen(SwarmStepStarted::class, function (SwarmStepStarted $event): void {
    logger()->debug('Agent step starting', [
        'run_id'      => $event->runId,
        'step_index'  => $event->index,
        'agent_class' => $event->agentClass,
    ]);
});
```

---

### `SwarmStepCompleted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted`

**When it fires:** after a single agent step finishes successfully and its result is recorded.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$index` | `int` | Zero-based step index. |
| `$agentClass` | `string` | Fully qualified class name of the agent. |
| `$input` | `string` | Input that was given to the agent. |
| `$output` | `string` | Output returned by the agent. |
| `$durationMs` | `int` | Duration of this step in milliseconds. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$artifacts` | `array<int, SwarmArtifact>` | Artifacts produced during this step. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;

Event::listen(SwarmStepCompleted::class, function (SwarmStepCompleted $event): void {
    StepMetricsRecorder::record(
        runId: $event->runId,
        stepIndex: $event->index,
        agent: $event->agentClass,
        durationMs: $event->durationMs,
    );
});
```

---

### `SwarmPaused`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmPaused`

**When it fires:** after a durable run is successfully paused (via `swarm:pause` or the `DurableSwarmManager`).

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;

Event::listen(SwarmPaused::class, function (SwarmPaused $event): void {
    AuditLog::write('swarm.paused', [
        'run_id' => $event->runId,
        'swarm'  => $event->swarmClass,
    ]);
});
```

---

### `SwarmResumed`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmResumed`

**When it fires:** after a paused durable run is successfully resumed (via `swarm:resume` or the `DurableSwarmManager`).

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;

Event::listen(SwarmResumed::class, function (SwarmResumed $event): void {
    AuditLog::write('swarm.resumed', [
        'run_id' => $event->runId,
        'swarm'  => $event->swarmClass,
    ]);
});
```

---

### `SwarmCancelled`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmCancelled`

**When it fires:** after a run is successfully cancelled (via `swarm:cancel` or the `DurableSwarmManager`). This is a terminal event — no further events will fire for this run.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$executionMode` | `string\|null` | Execution mode. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;

Event::listen(SwarmCancelled::class, function (SwarmCancelled $event): void {
    RunTracker::markCancelled($event->runId);
});
```

---

### `SwarmWaiting`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmWaiting`

**When it fires:** when a durable run enters a wait state (waiting for an external signal or webhook). The run remains in a waiting state until it is signalled, cancelled, or the wait times out.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$waitName` | `string` | The named wait point declared in the swarm. |
| `$reason` | `string\|null` | Optional human-readable reason the run is waiting. |
| `$metadata` | `array<string, mixed>` | Run context metadata. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmWaiting;

Event::listen(SwarmWaiting::class, function (SwarmWaiting $event): void {
    logger()->info('Swarm waiting for external input', [
        'run_id'    => $event->runId,
        'wait_name' => $event->waitName,
        'reason'    => $event->reason,
    ]);
});
```

---

### `SwarmWaitTimedOut`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut`

**When it fires:** when a named wait point expires without receiving a signal. The run typically transitions to a failed or cancelled state after this event.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$waitName` | `string` | The named wait point that timed out. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmWaitTimedOut;

Event::listen(SwarmWaitTimedOut::class, function (SwarmWaitTimedOut $event): void {
    NotificationService::alertTimeout(
        runId: $event->runId,
        waitName: $event->waitName,
    );
});
```

---

### `SwarmSignalled`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmSignalled`

**When it fires:** when an external signal is delivered to a waiting durable run. The `accepted` property tells you whether the signal was recognized and applied.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$swarmClass` | `string` | Fully qualified class name of the swarm. |
| `$topology` | `string` | Topology string. |
| `$signalName` | `string` | The name of the signal that was sent. |
| `$accepted` | `bool` | `true` if the run accepted and applied the signal; `false` if it was rejected (e.g., the run was not in a waiting state). |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;

Event::listen(SwarmSignalled::class, function (SwarmSignalled $event): void {
    AuditLog::write('swarm.signalled', [
        'run_id'      => $event->runId,
        'signal_name' => $event->signalName,
        'accepted'    => $event->accepted,
    ]);
});
```

---

### `SwarmProgressRecorded`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmProgressRecorded`

**When it fires:** when a durable run records a progress checkpoint. Fires for both the main run and for individual branches in a hierarchical run.

| Property | Type | Description |
|---|---|---|
| `$runId` | `string` | Run identifier. |
| `$branchId` | `string\|null` | Branch identifier for hierarchical runs. `null` for the main run. |
| `$progress` | `array<string, mixed>` | The progress data recorded at this checkpoint. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |
| `$swarmClass` | `string\|null` | Fully qualified class name of the swarm. |
| `$topology` | `string\|null` | Topology string. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmProgressRecorded;

Event::listen(SwarmProgressRecorded::class, function (SwarmProgressRecorded $event): void {
    DurableDashboard::updateProgress(
        runId: $event->runId,
        branchId: $event->branchId,
        progress: $event->progress,
    );
});
```

---

### `SwarmChildStarted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted`

**When it fires:** when a durable parent run dispatches a child swarm. Provides the link between parent and child run identifiers.

| Property | Type | Description |
|---|---|---|
| `$parentRunId` | `string` | Run identifier of the parent swarm that spawned the child. |
| `$childRunId` | `string` | Run identifier assigned to the child swarm. |
| `$childSwarmClass` | `string` | Fully qualified class name of the child swarm. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted;

Event::listen(SwarmChildStarted::class, function (SwarmChildStarted $event): void {
    RunTracker::linkChild(
        parentRunId: $event->parentRunId,
        childRunId: $event->childRunId,
        childSwarm: $event->childSwarmClass,
    );
});
```

---

### `SwarmChildCompleted`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted`

**When it fires:** when a durable child swarm completes successfully. The `SwarmCompleted` event for the child run fires separately.

| Property | Type | Description |
|---|---|---|
| `$parentRunId` | `string` | Run identifier of the parent swarm. |
| `$childRunId` | `string` | Run identifier of the child swarm that completed. |
| `$childSwarmClass` | `string` | Fully qualified class name of the child swarm. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmChildCompleted;

Event::listen(SwarmChildCompleted::class, function (SwarmChildCompleted $event): void {
    RunTracker::childCompleted(
        parentRunId: $event->parentRunId,
        childRunId: $event->childRunId,
    );
});
```

---

### `SwarmChildFailed`

**Full class:** `BuiltByBerry\LaravelSwarm\Events\SwarmChildFailed`

**When it fires:** when a durable child swarm fails. The `failure` property carries structured failure information if available.

| Property | Type | Description |
|---|---|---|
| `$parentRunId` | `string` | Run identifier of the parent swarm. |
| `$childRunId` | `string` | Run identifier of the child swarm that failed. |
| `$childSwarmClass` | `string` | Fully qualified class name of the child swarm. |
| `$failure` | `array<string, mixed>\|null` | Structured failure data. `null` if no failure detail was captured. |
| `$executionMode` | `string` | Execution mode. Defaults to `durable`. |

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmChildFailed;

Event::listen(SwarmChildFailed::class, function (SwarmChildFailed $event): void {
    logger()->error('Child swarm failed', [
        'parent_run_id' => $event->parentRunId,
        'child_run_id'  => $event->childRunId,
        'child_swarm'   => $event->childSwarmClass,
        'failure'       => $event->failure,
    ]);
});
```

---

## Memory Events

Swarm Memory dispatches events through Laravel's event system when memory operations occur. The events below are dispatched at the **store layer**: `MemoryWritten` / `MemoryRead` / `MemoryForgotten` fire from `DatabaseMemoryStore` and `CacheMemoryStore` directly (custom `MemoryStore` drivers must dispatch them from their own `put()`, `get()`, and `forget()` implementations to keep the listener contract uniform), `MemorySnapshotted` fires from the snapshot recorder, and the write-time redaction events `MemoryRedacted` / `MemoryWriteSkipped` fire from the `RedactingMemoryStore` decorator (v0.10.0+). The operator commands dispatch a separate set of events — see [Memory operator-command events](#memory-operator-command-events).

| Event | Full class | When | Key properties |
| --- | --- | --- | --- |
| `MemoryWritten` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten` | After a successful `put()` | `scope`, `scopeId`, `key`, `metadata`, `bytes` |
| `MemoryRedacted` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRedacted` | After a `MemoryCapturePolicy` `Redact` decision rewrites a write (v0.10.0+) | `scope`, `scopeId`, `key` (address only — no value) |
| `MemoryWriteSkipped` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWriteSkipped` | After a `MemoryCapturePolicy` `Skip` decision drops a write (v0.10.0+) | `scope`, `scopeId`, `key` (address only — no value) |
| `MemoryRead` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead` | After every `get()`, hit or miss | `scope`, `scopeId`, `key`, `hit` |
| `MemoryForgotten` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten` | After every `forget()` | `scope`, `scopeId`, `key`, `existed` |
| `MemorySnapshotted` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemorySnapshotted` | After a per-step snapshot is captured | `runId`, `stepIndex`, `snapshotId`, `bytes`, `entryCount` |
| `MemoryScopeOutOfSnapshot` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryScopeOutOfSnapshot` | During replay when a read targets a non-Run scope | `runId`, `stepIndex`, `scope`, `scopeId`, `key`, `operation` |

`MemoryRedacted` and `MemoryWriteSkipped` carry the entry **address only** (`scope`, `scopeId`, `key`) and never the value, so an audit listener gets positive proof that redaction or a skip happened without re-exposing the data the policy just removed. A `Skip` writes no row and fires no `MemoryWritten`; the default `DefaultMemoryCapturePolicy` (`Full` for every write) fires neither event. See [Capture policy](memory.md#capture-policy-write-time-redaction).

`MemoryRead` intentionally does not expose the entry value. Listeners that need the value should re-read through the store under their own access controls — this keeps the event surface compatible with capture-policy redaction in v0.10. The `hit` boolean reports whether the underlying lookup returned a stored entry (`true`) or missed (`false`); the bundled stores set it from the store result. Third-party drivers that have not been updated leave it at the conservative default `false` — listeners should treat the field as advisory in that case.

`MemoryWritten` carries an optional `bytes` field with the approximate JSON-encoded byte size of the persisted `value` + `metadata`. The bundled stores populate it at write time; third-party drivers leave it `null`. Treat it as a sampling input, not the row's database footprint.

`MemorySnapshotted` carries optional `bytes` and `entryCount` fields measured at snapshot persistence time. The bundled `DatabaseMemorySnapshotRecorder` populates both; third-party drivers leave them `null`.

`MemoryForgotten` includes an `existed` boolean so listeners can distinguish a real deletion from a no-op probe (a `forget()` call on a key that was never set).

`MemoryScopeOutOfSnapshot` fires during `frozen_view` replay when a read targets a Conversation, Agent, or Swarm scoped entry (only Run scope is snapshotted in v0.9.0). Without a listener the read falls through to the live store; no persistent record is created by default. Wire a listener for compliance postures that require a record of every cross-scope read during replay.

### Memory operator-command events

The memory operator commands (v0.10.0+) dispatch their own events when an operator inspects, exports, or purges memory. Unlike the store-layer events above, these fire from the Artisan commands and pair with the `command.memory.*` audit categories the same commands emit. Each is dispatched on a **successful** invocation only.

| Event | Full class | When | Key properties |
| --- | --- | --- | --- |
| `MemoryInspected` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryInspected` | After `swarm:memory:inspect` reads snapshots | `runId`, `stepIndex`, `scopeFilter`, `format`, `snapshotCount` |
| `MemoryDumped` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryDumped` | After `swarm:memory:dump` exports a run/conversation | `subjectType`, `subjectId`, `format`, `includeSnapshots`, `entryCount`, `snapshotCount`, `runsExpanded` |
| `MemoryPurged` | `BuiltByBerry\LaravelSwarm\Events\Memory\MemoryPurged` | After `swarm:memory:purge` runs (including dry-run and `prevent_prune` skips) | `counts` (per-scope), `criteria` (retention windows, scope filter, flags, cutoffs) |

`MemoryInspected` and `MemoryDumped` are read/export events: they record that an operator viewed or extracted frozen memory, complementing the `command.memory.inspect` / `command.memory.dump` audit egress records. `MemoryPurged` fires on every purge outcome — a real delete, a `--dry-run` preview, or a `prevent_prune` suppression (with `criteria.prevent_prune = true` and zeroed `counts`) — so a retention pipeline sees each scheduled run. See [Compliance & Audit](compliance-audit.md).

For full listener examples and the compliance hard-fail pattern, see [Swarm Memory](memory.md#lifecycle-events).

---

## Common Patterns

### Dashboard Run Tracking

Listen to the three core terminal events to maintain a `swarm_runs` table that drives a dashboard:

```php
// app/Listeners/RunTracker.php

namespace App\Listeners;

use App\Models\SwarmRun;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;

class RunTracker
{
    public function handleStarted(SwarmStarted $event): void
    {
        SwarmRun::create([
            'run_id'         => $event->runId,
            'swarm_class'    => $event->swarmClass,
            'topology'       => $event->topology,
            'execution_mode' => $event->executionMode,
            'status'         => 'running',
            'started_at'     => now(),
        ]);
    }

    public function handleCompleted(SwarmCompleted $event): void
    {
        SwarmRun::where('run_id', $event->runId)->update([
            'status'       => 'completed',
            'duration_ms'  => $event->durationMs,
            'completed_at' => now(),
        ]);
    }

    public function handleFailed(SwarmFailed $event): void
    {
        SwarmRun::where('run_id', $event->runId)->update([
            'status'          => 'failed',
            'duration_ms'     => $event->durationMs,
            'exception_class' => $event->exceptionClass,
            'failed_at'       => now(),
        ]);
    }
}
```

Register all three methods in `EventServiceProvider`:

```php
protected $listen = [
    SwarmStarted::class   => [RunTracker::class . '@handleStarted'],
    SwarmCompleted::class => [RunTracker::class . '@handleCompleted'],
    SwarmFailed::class    => [RunTracker::class . '@handleFailed'],
];
```

---

### Email Notification on Completion

```php
// app/Listeners/NotifyOnCompletion.php

namespace App\Listeners;

use App\Mail\SwarmCompletedMail;
use App\Models\User;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use Illuminate\Support\Facades\Mail;

class NotifyOnCompletion
{
    public function handle(SwarmCompleted $event): void
    {
        // Only notify for specific swarms
        if ($event->swarmClass !== \App\Ai\Swarms\ContentPipeline::class) {
            return;
        }

        $userId = $event->metadata['user_id'] ?? null;

        if ($userId && $user = User::find($userId)) {
            Mail::to($user)->queue(new SwarmCompletedMail(
                runId: $event->runId,
                output: $event->output,
                durationMs: $event->durationMs,
            ));
        }
    }
}
```

---

### Audit Trail

Listen to all events and write structured records to an audit log. Keep listeners cheap — heavy persistence or third-party calls belong in a queued listener of your own.

```php
// app/Listeners/SwarmAuditLogger.php

namespace App\Listeners;

use App\Models\SwarmAuditEntry;
use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmSignalled;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;

class SwarmAuditLogger
{
    public function handleStarted(SwarmStarted $event): void
    {
        $this->write($event->runId, 'started', [
            'swarm'    => $event->swarmClass,
            'topology' => $event->topology,
            'mode'     => $event->executionMode,
        ]);
    }

    public function handleStepStarted(SwarmStepStarted $event): void
    {
        $this->write($event->runId, 'step_started', [
            'index' => $event->index,
            'agent' => $event->agentClass,
        ]);
    }

    public function handleStepCompleted(SwarmStepCompleted $event): void
    {
        $this->write($event->runId, 'step_completed', [
            'index'       => $event->index,
            'agent'       => $event->agentClass,
            'duration_ms' => $event->durationMs,
        ]);
    }

    public function handleCompleted(SwarmCompleted $event): void
    {
        $this->write($event->runId, 'completed', [
            'duration_ms'    => $event->durationMs,
            'artifact_count' => count($event->artifacts),
        ]);
    }

    public function handleFailed(SwarmFailed $event): void
    {
        $this->write($event->runId, 'failed', [
            'exception_class' => $event->exceptionClass,
            'message'         => $event->exception->getMessage(),
            'duration_ms'     => $event->durationMs,
        ]);
    }

    public function handlePaused(SwarmPaused $event): void
    {
        $this->write($event->runId, 'paused', []);
    }

    public function handleResumed(SwarmResumed $event): void
    {
        $this->write($event->runId, 'resumed', []);
    }

    public function handleCancelled(SwarmCancelled $event): void
    {
        $this->write($event->runId, 'cancelled', []);
    }

    public function handleSignalled(SwarmSignalled $event): void
    {
        $this->write($event->runId, 'signalled', [
            'signal_name' => $event->signalName,
            'accepted'    => $event->accepted,
        ]);
    }

    private function write(string $runId, string $action, array $context): void
    {
        SwarmAuditEntry::create([
            'run_id'    => $runId,
            'action'    => $action,
            'context'   => $context,
            'logged_at' => now(),
        ]);
    }
}
```

Register each method in `EventServiceProvider`:

```php
protected $listen = [
    SwarmStarted::class       => [SwarmAuditLogger::class . '@handleStarted'],
    SwarmStepStarted::class   => [SwarmAuditLogger::class . '@handleStepStarted'],
    SwarmStepCompleted::class => [SwarmAuditLogger::class . '@handleStepCompleted'],
    SwarmCompleted::class     => [SwarmAuditLogger::class . '@handleCompleted'],
    SwarmFailed::class        => [SwarmAuditLogger::class . '@handleFailed'],
    SwarmPaused::class        => [SwarmAuditLogger::class . '@handlePaused'],
    SwarmResumed::class       => [SwarmAuditLogger::class . '@handleResumed'],
    SwarmCancelled::class     => [SwarmAuditLogger::class . '@handleCancelled'],
    SwarmSignalled::class     => [SwarmAuditLogger::class . '@handleSignalled'],
];
```

## Run ID as Correlation Anchor

Every event carries a `runId` (or `parentRunId`/`childRunId` for child events). This is the stable identifier across all persistence layers — run history tables, durable run tables, Artisan commands, and your own application records.

Use `runId` as the join key whenever you aggregate data across events:

```php
// Fetch all audit entries for one run
SwarmAuditEntry::where('run_id', $runId)->orderBy('logged_at')->get();

// Inspect the run via SwarmHistory
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;

$history = SwarmHistory::find($runId);
```

For durable runs, events may fire across different queue workers and arrive out of wall-clock order relative to events from other runs. The `runId` lets you reconstruct the logical sequence even when physical delivery is interleaved. `SwarmStepCompleted` events carry an `index` field that gives you the deterministic step order for a single run regardless of worker timing.

For child swarms, use `parentRunId` to walk the parent-child tree:

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmChildStarted;

Event::listen(SwarmChildStarted::class, function (SwarmChildStarted $event): void {
    // Store the parent→child link so you can build a full run tree
    RunGraph::addEdge(
        from: $event->parentRunId,
        to: $event->childRunId,
    );
});
```

See [Observability: Logging and Tracing](observability-logging-tracing.md) for a full correlation field reference and structured-log examples.

## Testing Events

Use the `InteractsWithSwarmEvents` trait and `assertEventFired()` to verify that lifecycle events fired during a real (non-faked) run. `SwarmFake` bypasses the runner entirely and does not fire lifecycle events — use real execution when you need event assertions.

```php
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;
use Tests\TestCase;

class ArticlePipelineEventTest extends TestCase
{
    use InteractsWithSwarmEvents;

    public function test_fires_started_and_completed_events(): void
    {
        ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.');

        ArticlePipeline::assertEventFired(SwarmStarted::class);
        ArticlePipeline::assertEventFired(SwarmCompleted::class);
    }

    public function test_started_event_carries_correct_execution_mode(): void
    {
        ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.');

        ArticlePipeline::assertEventFired(
            SwarmStarted::class,
            fn ($event) => $event->executionMode === 'run',
        );
    }

    public function test_step_events_carry_agent_class(): void
    {
        ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.');

        ArticlePipeline::assertEventFired(
            SwarmStepCompleted::class,
            fn ($event) => $event->agentClass === OutlineAgent::class
                && $event->index === 0,
        );
    }

    public function test_completed_event_includes_duration(): void
    {
        ArticlePipeline::make()->prompt('Draft a blog outline about Laravel queues.');

        ArticlePipeline::assertEventFired(
            SwarmCompleted::class,
            fn ($event) => $event->durationMs > 0,
        );
    }
}
```

The `InteractsWithSwarmEvents` trait activates the event recorder automatically and resets it between tests. `assertEventFired()` fails with a clear message if the recorder has not been activated or if no matching event was captured.

For the full assertion API — including the callable form and interaction with `SwarmFake` — see [Testing](testing.md).
