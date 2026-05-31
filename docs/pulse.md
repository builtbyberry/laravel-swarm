# Pulse

Laravel Swarm integrates with [Laravel Pulse](https://laravel.com/docs/pulse) through optional recorders and cards. If your application already uses Pulse, you can add swarm-level observability without changing how your swarms run.

## Quick Setup

Run the bundled installer once Pulse itself is installed:

```bash
composer require laravel/pulse

php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"

php artisan migrate

php artisan swarm:install:pulse
```

`swarm:install:pulse` is also dispatched automatically by the broader
[`swarm:install`](./getting-started.md) entry point when `laravel/pulse`
is detected — if you are setting up the package for the first time, start
there and let it offer the Pulse wiring as one step in the full install.
Use the targeted command directly when you are adding Pulse to an
application that already has Laravel Swarm installed.

`swarm:install:pulse` does the two file edits manually documented below — it
registers the recorders in `config/pulse.php` and injects the swarm cards
into `resources/views/vendor/pulse/dashboard.blade.php`. Both edits are
fenced with sentinel comments and are safe to re-run; the original files
are copied to `<file>.bak` before the first mutation.

Pick which cards to enable with `--cards` (default: all three):

```bash
php artisan swarm:install:pulse --no-interaction --cards=runs,steps
```

Re-run with `--force` to rewrite the managed blocks after you change the
selected cards.

If Pulse is not installed, the command refuses with a copy-paste hint and
exits non-zero. The rest of this page documents the same edits by hand for
operators who prefer (or whose host app blocks) installers.

## Install Pulse

Install and publish Pulse in your application first:

```bash
composer require laravel/pulse

php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"

php artisan migrate
```

Once Pulse is installed, Laravel Swarm will register its Pulse cards automatically.

## Register The Recorders

Add the swarm recorders to the `recorders` array in `config/pulse.php`:

```php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmMemoryMetrics;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;

'recorders' => [
    // ...

    SwarmRuns::class => [
        'enabled' => env('PULSE_SWARM_RUNS_ENABLED', true),
    ],

    SwarmStepDurations::class => [
        'enabled' => env('PULSE_SWARM_STEP_DURATIONS_ENABLED', true),
    ],

    SwarmMemoryMetrics::class => [
        'enabled' => env('PULSE_SWARM_MEMORY_METRICS_ENABLED', true),
    ],
],
```

`SwarmRuns` records run totals, failures, failure rate inputs, topology usage, and average run duration. `SwarmStepDurations` records average step duration by swarm, topology, and agent. `SwarmMemoryMetrics` records memory entry counts, write byte sizes, recall hit/miss totals per scope, and snapshot byte sizes — gated by the `swarm.pulse.memory.sample_rate` config knob.

Pulse is the aggregate observability layer. If your browser needs a live
operations feed for individual runs, listen to Laravel Swarm lifecycle events
and broadcast your own application event.

See [Operations Dashboard](../examples/operations-dashboard/README.md) for that
run-level pattern.

## Add The Cards

Publish Pulse's dashboard view if you have not already:

```bash
php artisan vendor:publish --tag=pulse-dashboard
```

Then add the swarm cards to `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:swarm.runs cols="6" />
<livewire:swarm.steps cols="6" />
<livewire:swarm.audit-outbox cols="6" />
<livewire:swarm.memory cols="6" />
```

`<livewire:swarm.runs />` shows per-swarm totals, failures, failure rate, average run duration, and topology mix. `<livewire:swarm.steps />` shows the slowest average swarm steps by agent. `<livewire:swarm.audit-outbox />` surfaces the live operational state of the audit outbox. `<livewire:swarm.memory />` surfaces memory growth + snapshot size per scope (see [Swarm Memory](memory.md#pulse-observability) for tuning guidance).

## What Pulse Aggregates

### swarm.runs card

The `swarm.runs` card queries the following Pulse aggregate types, all keyed by swarm class:

| Aggregate type | What it represents |
| --- | --- |
| `swarm_run_total` | Total completed and failed runs (sum) |
| `swarm_run_failed` | Total failed runs (sum) |
| `swarm_topology_sequential` | Runs that used the sequential topology (sum) |
| `swarm_topology_parallel` | Runs that used the parallel topology (sum) |
| `swarm_topology_hierarchical` | Runs that used the hierarchical topology (sum) |
| `swarm_run_duration_total_ms` | Summed run duration in milliseconds (used to calculate average) |
| `swarm_run_duration_samples` | Sample count (used as the denominator for average duration) |

From these, the card derives:

- **Total runs** — `swarm_run_total` sum for the selected period.
- **Failures** — `swarm_run_failed` sum.
- **Failure rate** — `failures / totalRuns * 100`, rounded to one decimal place.
- **Average run duration** — `swarm_run_duration_total_ms / swarm_run_duration_samples`, rounded to the nearest millisecond.
- **Topology mix** — counts for each topology sorted by usage, displayed only for topologies that appeared in the period.

Rows are sorted by total run count descending. The card displays up to 100 swarm classes.

The `SwarmRuns` recorder fires on `SwarmCompleted` and `SwarmFailed`. Runs that are pending, queued but not yet started, or still in progress do not appear in this card until they reach a terminal state.

### swarm.steps card

The `swarm.steps` card queries the `swarm_step_duration` aggregate type, keyed by a composite of swarm class, topology, and agent class. For each combination it records:

- **Average step duration (ms)** — the `avg` aggregate of `durationMs` from `SwarmStepCompleted` events.
- **Step count** — how many step completions contributed to the average.

The card shows up to 25 entries sorted by average duration descending — the slowest agent steps across all swarms. Both swarm class and agent class are surfaced per row so you can identify which agent inside which swarm is responsible.

The `SwarmStepDurations` recorder fires on `SwarmStepCompleted`.

### swarm.audit-outbox card

The `swarm.audit-outbox` card surfaces the live operational state of the v0.5 audit outbox — the same signal an operator would see by running `php artisan swarm:audit:status`. Unlike `swarm.runs` and `swarm.steps`, this card does not depend on a Pulse recorder; the outbox is low-volume operational state, so the card queries the `swarm_audit_outbox` table directly (via the `AuditOutbox` contract / configured table name).

The card surfaces:

- **Dead-letter count** — number of rows in `dead_letter` status. The card renders this prominently in red whenever the count is greater than zero.
- **Pending count** — total rows in `pending` status, claimed or not.
- **Stale-pending count** — pending rows with a `reserved_at` older than `2 × swarm.durable.relay.reservation_timeout_seconds`, matching the same heuristic that `swarm:audit:status` and `swarm:health` use to flag a relay that may not be running.
- **Oldest pending age** and **oldest dead-letter age** — short, human-readable ages for the longest-waiting row in each status.
- **Dead-letter retention** — the configured `swarm.audit.outbox.dead_letter_retention_days`, or `indefinite` when no retention is configured.

When `swarm.persistence.driver` is not `database`, the `AuditOutbox` contract resolves to `NoOpAuditOutbox::isAvailable() === false` and the card renders a neutral "Audit outbox unavailable on cache persistence driver." state without touching the database.

When the card raises an alarm signal, follow the in-card hint: run `php artisan swarm:audit:status` for the full breakdown (age distribution, top dead-letter categories, oldest IDs), and `php artisan swarm:audit:reconcile` to triage. See [Maintenance](maintenance.md) and [Audit Evidence Contract](audit-evidence-contract.md) for the full operator workflow.

### swarm.memory card

The `swarm.memory` card surfaces memory growth + snapshot size signals operators tune retention and capture policy against. The `SwarmMemoryMetrics` recorder fires on the `MemoryWritten`, `MemoryRead`, and `MemorySnapshotted` events, gated by `swarm.pulse.memory.sample_rate` (default `1.0` — record every event; dial down for high-volume apps).

The card queries the following Pulse aggregate types, keyed by `MemoryScope` value (`Run`, `Conversation`, `Agent`, `Swarm`) for write/read signals and a single virtual `snapshots` key for snapshot signals:

| Aggregate type | What it represents |
| --- | --- |
| `swarm_memory_entries` | Count of `put()` calls per scope (sum) |
| `swarm_memory_bytes_total` | Summed JSON byte size of `value` + `metadata` per scope (sum) |
| `swarm_memory_bytes_samples` | Sample count for the byte total (denominator for average) |
| `swarm_memory_read_total` | Count of `get()` calls per scope (sum) |
| `swarm_memory_read_hits` | Count of `get()` calls that returned a stored entry (sum) |
| `swarm_memory_snapshot_count` | Count of persisted snapshot rows (sum) |
| `swarm_memory_snapshot_bytes` | Summed JSON byte size of snapshot rows (sum) |
| `swarm_memory_snapshot_entries` | Summed entry count of snapshot rows (sum) |

From these, the card derives:

- **Entries per scope** — `swarm_memory_entries`.
- **Avg bytes per write** — `swarm_memory_bytes_total / swarm_memory_bytes_samples`.
- **Hit rate** — `swarm_memory_read_hits / swarm_memory_read_total * 100`, rounded to one decimal place. `n/a` when no reads were sampled in the period.
- **Snapshots persisted, avg snapshot bytes, avg snapshot entries** — same averaging pattern using the snapshot aggregates.

For tuning guidance — which knob moves which number — see [Swarm Memory: Pulse observability](memory.md#pulse-observability).

### Period selectors

The `swarm.runs` and `swarm.steps` cards inherit Pulse's standard period controls (1h, 24h, 7d). The period applies to all aggregates on the card simultaneously. Pulse stores bucketed time-series data, so switching periods reloads pre-aggregated buckets rather than scanning raw events. The `swarm.audit-outbox` card reflects live operational state — period selectors do not apply because the underlying counts are point-in-time queries against the outbox table.

## Relationship to Telemetry

Pulse and the telemetry sink are complementary layers that serve different purposes.

**Pulse** is the aggregate layer. It shows totals, failure rates, average durations, and topology distributions over time. It answers questions like: "Are failure rates rising?", "Which swarm is slowest on average?", "How many runs completed in the last 24 hours?" Pulse data is bucketed and pre-aggregated; it cannot answer questions about a single run.

**The telemetry sink** is the per-event layer. Every lifecycle event (`SwarmStarted`, `SwarmStepCompleted`, `SwarmFailed`, job boundaries, etc.) flows through the sink with a `run_id`, timestamps, and correlation fields. It answers questions like: "Why did run `abc-123` fail?", "How long did each step take in this specific run?", "What exception was thrown?"

The practical division:

- Use Pulse for dashboards, trend monitoring, and alerting on aggregate thresholds.
- Use the telemetry sink and lifecycle events for investigating individual runs and correlating logs across worker processes.

For details on the telemetry sink, correlation fields, and structured log patterns see [Observability: Logging And Tracing](observability-logging-tracing.md).

## Customizing Card Display

The Livewire cards accept the standard Pulse `Card` props for layout control:

```blade
<livewire:swarm.runs cols="8" rows="4" />
<livewire:swarm.steps cols="4" rows="4" />
```

| Prop | Default | Notes |
| --- | --- | --- |
| `cols` | Pulse default | Number of dashboard grid columns the card spans. |
| `rows` | Pulse default | Number of dashboard grid rows the card spans. |

Column visibility, metric selection, and sort order are not configurable at the card level — the displayed columns and their order are fixed. The `enabled` flag in `config/pulse.php` is the supported way to disable a recorder without removing it from the config array:

```php
SwarmRuns::class => [
    'enabled' => env('PULSE_SWARM_RUNS_ENABLED', true),
],
```

Setting `PULSE_SWARM_RUNS_ENABLED=false` (or `PULSE_SWARM_STEP_DURATIONS_ENABLED=false`) stops the recorder from writing to Pulse. Cards for a disabled recorder will show no data for periods after the recorder was disabled but will still render historical data already stored.

## Troubleshooting

**No data appearing in Pulse**

Check the following in order:

1. Confirm `SWARM_OBSERVABILITY_ENABLED=true` is set (or that `swarm.observability.enabled` is `true` in `config/swarm.php`). The Pulse recorders listen to lifecycle events; if observability is disabled the events are never dispatched.
2. Confirm the `SwarmRuns` and `SwarmStepDurations` recorders are present in the `recorders` array in `config/pulse.php`. If the array is missing either entry, Pulse will not subscribe to the events.
3. Confirm `PULSE_SWARM_RUNS_ENABLED` and `PULSE_SWARM_STEP_DURATIONS_ENABLED` are not explicitly set to `false`.
4. Run a swarm synchronously (`prompt()`) and check the `pulse_entries` table (or equivalent configured Pulse storage) for rows with types matching `swarm_run_*` or `swarm_step_duration`. If rows appear, the recorder is working and the issue is the dashboard card — verify the card tags are present in `dashboard.blade.php`.

**Card registration error**

If you see a Livewire component resolution error, verify that `laravel/pulse` is installed and that the package service provider has run. The package registers the `swarm.runs` and `swarm.steps` Livewire components automatically when `Laravel\Pulse\Pulse` is resolvable, so the components are not available if Pulse is not installed.

**Metrics stop updating**

For queued and durable runs, the `SwarmCompleted` and `SwarmFailed` events fire inside queue workers, not the HTTP process. If Pulse stops showing new data for queued swarms:

- Confirm your queue worker is running and processing the queue that swarm jobs are dispatched to.
- For durable runs, confirm that `swarm:relay` (or `swarm:recover`) is scheduled. Durable runs that are waiting will not emit a terminal event until they resume and complete.
- Check your queue worker logs for failed `InvokeSwarm` or `AdvanceDurableSwarm` jobs. A job that fails without retries does not fire `SwarmFailed`; the run stays open and the Pulse card will not record it until the run reaches a terminal state.

**Pulse aggregation lag**

Pulse uses lazy recording by default — entries are flushed at the end of the request or job, not inline. If you are checking `pulse_entries` immediately after a synchronous run in a test or console script, confirm the Pulse flush has occurred. In tests, call `Pulse::flush()` or use the Pulse testing helpers if you need immediate visibility.

## Using Pulse for Production Monitoring

The two cards surface a small set of high-value signals for production operations.

**Failed run rate (`swarm.runs` card)**

A rising failure rate on any swarm class is the first signal to investigate. Common causes include provider timeouts, guardrail rejections, or depleted retries on durable runs. Compare the topology mix column: hierarchical swarms with a high coordinator step count are more exposed to structured-output validation failures.

Pair with the telemetry sink to drill into `SwarmFailed` events for the affected swarm class and inspect `exception_class` and `message` fields.

**Average run duration (`swarm.runs` card)**

Sustained increases in average run duration may indicate provider latency degradation or growing task complexity (more tokens, more steps). Use the 1h view to detect sudden regressions and the 7d view to identify gradual drift.

Average duration is computed from `swarm_run_duration_total_ms / swarm_run_duration_samples`, which includes all run outcomes that reached a terminal state. Failed runs that terminated early will pull the average down; if you see an unusually low average alongside a high failure rate, it may mean most runs are failing fast rather than completing successfully.

**p95 step latency (`swarm.steps` card)**

The steps card surfaces average step duration, not a true p95. For p95 estimation, use the telemetry sink to collect `durationMs` from `SwarmStepCompleted` events and compute percentiles in your log aggregator or metrics backend. The Pulse steps card is most useful for identifying which agent class is consistently slowest across swarms — use it to set realistic `#[Timeout]` attribute values on swarms where that agent appears.

**Pending run accumulation**

Pulse does not directly show pending runs (the `swarm.runs` card only counts terminal states). If you suspect queued or durable runs are accumulating without completing, use `php artisan swarm:status` to inspect the run history store. A growing backlog of runs that never reach `completed` or `failed` typically means:

- Queue workers are overwhelmed or not running.
- `swarm:relay` is not scheduled (for durable runs that advance through the relay command rather than pure queue jobs).
- A durable run is stuck in a waiting state and the signal or resume condition has not been satisfied.

The `swarm:recover` command can unstick durable runs that have exceeded their TTL without advancing.
