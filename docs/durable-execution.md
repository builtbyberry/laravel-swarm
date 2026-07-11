## Quick Setup With `swarm:install:durable`

The fastest way to wire a fresh Laravel app for durable execution is the
package's targeted sub-installer:

```bash
php artisan swarm:install:durable
```

`swarm:install:durable` is also dispatched automatically by the broader
[`swarm:install`](./getting-started.md) entry point — if you are setting
up the package for the first time, start there and let it offer the
durable wiring as one step in the full install. Use the targeted command
directly when you are adding durable execution to an application that
already has Laravel Swarm installed.

The command verifies that `swarm.persistence.driver` is `database`, confirms
the durable runtime tables (`swarm_durable_runs`, `swarm_durable_outbox`) have
been migrated (offering to run `php artisan migrate` if they have not),
appends the required scheduler block to `routes/console.php` (`swarm:relay`
every minute, `swarm:recover` every five minutes, `swarm:prune` daily) behind
an idempotency marker so re-runs are safe, inspects `QUEUE_CONNECTION` and
refuses on `sync` (use `--allow-sync-queue` for local experiments), and
prints copy-paste worker snippets for `queue:work`, Laravel Horizon, and
Forge/Supervisor.

Useful flags:

- `--queue=<name>` — override the queue name printed in the worker snippets
  (defaults to the configured `SWARM_DURABLE_QUEUE` or `swarm-durable`).
- `--migrate` / `--skip-migrate` — explicit yes/no for the migration step
  when running non-interactively (CI, container build).
- `--allow-sync-queue` — proceed even when `QUEUE_CONNECTION=sync`. Local
  iteration only; never for production.

What this command does **not** do, by design: install Horizon
(`composer require laravel/horizon`), edit `config/queue.php`, or spawn
worker processes. Those remain explicit operator decisions.

The step-by-step walkthrough below is the same plumbing the installer
performs — read it when you want to understand the operational contract,
or follow it when you are wiring durable execution into an app the
installer cannot touch.

## Your First Durable Swarm

This section walks through the minimum setup to dispatch and monitor a durable
swarm. Read it before diving into the deeper sections below — it gives you the
full happy path in one place.

### 1. Dispatch the swarm

```php
$response = ReportGenerationSwarm::make()
    ->dispatchDurable('Generate Q4 financial report for ACME Corp');

$runId = $response->runId;
```

`dispatchDurable()` returns immediately. It registers a durable run in the
database, writes the first step to the outbox, and gives you back a run ID. The
swarm itself runs in the background through your queue worker — you do not wait
for it here.

### 2. Store the run ID

Persist `$runId` to your database or session now. It is the only handle you
have for checking status, pausing, cancelling, or retrieving output later. If
you lose it, the run continues unaffected but becomes harder to track.

```php
$report = Report::create([
    'user_id' => auth()->id(),
    'swarm_run_id' => $runId,
    'status' => 'pending',
]);
```

### 3. Schedule the relay

The relay drains the durable outbox so each completed checkpoint dispatches its
next queue job. Without it, the swarm starts but never advances past the first
step.

```php
// routes/console.php
Schedule::command('swarm:relay')->everyMinute();
```

