# Durable Execution

Use `dispatchDurable()` when a swarm needs checkpointed execution instead of a
single long-running queue job.

`queue()` remains the lightweight background mode. One queued job runs the
whole swarm.

`dispatchDurable()` is different. Laravel Swarm persists durable runtime state
and advances one step per job.

## Choosing Between `queue()` And `dispatchDurable()`

Use `queue()` when the swarm is short-lived and one job is still a comfortable
fit for your workers and queue visibility settings.

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
combined output shape as synchronous `run()`.

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

## Operational State

Durable runs persist neutral operational state in the database so applications
can build run inspectors, operator dashboards, and recovery tools without
depending only on terminal history rows.

For hierarchical durable runs, Laravel Swarm stores the route cursor, route
start node, current node, completed node IDs, and per-node state. While a run
is active, the durable runtime record also keeps the validated route plan so
recovery can continue the route. Active route plans can contain worker prompts
and should be treated as sensitive operational storage.

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

For all durable runs, the runtime record also tracks execution mode, attempts,
lease timestamps, recovery counters, pause/resume/cancel timestamps, timeout
state, and failure metadata.

`SwarmHistory` remains the stable inspection API for run history, output,
steps, usage, timing, and terminal failure details. The durable runtime record
is the database-backed operational surface for current execution state.

Intermediate durable node outputs and branch outputs are still treated as
runtime payloads. Laravel Swarm deletes durable node-output rows when a
hierarchical durable run completes, fails, or is cancelled. Terminal history,
context, and durable failure metadata follow the normal capture and redaction
settings.

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
