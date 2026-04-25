# Durable Compliance Review

Shows a checkpointed sequential workflow for compliance/document review where
each agent step is persisted before the next job is dispatched.

Use this pattern when replaying the entire swarm after a queue retry would be
too expensive, too slow, or operationally unsafe.

This example covers:

- database-backed persistence
- `dispatchDurable()`
- one-agent-step-per-job execution
- `SwarmCompleted` / `SwarmFailed` event handling
- scheduled `swarm:recover`
- scheduled `swarm:prune`

**Requires:**

- `SWARM_PERSISTENCE_DRIVER=database`
- migrated swarm tables
- a running queue worker
- `swarm:recover` scheduled in Laravel's scheduler
- `swarm:prune` scheduled for retention cleanup

## What Durable Changes

`queue()` runs one queued job for the whole swarm. `dispatchDurable()` runs one
queued job per sequential agent step and checkpoints the run between steps.

That means a retry re-runs the current step. It does not replay the entire
workflow from the beginning.

## Configuration

```bash
SWARM_PERSISTENCE_DRIVER=database
SWARM_DURABLE_STEP_TIMEOUT=300
```

Run the package migrations before dispatching durable work:

```bash
php artisan migrate
php artisan queue:work
```

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

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
Schedule::command('swarm:prune')->daily();
```

`swarm:recover` supervises checkpointed durable runs. `swarm:prune` handles
retention cleanup for terminal history, context, artifact, and durable rows.