This is required — not optional. A durable run stalls permanently if the relay
is not running. See [Timeouts And Database Requirements](#timeouts-and-database-requirements)
for the full relay reference, including how to recover from a queue outage.

### 4. Schedule recovery

The recovery command finds runs whose queue workers died after checkpointing but
before dispatching the next job — a narrow but real crash window. Schedule it
frequently so a stranded run is caught within minutes, not hours.

```php
Schedule::command('swarm:recover')->everyFiveMinutes();
```

See [Pause, Resume, Cancel, And Recover](#pause-resume-cancel-and-recover) for
what recovery covers and how it handles durable parallel branches.

### 5. Listen for completion

Durable runs are event-driven. Register a listener for `SwarmCompleted` to act
on the result — send a notification, update a database record, or kick off a
downstream job.

```php
// In EventServiceProvider
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;

protected $listen = [
    SwarmCompleted::class => [SendReportNotification::class],
];
```

You can also listen to `SwarmFailed` to handle the failure path. Do not use
`then()` or `catch()` on durable responses — those callbacks are not supported
for durable runs.

That is the full happy path. The rest of this document covers the mechanics,
operational surface, and production requirements in depth.

---

# Durable Execution

Use `dispatchDurable()` when a swarm needs checkpointed execution instead of a
single long-running queue job.

Durable execution is Swarm-native checkpointing at agent-step boundaries. It is
not deterministic workflow replay and does not introduce generator/yield
workflow syntax.

`queue()` remains the lightweight background mode. One queued job runs the
whole swarm.

`dispatchDurable()` is different. Laravel Swarm persists durable runtime state
and advances one step per job.

## Choosing Between `queue()` And `dispatchDurable()`

Use `queue()` when the swarm is short-lived and one job is still a comfortable
fit for your workers and queue visibility settings.

For **hierarchical** swarms only, you can opt into `swarm.queue.hierarchical_parallel.coordination = multi_worker` (or `#[QueuedHierarchicalParallelCoordination]`) so **parallel route nodes** fan out to separate queue jobs while sequential segments still run in `InvokeSwarm` / `ResumeQueuedHierarchicalSwarm` segments—without checkpointing every routed step like full `dispatchDurable()`. That path reuses durable branch tables, leases, join, cancel, and `swarm:recover`; lifecycle events keep `execution_mode: queue`. It requires database-backed persistence.

Use `dispatchDurable()` when the workflow is long-running, production-critical,
or more expensive to replay from the beginning:

```php
use App\Ai\Swarms\ArticlePipeline;

$response = ArticlePipeline::make()->dispatchDurable([
    'topic' => 'Laravel queues',
    'audience' => 'intermediate developers',
    'goal' => 'blog outline',
]);

$response->runId;
```

Durable execution supports sequential, parallel, and hierarchical swarms.

For hierarchical swarms, the coordinator runs first and returns the route plan.
Laravel Swarm validates and persists that plan, then advances one routed worker
node per durable job. Hierarchical parallel groups create durable branch jobs
with independent leases, then join before continuing to the next route node.
Top-level parallel swarms use the same branch runtime and join into the same
combined output shape as synchronous `prompt()`.

### Runtime architecture and code map

`DurableSwarmManager` is the **application-facing facade** for operator actions,
signals, waits, inspection, and the queue jobs that call `advance()` /
`advanceBranch()`. The heavy lifting lives in focused classes under
`src/Runners/Durable/`, constructed together by `DurableManagerCollaboratorFactory`
so a single run shares one `DurableRunContext` and consistent capture behavior.

For a full collaborator table, container lifetime rules, job dispatch flow, test
patterns, and upgrade notes for removed manager methods, read
[Durable Runtime Architecture](durable-runtime-architecture.md).

Durable parallel branch failures are configurable with
`swarm.durable.parallel.failure_policy` or the
`#[DurableParallelFailurePolicy]` attribute. The default is `collect_failures`,
which waits for all dispatched branches to reach a terminal state before
failing the parent run with branch diagnostics. Applications can opt into
`fail_run` or `partial_success` when that better matches the workflow.

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy as FailurePolicy;

#[DurableParallelFailurePolicy(FailurePolicy::PartialSuccess)]
class ResearchSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            //
        ];
    }
}
```

Durable responses do not support `then()` or `catch()`. Durable runs are
event-driven. Listen to `SwarmCompleted` and `SwarmFailed` instead of
serializing callbacks into the queue payload.

`dispatchDurable()` still follows Laravel's pending-dispatch lifecycle. In
Tinker or other manual testing, holding onto the response keeps the first job
pending until the response object is released:

```php
$response = ArticlePipeline::make()->dispatchDurable([
    'topic' => 'Atomic lease test',
]);

$runId = $response->runId;

unset($response);
gc_collect_cycles();
```

## How Durable Runs Advance

Laravel Swarm persists durable execution state in the database and dispatches
one job for each durable step.

Each durable step job:

- acquires a database lease for the run
- reloads the persisted run context
- executes the next sequential agent or hierarchical routed worker
- checkpoints the updated context, artifacts, history, and durable cursor
- dispatches the next step job, or marks the run complete

That gives retries and recovery a clear boundary. A retry re-runs the current
step. It does not replay the whole workflow.

## Durable Per-Node Streaming

By default a durable step calls the agent's `prompt()` and records one response per
node. A swarm can instead **stream each node's events into the append-only causal
log** by declaring `#[DurableStreaming]` on the swarm class:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableStreaming;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
#[DurableStreaming]
class ClaimsReviewSwarm implements Swarm { /* … */ }
```

This gives operators a live, replay-safe signal from a durable run. A node that
crashes mid-stream and re-executes on resume retracts its prior attempt with a
`node_reexecuted` void-edge before re-emitting, so a clean fold of the log shows
exactly one attempt per node. Requires the **database** persistence driver (the
causal log lives in `swarm_stream_events`); dispatch fails loud otherwise.

The opt-in is **resolved once and pinned onto the durable run row at run-start**, so
a run streams (or does not) for its whole life — adding or removing the attribute in
a deploy never changes an in-flight run. A bare `#[DurableStreaming]` opts in;
`#[DurableStreaming(false)]` explicitly opts out (e.g. to override a base class).
A swarm without the attribute never writes a stream event.

