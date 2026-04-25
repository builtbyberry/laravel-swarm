# Durable Execution

Use `dispatchDurable()` when a swarm needs checkpointed execution instead of a
single long-running queue job.

`queue()` remains the lightweight background mode. One queued job runs the
whole swarm.

`dispatchDurable()` is different. Laravel Swarm persists a durable cursor and
advances one sequential step per job.

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

Durable execution is sequential-only in this release.

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
one job for each swarm step.

Each durable step job:

- acquires a database lease for the run
- reloads the persisted run context
- executes the next sequential agent
- checkpoints the updated context, artifacts, and history
- dispatches the next step job, or marks the run complete

That gives retries and recovery a clear boundary. A retry re-runs the current
step. It does not replay the whole workflow.

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

Finally, schedule recovery so checkpointed durable runs are supervised if a
worker exits after saving state but before dispatching the next step:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```
