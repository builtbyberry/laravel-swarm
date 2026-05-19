# Durable Compliance Review

Shows a checkpointed workflow for compliance/document review where each durable
step is persisted before the next job is dispatched.

Use this pattern when replaying the entire swarm after a queue retry would be
too expensive, too slow, or operationally unsafe.

This example covers:

- database-backed persistence
- `dispatchDurable()`
- one-agent-step-per-job execution
- `SwarmCompleted` / `SwarmFailed` event handling
- scheduled `swarm:recover`
- scheduled `swarm:prune`
- operator pause, resume, cancel, and recover controls

**Requires:**

- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- migrated swarm tables
- a running queue worker
- `swarm:recover` scheduled in Laravel's scheduler
- `swarm:prune` scheduled for retention cleanup

## Strict Audit Mode

Regulated callers should treat unattributed runs and silently-dropped audit
evidence as compliance violations. v0.4 adds two configuration flags and a
new failure policy that together turn both conditions into hard failures
visible to the dispatching caller.

```bash
SWARM_AUDIT_ACTOR_REQUIRED=true
SWARM_AUDIT_FAILURE_POLICY=halt
```

With `actor.required=true`, runs entering the runner without a resolvable
`Actor` throw `MissingActorException` at dispatch entry. Bind one via
`$context->withActor(...)`, `Context::add('swarm:actor', $actor)` inside the
request, or a custom `ActorResolver` in the container.

With `failure_policy=halt`, audit sink and signer exceptions raise
`AuditSinkHaltedException` (which carries `HaltsSwarmExecution`) and surface
to the caller instead of being swallowed or logged. The `halt` policy is new
in v0.4, alongside the existing `swallow` and `log` policies.

Compose the two: bind a `SwarmAuditSigner` and keep `failure_policy=halt` so
any signing failure halts the run. Regulated callers cannot accidentally
emit unsigned evidence or complete a run whose audit trail was discarded.

See `docs/audit-evidence-contract.md` (Sink Failure Handler) for the full
contract and custom `SinkFailureHandler` patterns.

## What Durable Changes

`queue()` runs one queued job for the whole swarm. `dispatchDurable()` runs one
queued job per durable step and checkpoints the run between steps. In a
sequential swarm, that means one agent per job. In a hierarchical swarm, the
coordinator runs first and each later job advances one routed worker node.

That means a retry re-runs the current step. It does not replay the entire
workflow from the beginning.

## Configuration

```bash
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
SWARM_DURABLE_STEP_TIMEOUT=300
```

Run the package migrations before dispatching durable work:

```bash
php artisan migrate
php artisan queue:work
```

Durable work still runs on Laravel queues. Keep `SWARM_DURABLE_STEP_TIMEOUT`,
your worker timeout, and the queue connection's `retry_after` comfortably above
the provider call duration you expect for one agent step.

## Files To Create

### `app/Ai/Swarms/ComplianceReviewSwarm.php`

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ComplianceExtractor;
use App\Ai\Agents\ComplianceIntake;
use App\Ai\Agents\ComplianceRiskReviewer;
use App\Ai\Agents\ComplianceSummarizer;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ComplianceReviewSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ComplianceIntake,
            new ComplianceExtractor,
            new ComplianceRiskReviewer,
            new ComplianceSummarizer,
        ];
    }
}
```

### `app/Ai/Agents/ComplianceIntake.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ComplianceIntake implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Identify the document type, jurisdiction, and review objective.';
    }
}
```

### `app/Ai/Agents/ComplianceExtractor.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ComplianceExtractor implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract obligations, dates, parties, and cited controls.';
    }
}
```

### `app/Ai/Agents/ComplianceRiskReviewer.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ComplianceRiskReviewer implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Assess compliance risk and list unresolved review questions.';
    }
}
```

### `app/Ai/Agents/ComplianceSummarizer.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ComplianceSummarizer implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Write the final compliance summary for the reviewer.';
    }
}
```

## Dispatch

```php
use App\Ai\Swarms\ComplianceReviewSwarm;

$response = ComplianceReviewSwarm::make()->dispatchDurable([
    'document_id' => 1234,
    'document_type' => 'vendor contract',
    'jurisdiction' => 'US',
    'review_goal' => 'identify renewal and termination risk',
]);

$runId = $response->runId;
```

Only pass plain data. Store large documents in your own application storage and
pass identifiers or short excerpts through the swarm task.

## Events

```php
use App\Ai\Swarms\ComplianceReviewSwarm;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use Illuminate\Support\Facades\Event;

Event::listen(SwarmCompleted::class, function (SwarmCompleted $event): void {
    if ($event->swarmClass !== ComplianceReviewSwarm::class) {
        return;
    }

    logger()->info('Compliance review completed', [
        'run_id' => $event->runId,
        'output' => $event->output,
    ]);
});

Event::listen(SwarmFailed::class, function (SwarmFailed $event): void {
    if ($event->swarmClass !== ComplianceReviewSwarm::class) {
        return;
    }

    report($event->exception);
});
```

Durable responses do not use queued `then()` / `catch()` callbacks.

## Operator Controls

Use `DurableSwarmManager` when your application needs pause, resume, cancel, or
manual recovery buttons. These controls are step-boundary controls; they do not
hard-cancel an in-flight provider request.

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Http\JsonResponse;

public function pause(string $runId, DurableSwarmManager $manager): JsonResponse
{
    $manager->pause($runId);

    return response()->json([
        'run_id' => $runId,
        'status' => 'pause_requested',
    ]);
}

public function resume(string $runId, DurableSwarmManager $manager): JsonResponse
{
    $manager->resume($runId);

    return response()->json([
        'run_id' => $runId,
        'status' => 'resume_requested',
    ]);
}

public function cancel(string $runId, DurableSwarmManager $manager): JsonResponse
{
    $manager->cancel($runId);

    return response()->json([
        'run_id' => $runId,
        'status' => 'cancel_requested',
    ]);
}

public function recover(DurableSwarmManager $manager): JsonResponse
{
    return response()->json([
        'run_ids' => $manager->recover(limit: 10),
    ]);
}
```

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
Schedule::command('swarm:prune')->daily();
```

`swarm:recover` supervises checkpointed durable runs. `swarm:prune` handles
retention cleanup for terminal history, context, artifact, and durable rows.
