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

Durable execution supports sequential and hierarchical swarms in this release.
Parallel swarms are not durable yet.

For hierarchical swarms, the coordinator runs first and returns the route plan.
Laravel Swarm validates and persists that plan, then advances one routed worker
node per durable job. Hierarchical parallel groups execute branch workers
sequentially in declaration order for v1; durable fan-out/fan-in is intentionally
deferred.

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

For hierarchical durable runs, route plans and intermediate node outputs are
runtime state. They are retained only while the run is active so recovery can
continue from the correct node. When the run completes, fails, or is cancelled,
Laravel Swarm clears the runtime route plan, route cursor, and durable node
output rows. Terminal history and context still follow the normal capture and
redaction settings.

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

`swarm:recover` is the safety net for stranded durable runs. Schedule it
frequently:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

Treat recovery like workflow supervision, not like pruning. A stranded durable
run should not wait until tomorrow's maintenance window.

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

Hierarchical durable swarms are supported, but they carry higher planning,
prompt, and intermediate-output storage risk than a fixed sequential chain.
Hierarchical queued and durable execution both execute parallel groups
sequentially in this release. Treat hierarchical durable workflows as an
explicit operational choice rather than the default enterprise rollout path.

Durable recovery depends on the scheduler. If `swarm:recover` is not scheduled,
a run can stay `running` after a worker crashes or exits between checkpointing a
step and dispatching the next job. Manual recovery is possible with
`php artisan swarm:recover`, but production durable workflows should not depend
on a human noticing a stranded run.