**Operator kill-switch.** Set `SWARM_DURABLE_STREAMING_ENABLED=false`
(`swarm.durable.streaming_enabled`, default `true`) to stop the per-event causal-log
write load fleet-wide at runtime without a redeploy — e.g. when `swarm_stream_events`
is hot. It gates only emission: opted-in runs fall back to the blocking `prompt()`
path, but every crashed attempt is still retracted and every committed node still
sealed, so the log stays consistent. Flipping it mid-run is safe.

Scope: sequential and parallel durable runs in v0.15.0. Parallel covers both
top-level `Topology::Parallel` branches and hierarchical fan-out branches (and so
queue-hierarchical-parallel, whose branches run through the same advancer); each
branch streams under its own node id — a fan-out branch's real node id, or, for a
top-level parallel branch, its stable `branch_id` (e.g. `parallel:2`) — and under
its own branch attempt epoch, so a branch that crashes and resumes retracts only its
own prior attempt and never a committed sibling. Seal-on-join is by topology: a
**hierarchical** fan-out's branch generation is sealed at the parent's post-join
checkpoint (never at branch commit), so its events graduate and compact. A
**top-level `Topology::Parallel`** run instead converges by completing the run with no
post-join checkpoint, so its streamed branch events are **never sealed** — they are
retained-but-uncompacted in `swarm_stream_events` and expire with the rest of the run
at its data TTL (`swarm.context.ttl`) rather than graduating to cold storage. This is
deliberate: a top-level parallel run has no subsequent node to fence, so there is no
on-join seal point; size `swarm_stream_events` retention with that in mind for
high-fan-out top-level parallel streaming. Hierarchical main-walk node streaming
follows. Applying `#[DurableStreaming]` to a topology that does not yet stream **fails
loud at dispatch** — it never silently pins the opt-in and no-ops. Non-durable
(`run()`/`queue()`) execution is unaffected.

## Durable Hierarchical Parallel Flow

A hierarchical durable run can contain route-plan `parallel` nodes. Those
parallel nodes do not run inside one parent job. Laravel Swarm turns each branch
worker into a durable branch job with its own lease.

The flow is:

1. the coordinator runs as the first durable step
2. Laravel Swarm validates and persists the route plan and route cursor
3. a parallel node creates branch runtime rows for each branch worker
4. branch jobs run independently and checkpoint output, usage, attempts,
   failure details, and queue routing
5. the parent durable run enters `waiting` while branch rows are active
6. when every branch row is terminal, the parent is released to the join step
7. the next routed worker receives branch outputs through `with_outputs`
8. the route continues until a finish node completes the run

That waiting parent is active operational state, not a terminal run. Pause and
cancel operate immediately at this branch boundary. Pausing prevents the join
from dispatching while allowing already-running branch provider calls to finish.
Resuming redispatches pending or stale branches, or releases the parent to the
join when every branch is already terminal. Cancelling marks non-terminal branch
rows cancelled so stale branch workers become inert when they checkpoint.

Recovery also understands this boundary. If a branch checkpoints successfully
but the worker exits before dispatching the parent join, `swarm:recover` can
release the waiting parent once every branch row is terminal.

Active route plans and branch outputs can contain worker prompts and
intermediate outputs. Treat durable runtime tables as sensitive operational
storage and keep capture settings and retention windows aligned with the data
being processed.

See [Durable Hierarchical Approval](../examples/durable-hierarchical-approval/README.md)
for a copy-paste example with a coordinator, two branch reviewers, and a join
summarizer.

## Operational State

Durable runs persist neutral operational state in the database so applications
can build run inspectors, operator dashboards, and recovery tools without
depending only on terminal history rows.

For hierarchical durable runs, Laravel Swarm stores the route cursor, route
start node, current node, and completed node IDs on `swarm_durable_runs`, keeps
the validated route plan and run-level failure / retry policy in
`swarm_durable_run_state`, and stores per-node snapshots in
`swarm_durable_node_states` so the hot durable row stays narrow for lease and
scheduler updates. While a run is active, the validated route plan enables
recovery to continue the route. Active route plans can contain worker prompts and
should be treated as sensitive operational storage.

When a hierarchical durable run completes, fails, or is cancelled, Laravel
Swarm replaces the raw route plan with an inspection-safe projection. The
terminal projection keeps route topology such as node IDs, node type, worker
agent, selected dependencies, branch IDs, next pointers, and finish
`output_from`, but removes worker prompts, literal finish output, and node
metadata.

