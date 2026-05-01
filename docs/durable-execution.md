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

## Operational queries at scale

Operator dashboards and high-volume list views should filter and sort using
**typed columns** on `swarm_durable_runs` and **satellite tables** (labels,
waits, signals, progress, child runs, branches, `swarm_durable_run_state`,
`swarm_durable_node_states`), not SQL predicates on JSON paths across large
result sets.

Laravel Swarm’s recovery, retry, and join helpers query only typed fields. For
your own dashboards, treat these as the supported operational dimensions:

- **Identity and classification:** `run_id`, `swarm_class`, `topology`,
  `execution_mode`, `coordination_profile`
- **Lifecycle:** `status`, `finished_at`, `created_at`, `updated_at`
- **Steps and hierarchy:** `next_step_index`, `current_step_index`, `total_steps`,
  `current_node_id`, `route_start_node_id`, `parent_run_id`
- **Leases and retries:** `leased_until`, `lease_acquired_at`, `execution_token`,
  `attempts`, `next_retry_at`, `retry_attempt`, `recovery_count`, `last_recovered_at`
- **Timeouts and waits:** `timeout_at`, `step_timeout_seconds`, `timed_out_at`,
  `wait_reason`, `waiting_since`, `wait_timeout_at`, `last_progress_at`
- **Queue routing:** `queue_connection`, `queue_name`
- **Pause and cancel:** `pause_requested_at`, `paused_at`, `resumed_at`,
  `cancel_requested_at`, `cancelled_at`
- **Labels:** query `swarm_durable_labels` by `key` and the typed value columns
  (`value_string`, `value_integer`, `value_float`, `value_boolean`, `value_type`)

Checkpoint JSON for hierarchical routing and per-node progress lives in
`swarm_durable_run_state` (`route_plan`, `failure`, `retry_policy`) and
`swarm_durable_node_states` (`state` per `node_id`), not on the hot
`swarm_durable_runs` row. The main durable row still carries `route_cursor` and
`completed_node_ids` for scheduler joins. Load JSON payloads **after** narrowing
by `run_id` or the typed filters above—for example when hydrating a detail
view—not as the primary `WHERE` clause across a large table.

For listing finished work, prefer **run history** (`SwarmHistory` /
`swarm_run_histories`) and combine it with durable tables when you need live
runtime fields.

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

## Durable Operator Surfaces

Durable runs can now carry indexed labels, structured details, latest progress
records, signals, waits, and child swarm lineage. Use `swarm:inspect <run-id>
--json` for a full operator-oriented snapshot of a single run, and
`swarm:progress <run-id>` for latest progress records. List-heavy dashboards
should aggregate or cache the underlying durable tables instead of calling full
inspect for every row.

See:

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

Finally, schedule recovery so checkpointed durable runs are supervised if a
worker exits after saving state but before dispatching the next step:

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
explicit. Durable sequential execution is the recommended default for an
enterprise pilot because each step has the simplest retry, recovery, and
inspection boundary.

- use database-backed persistence and run the package migrations
- put durable swarms on a dedicated queue when the workflow is important or
  provider calls are slow
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

Durable recovery depends on the scheduler. If `swarm:recover` is not scheduled,
a run can stay `running` after a worker crashes or exits between checkpointing a
step and dispatching the next job. Manual recovery is possible with
`php artisan swarm:recover`, but production durable workflows should not depend
on a human noticing a stranded run.
