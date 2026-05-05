# Durable Retries, Progress, And Child Swarms

Shows a parent durable workflow that records progress, retries transient
failures, and dispatches a child durable swarm for separately inspectable work.

Use this pattern when one production workflow needs both operator visibility and
delegated durable sub-work.

This example teaches:

- `#[DurableRetry]` configures durable retry attempts and backoff;
- `#[MaxAgentSteps]` bounds the number of agent executions;
- `recordProgress()` stores latest-state progress for inspection;
- `DispatchesChildSwarms` declares child durable work from context;
- child swarms keep their own run ID, history, progress, and terminal state.

## Prerequisites

- Laravel AI is configured in your application.
- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- Package migrations have run.
- A queue worker is running.
- `swarm:recover` is scheduled.

## Parent Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ComplianceIntake;
use App\Ai\Agents\ComplianceSummary;
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[MaxAgentSteps(5)]
#[DurableRetry(maxAttempts: 3, backoffSeconds: [10, 60, 300])]
class ComplianceParentSwarm implements Swarm, DispatchesChildSwarms
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ComplianceIntake,
            new ComplianceSummary,
        ];
    }

    public function durableChildSwarms(RunContext $context): array
    {
        $documentId = $context->data['document_id'] ?? null;

        if ($documentId === null) {
            return [];
        }

        return [
            [
                'swarm' => DocumentRiskReviewSwarm::class,
                'task' => ['document_id' => $documentId],
            ],
        ];
    }
}
```

## Child Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\DocumentRiskReviewer;
use BuiltByBerry\LaravelSwarm\Attributes\DurableRetry;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[DurableRetry(maxAttempts: 2, backoffSeconds: [30, 120])]
class DocumentRiskReviewSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new DocumentRiskReviewer,
        ];
    }
}
```

## Dispatch

```php
use App\Ai\Swarms\ComplianceParentSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ComplianceParentSwarm::make()
    ->dispatchDurable(
        RunContext::fromTask(['document_id' => $document->id])
            ->withLabels(['document_id' => $document->id])
    )
    ->onQueue('swarm-durable');

$response->runId;
```

## Record Progress

Agents and application services can record latest-state progress when they know
the run ID:

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

app(DurableSwarmManager::class)->recordProgress($runId, progress: [
    'stage' => 'extracting-document-facts',
    'completed' => 12,
    'total' => 30,
]);
```

Inspect progress:

```bash
php artisan swarm:progress <run-id>
php artisan swarm:inspect <run-id> --json
```

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

## What Happened

The parent durable run checkpointed after an agent step, declared child work,
and entered a child wait. The child swarm was dispatched as its own durable run.
When the child completed or failed, recovery reconciled the child terminal
state, released the parent wait, and dispatched the parent next step. Retry
policy applied to retryable parent steps and child steps without replaying the
entire workflow.

## Testing

Use fakes for intent:

```php
ComplianceParentSwarm::fake()
    ->recordDurableProgress(['stage' => 'extracting-document-facts'])
    ->recordDurableRetry(['max_attempts' => 3])
    ->recordDurableChildSwarm(DocumentRiskReviewSwarm::class, ['document_id' => 100]);

ComplianceParentSwarm::assertDurableProgressRecorded(['stage' => 'extracting-document-facts']);
ComplianceParentSwarm::assertDurableRetryScheduled(['max_attempts' => 3]);
ComplianceParentSwarm::assertDurableChildSwarmDispatched(DocumentRiskReviewSwarm::class);
```

Use database-backed feature tests for actual retry scheduling, progress
inspection, child dispatch rows, parent wait release, and recovery.

## Related Documentation

- [Durable Retries And Progress](../../docs/durable-retries-and-progress.md)
- [Durable Child Swarms](../../docs/durable-child-swarms.md)
- [Testing](../../docs/testing.md)