For durable parallel work, branch runtime rows track branch IDs, parent node IDs,
agent classes, inputs, outputs, failures, queue routing, attempts, and
branch-specific leases. The parent durable run waits while branches are active
and only advances the join after all branch rows are terminally accounted for.
That waiting state is a durable branch boundary: recovery can release it to the
join step after terminal branch checkpoints, and pause, resume, or cancel can
operate without an active parent step job.

For all durable runs, the main durable row tracks execution mode, attempts,
lease timestamps, recovery counters, pause/resume/cancel timestamps, and
timeout state; run-level failure metadata and retry policy live in
`swarm_durable_run_state` and are merged into `DurableRunStore::find()` for
inspection.

`SwarmHistory` remains the stable inspection API for run history, output,
steps, usage, timing, and terminal failure details. The durable runtime record
is the database-backed operational surface for current execution state.

Intermediate durable node outputs and branch outputs are still treated as
runtime payloads. Laravel Swarm deletes durable node-output rows when a
hierarchical durable run completes, fails, or is cancelled. Terminal history,
context, and durable failure metadata follow the normal capture and redaction
settings.

## Operational query contract

This section is the **supported durable operational query surface** for
high-volume operators and integrators. It describes which fields are safe
predicates, which tables participate, how first-party commands and Pulse behave,
and what stays intentionally out of contract.

### Database persistence only

**Cache-backed persistence (`swarm.persistence.driver = cache`) is out of this
contract.** Durable execution itself requires database-backed stores; there is
no durable runtime table in cache mode. Any monitoring, recovery automation, or
dashboard that assumes queryable durable rows **must** use the `database`
driver. See [Maintenance](maintenance.md) for persistence driver notes.

### Package-maintained operational surfaces

These entry points are part of the contract: they resolve durable state through
`DurableRunStore` / `DatabaseDurableRunStore` and do **not** use broad JSON-path
`WHERE` clauses on checkpoint payloads.

| Surface | Query / data path | Primary tables / columns |
| --- | --- | --- |
| `swarm:recover` | `DurableRecoveryCoordinator` → `recoverable`, `recoverableBranches`, `dueRetries`, `dueRetryBranches`, `recoverableWaitingJoins`, `recoverableTimedOutWaits`, `parentsWaitingOnTerminalChildren`, `undispatchedChildRuns` | Typed columns on `swarm_durable_runs`, `swarm_durable_branches`, joins to `swarm_durable_waits` |
| `swarm:inspect`, `swarm:progress` | `DurableSwarmManager::inspect` → `find`, `labels`, `details`, `waits`, `signals`, `progress`, `childRuns`, `branchesFor` | Main row + satellite tables keyed by `run_id` |
| `swarm:pause` / `resume` / `cancel` | lifecycle controllers → `DurableRunStore` mutations | Typed status / lease / pause columns |
| `swarm:health --durable` | store readiness probe | Connection to configured durable tables |
| `swarm:prune` | category pruning over configured `swarm.tables.*` roles | All durable family tables (bounded batches) |
| Pulse `SwarmRuns` / `SwarmStepDurations` (companion package, v0.17.1+) | **Event-driven** aggregates on `SwarmCompleted`, `SwarmFailed`, `SwarmStepCompleted` | Laravel Pulse tables only — **no** direct durable SQL |

`swarm:status` and `swarm:history` read **run history** (`RunHistoryStore` /
`swarm_run_histories`), not durable tables. Treat history as the listing API for
terminal and step-captured runs; join to durable rows only when you need live
runtime fields for a known `run_id`.

### Supported predicates (typed and indexed)

Filter and sort list views using **typed columns** on `swarm_durable_runs` and
**satellite tables** (labels, waits, signals, progress, child runs, branches,
`swarm_durable_run_state` keys you join on, `swarm_durable_node_states` by
`node_id`). **Do not** use SQL predicates on JSON paths across large result sets.

**Approved filter shapes:** equality on `run_id`, `swarm_class`, `status`,
`topology`, `coordination_profile`, `execution_mode`; range or ordering on
`created_at`, `updated_at`, `finished_at`, `leased_until`, `next_retry_at`,
`wait_timeout_at`, `timeout_at`; `IN` / `whereIn` on small bounded status sets;
label lookups via `swarm_durable_labels` (`key` + typed `value_*` columns). Load
checkpoint JSON **after** narrowing to a small row set (detail hydration), not
as the primary `WHERE` across the fleet.

**Identity and classification:** `run_id`, `swarm_class`, `topology`,
`execution_mode`, `coordination_profile`

**Lifecycle:** `status`, `finished_at`, `created_at`, `updated_at`

**Steps and hierarchy:** `next_step_index`, `current_step_index`, `total_steps`,
`current_node_id`, `route_start_node_id`, `parent_run_id`

**Leases and retries:** `leased_until`, `lease_acquired_at`, `execution_token`,
`attempts`, `next_retry_at`, `retry_attempt`, `recovery_count`, `last_recovered_at`

**Timeouts and waits:** `timeout_at`, `step_timeout_seconds`, `timed_out_at`,
`wait_reason`, `waiting_since`, `wait_timeout_at`, `last_progress_at`

**Queue routing:** `queue_connection`, `queue_name`

**Pause and cancel:** `pause_requested_at`, `paused_at`, `resumed_at`,
`cancel_requested_at`, `cancelled_at`

**Labels:** `swarm_durable_labels` keyed by `run_id`, filter on `key` and typed
value columns (`value_string`, `value_integer`, `value_float`, `value_boolean`,
`value_type`). Prefer `DurableRunStore::runIdsForLabels()` for package-aligned
label resolution.

**Satellite operational rows:** `swarm_durable_waits`, `swarm_durable_signals`,
`swarm_durable_progress`, `swarm_durable_child_runs`, `swarm_durable_branches`,
`swarm_durable_details` (KV details), webhook idempotency — each has typed
status / name / timestamp columns suitable for predicates; keep JSON `metadata`
/ `outcome` / `progress` blobs for post-filter hydration unless you add an
**application-owned projection** (below).

### Non-queryable checkpoint JSON

The following remain **checkpoint / inspection payload**, not fleet-wide
predicates:

- `swarm_durable_run_state`: `route_plan`, `failure`, `retry_policy`
- `swarm_durable_node_states.state` per `node_id`
- `swarm_durable_runs.route_cursor`, `completed_node_ids` (JSON on the main row
  for routing joins — still avoid `WHERE` JSON-path scans; narrow by typed
  columns first)

Laravel Swarm's recovery, retry, and join helpers query only typed fields.

### Indexes the package relies on

Recovery and waiting-join scans use composite indexes on `swarm_durable_runs` and
`swarm_durable_branches` (see migration
`2026_04_24_000011_add_recovery_indexes_to_swarm_durable_tables.php`:
`swarm_durable_runs_recovery_idx`, `swarm_durable_runs_waiting_join_idx`,
`swarm_durable_branches_recovery_idx`). When adding custom reporting queries,
ensure predicates remain compatible with these indexes or add **your own**
covering indexes in application migrations.

### Pulse and the contract

The [Pulse companion package](pulse.md)'s recorders aggregate **lifecycle
events**, not durable SQL. They remain aligned with the contract because keys
are derived from typed event properties (`swarmClass`, `topology`, `status`,
`durationMs`). If you extend Pulse cards, keep the same rule: derive
aggregates from events or from **typed** durable columns — never from
JSON-path filters across durable tables.

### Application-owned projections

When you need tenant dashboards, analytics, or ad hoc filters that checkpoint
JSON cannot support at scale, **project** into an application-owned table using
swarm lifecycle events. Example pattern:

1. Create an `app_swarm_run_projections` (or similarly named) migration in your
   application with columns you control (`run_id` unique, `swarm_class`,
   `tenant_id`, `current_status`, `last_step_at`, denormalized counters, etc.)
   and the indexes your dashboards require.
2. Listen to `BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted` and
   `BuiltByBerry\LaravelSwarm\Events\SwarmCompleted` (and optionally
   `SwarmFailed`) in your application: upsert projection rows by `run_id`.
   Use `ShouldQueue` listeners if writes must not block swarm completion.
3. Treat projection writes as **at-least-once**: use `updateOrInsert` on
   `run_id` + step index (or a monotonic `step_sequence` you own) so replays are
   idempotent.
4. Own **retention and PII** on projection tables separately from Swarm prune;
   Swarm prune does not delete app tables.

The snippet below uses an **application-owned** model name for illustration only;
the package does not publish `SwarmRunProjection` or a migrations stub for it.

```php
namespace App\Listeners;

use App\Models\SwarmRunProjection;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProjectSwarmRunToAnalyticsTable implements ShouldQueue
{
    public function handle(SwarmStepCompleted|SwarmCompleted $event): void
    {
        SwarmRunProjection::query()->updateOrInsert(
            ['run_id' => $event->runId],
            [
                'swarm_class' => $event->swarmClass,
                'topology' => $event->topology,
                'execution_mode' => $event->executionMode,
                'last_event_at' => now(),
                'last_step_index' => $event instanceof SwarmStepCompleted ? $event->index : null,
                'terminal_output' => $event instanceof SwarmCompleted ? $event->output : null,
            ],
        );
    }
}
```

Register the listener in your application `EventServiceProvider` (or Laravel
11+ `AppServiceProvider` using `Event::listen`). **Redaction:** projection code
runs in your app — respect `swarm.capture.*` and your own data policies; do not
copy sensitive prompts into analytics tables unless required.

### Anti-patterns

- `whereJsonContains` / `JSON_EXTRACT` / `json_extract` predicates across
  `swarm_durable_*` for fleet list views
- Full-table scans of `swarm_durable_run_state.route_plan` for reporting
- Calling `inspect()` per row in a high-volume list (hydrate details only after
  narrowing by typed predicates or history)

### CI static guard (non-exhaustive)

`tests/Unit/DurableOperationalQueryContractStaticTest` scans only
`src/Persistence/`, `src/Commands/`, and `src/Runners/` and fails
the build if `whereJson*`, `JSON_EXTRACT`, or `json_extract(` appears in those
trees. Under **`src/Persistence/`** it also flags common **Laravel JSON column
path** call shapes: quoted strings such as `where('col->key', …)` and
`orderBy(…'col->key'…)` / `orderByAsc` / `orderByDesc`, so JSON-path predicates
are harder to reintroduce in the store layer.

The check remains **regex-based**, not a SQL parser: it does **not** catch every
risk (`whereRaw` with hand-written JSON SQL, dynamic column names, or matches
inside comments). Those stay **code-review** discipline; extend the test
allowlist or patterns when a concrete regression appears.

For listing finished work, prefer **run history** (`SwarmHistory` /
`swarm_run_histories`) and combine it with durable tables when you need live
runtime fields for specific `run_id` values.

## Pause, Resume, Cancel, And Recover

Laravel Swarm includes operator commands for durable runs:

```bash
php artisan swarm:pause <run-id>
php artisan swarm:resume <run-id>
php artisan swarm:cancel <run-id>
php artisan swarm:recover
```

`pause()` and `cancel()` are step-boundary controls. Laravel Swarm does not try
to hard-cancel an in-flight provider request.

When a durable parallel parent is waiting for branch jobs, pause and cancel are
handled immediately at that branch boundary. Pausing a waiting run prevents the
parent join from dispatching; active branch jobs may finish their current
provider call, but the parent will not advance until the run is resumed.
Resuming a paused branch boundary redispatches pending or stale branches when
branch work remains. If every branch is already terminal, resume releases the
parent run back to `pending` and dispatches the join step. Cancelling a waiting
run marks non-terminal branches cancelled so stale branch workers become inert
when they try to checkpoint.

`swarm:recover` is the safety net for stranded durable runs. Schedule it
frequently:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

Treat recovery like workflow supervision, not like pruning. A stranded durable
run should not wait until tomorrow's maintenance window.
Recovery covers both stale parent or branch leases and the crash window after a
branch checkpoint commits but before the parent join job is dispatched.
It also releases timed-out durable run waits so a waiting run can continue with
a timeout outcome.

## Operator Control Contract

The Artisan commands above are thin wrappers over a public, container-bound
contract you can call from application code — an operator console, an HTTP
controller, an approval workflow, or scheduled maintenance. Resolve
`BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator`:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator;

$operator = app(SwarmOperator::class);

$pause  = $operator->pause($runId);   // DurablePauseResult
$resume = $operator->resume($runId);  // DurableResumeResult
$cancel = $operator->cancel($runId);  // DurableCancelResult
$signal = $operator->signal($runId, 'approved', ['by' => $userId], idempotencyKey: $requestId);
$ids    = $operator->recover(runId: $runId); // array<string> of redispatched run ids
```

Every control verb returns a **rich lifecycle result**, not a bare `bool`, so
you always know what actually happened. `pause()`/`cancel()` are step-boundary
controls: a run idle at a checkpoint transitions immediately, while a run
mid-step is marked to transition at its next safe boundary. The result reports
which:

The `status` is a typed `BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus`
enum, and each result exposes convenience predicates:

```php
use BuiltByBerry\LaravelSwarm\Enums\DurableLifecycleStatus;

$pause->status;        // DurableLifecycleStatus::Paused or ::PauseScheduled
$pause->isImmediate(); // true when it paused now

$cancel->status;       // DurableLifecycleStatus::Cancelled or ::CancelScheduled

$resume->status;                     // DurableLifecycleStatus::Resumed or ::Waiting
$resume->isWaiting();                // true when it resumed back into a waiting boundary
$resume->waitingBoundaryDispatched;  // true when a waiting boundary was re-armed
```

The contract is **control-only**. Operational reads — status, current step,
queue routing, labels — stay on the public history path (`SwarmHistory` /
`RunHistoryStore`, and the read commands below), so a dashboard reads there and
controls here.

The contract is **authorization-agnostic**: it performs no permission checks.
Deciding whether a caller may control a given run is your application's
responsibility — put a policy, gate, or middleware in front of the call.

Control verbs **fail loud**: an unknown or foreign `runId`, or a run in a status
the verb cannot act on, throws
`BuiltByBerry\LaravelSwarm\Exceptions\SwarmException`. A verb never silently
no-ops. `signal()` is idempotent per `idempotencyKey` (a duplicate delivery is
reported as `duplicate`, not re-applied) and `recover()` is lease-guarded, so a
double dispatch is safe.

The same result objects back `DurableSwarmResponse::pause()`/`resume()`/`cancel()`
and `signal()`.

## Read-only inspection

Where `SwarmOperator` is the **control** contract, `InspectsDurableRuns` is its
**read** counterpart — the supported, container-bound seam for companion
packages and external readers (such as the [`laravel-swarm-filament`](https://github.com/builtbyberry/laravel-swarm-filament)
panel and the [`laravel-swarm-mcp`](https://github.com/builtbyberry/laravel-swarm-mcp)
server) that need to **display** a durable run's state. Resolve it instead of
reaching into the `@internal` `DurableSwarmManager` / `DurableRunInspector` or the
`@internal` `SwarmPersistenceCipher`:

```php
use BuiltByBerry\LaravelSwarm\Contracts\InspectsDurableRuns;

$inspector = app(InspectsDurableRuns::class);

$detail = $inspector->inspect($runId);           // DurableRunDetail (throws if unknown)
$row    = $inspector->find($runId);              // durable run row, or null
$byLbls = $inspector->inspectByLabels(['env' => 'prod'], limit: 50);
```

`inspect()` returns a `DurableRunDetail` — the assembled read model carrying the
run row, its run history, labels, details, waits, signals, progress, child runs,
parallel branches, and hierarchical node outputs, plus `toArray()`.

**Display-decrypt contract.** Every sealed field these reads return is opened
through the evidence path that honors `swarm.persistence.decrypt_failure_policy`
and **degrades per row**: a value that cannot be decrypted becomes `null` with an
explicit `*_available: false` flag (e.g. `input_available`, `output_available`,
`context_available`) rather than throwing or leaking `sw0:` ciphertext — so one
undecryptable row never aborts the batch and never 500s a display surface. This
is the opposite of the operational resume reads on `DurableRunStore`, which
decrypt strictly and fail loud on a rotated `APP_KEY`; the two paths never
collapse.

Like `SwarmOperator`, this contract is **authorization-agnostic** — gate access
in your own application. It is backed by the database-durable inspector (durable
state is database-only). The companion read seams for the other surfaces follow
the same display-decrypt contract: `ReadableRunHistoryStore` (run + step detail
and the runs list) and `ReadableAuditOutbox` (non-mutating audit-outbox health).
See [Public Surface — read-only inspection contracts](public-surface.md#read-only-inspection-contracts-v0190).

## Durable Operator Surfaces

Durable runs can now carry indexed labels, structured details, latest progress
records, signals, waits, and child swarm lineage. Use `swarm:inspect <run-id>
--json` for a full operator-oriented snapshot of a single run, and
`swarm:progress <run-id>` for latest progress records. List-heavy dashboards
should aggregate or cache the underlying durable tables instead of calling full
inspect for every row.

See:

- [Durable Runtime Architecture](durable-runtime-architecture.md) — code map, container rules, testing hooks
- [Durable Waits And Signals](durable-waits-and-signals.md)
- [Durable Retries And Progress](durable-retries-and-progress.md)
- [Durable Child Swarms](durable-child-swarms.md)
- [Durable Webhooks](durable-webhooks.md)

## Timeouts And Database Requirements

Durable execution keeps the existing swarm timeout as the overall workflow
deadline. Each durable step job also uses a dedicated step timeout and lease
window.

Configure the per-step lease window with `SWARM_DURABLE_STEP_TIMEOUT`. The
value must be a positive integer number of seconds:

```bash
SWARM_DURABLE_STEP_TIMEOUT=300
```

`AdvanceDurableSwarm` and `AdvanceDurableBranch` declare explicit queue
settings derived from config:

- `SWARM_DURABLE_JOB_TRIES` (`swarm.durable.job.tries`, default `3`)
- `SWARM_DURABLE_JOB_TIMEOUT_MARGIN_SECONDS` (`swarm.durable.job.timeout_margin_seconds`, default `60`) — job `timeout()` is **step timeout + this margin** so the worker survives the step lease window with headroom for dispatch bookkeeping
- `SWARM_DURABLE_JOB_BACKOFF_SECONDS` (`swarm.durable.job.backoff_seconds`) — comma-separated positive integers, default `10,30,60`

Align the queue worker `--timeout` and the connection `retry_after` with these
values and your longest provider calls; see [Maintenance](maintenance.md) for
the production checklist.

Durable execution requires the database-backed persistence stores and the
durable runtime table. It is intentionally not available with cache-backed
swarm persistence.

Before dispatching durable runs in an application, configure database
persistence and run the package migrations:

```bash
SWARM_PERSISTENCE_DRIVER=database

php artisan migrate
```

Then run a queue worker for the connection and queue used by your durable swarm
jobs:

```bash
php artisan queue:work
```

Durable jobs still obey Laravel's queue worker and connection settings. Keep
the worker timeout and queue connection `retry_after` comfortably above the
longest expected provider call for one durable step. If the queue visibility
window is shorter than the provider call, another worker may see the job as
available before the current worker finishes.

Schedule the relay so checkpointed steps are dispatched to the queue after each
checkpoint commits:

```php
use Illuminate\Support\Facades\Schedule;

// Required: drains the durable outbox so each checkpoint dispatches its next job.
Schedule::command('swarm:relay')->everyMinute();
```

**Exit codes:** `swarm:relay` exits 0 only when the outbox is genuinely clean —
every claimed entry was dispatched or permanently removed. It exits 1 when entries
could not be dispatched due to a transient error (queue driver unavailable). Those
entries remain in the outbox and are re-claimed on the next relay run. Alert on
non-zero exit codes and cross-reference the `command.relay` audit event
(`status: "transient_failure"`) to distinguish a queue outage from a hard error
(`status: "error"`).

**Clearing a backlog after a queue outage:** use `--drain-until-empty` to process
all pending rows in a single invocation. Add `--max-attempts N` to retry through
batches of transient failures — useful when the queue driver is recovering and you
want to clear the backlog without waiting for the scheduler:

```bash
# Drain everything; stop if only transient failures remain
php artisan swarm:relay --drain-until-empty

# Retry up to 10 times, including through transient failure batches
php artisan swarm:relay --drain-until-empty --max-attempts=10
```

`--max-attempts` iterations run consecutively with no sleep. Size N for a short
recovery window rather than a large one-off number during a sustained outage.

Schedule recovery so runs that were checkpointed but whose queue job was never
dispatched are rediscovered and retried:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

Also schedule pruning so expired database persistence rows are removed:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:prune')->daily();
```

## Production Setup Checklist

Before using durable swarms in production, make the operational contract
explicit (see [Operational query contract](#operational-query-contract) above).
Durable sequential execution is the recommended default for an
enterprise pilot because each step has the simplest retry, recovery, and
inspection boundary.

- use database-backed persistence and run the package migrations
- put durable swarms on a dedicated queue when the workflow is important or
  provider calls are slow
- schedule `swarm:relay` every minute (required — dispatches jobs after checkpoint)
- schedule `swarm:recover` every few minutes
- schedule `swarm:prune` daily, or more often if retention windows are short
- keep the queue worker timeout above the longest expected durable step
- keep the queue connection `retry_after` above both the worker timeout and the
  `swarm.durable.step_timeout` value
- keep retention short for high-volume workflows
- keep capture settings conservative when prompts, outputs, context, or
  artifacts may contain regulated data
- disable automatic artifact capture unless step-output artifacts are required
  for inspection
- monitor run count, step count, artifact count, table growth, and per-run
  latency from the first production run

For a narrow production pilot, prefer sequential durable swarms with
lower-sensitivity data, a dedicated queue, short retention, and database growth
monitoring from day one.

Hierarchical durable swarms are supported, including durable branch fan-out for
parallel route nodes, but they carry higher planning, prompt, and
intermediate-output storage risk than a fixed sequential chain. Treat
hierarchical durable workflows as an explicit operational choice rather than
the default enterprise rollout path.

Durable dispatch depends on the relay. If `swarm:relay` is not scheduled, durable
runs stall permanently after the first checkpoint — the outbox row is written but
never drained. Run `php artisan swarm:relay` once to unblock a stalled run, then
add it to the scheduler for ongoing operation. `swarm:health --durable` warns when
unclaimed outbox rows are stale, making it easy to detect a missing relay entry.

Durable recovery depends on the scheduler. If `swarm:recover` is not scheduled,
a run can stay `running` after a worker crashes or exits between checkpointing a
step and dispatching the next job. Manual recovery is possible with
`php artisan swarm:recover`, but production durable workflows should not depend
on a human noticing a stranded run.
