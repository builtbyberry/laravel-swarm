# Durable Waits And Signals

Shows a durable approval workflow that stops at an operator boundary and
continues after a matching signal.

Use this pattern when a long-running swarm needs human approval, an integration
callback, or another external event before the next agent step should run.

This example teaches:

- `#[DurableWait]` declares a checkpointed wait;
- `#[DurableLabels]` and `#[DurableDetails]` attach operator metadata;
- `#[Timeout]` sets a best-effort orchestration deadline;
- `DurableSwarmManager::signal()` releases a matching wait;
- `swarm:signal` can continue a run from the console.

## Prerequisites

- Laravel AI is configured in your application.
- `SWARM_PERSISTENCE_DRIVER=database`
- `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
- Package migrations have run.
- A queue worker is running.
- `swarm:recover` is scheduled.

## Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ApprovalIntake;
use App\Ai\Agents\ApprovalSummary;
use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[Timeout(600)]
#[DurableLabels(['workflow' => 'approval'])]
#[DurableDetails(['owner' => 'regulatory'])]
#[DurableWait('approval_received', timeout: 86400, reason: 'Waiting for approval')]
class ApprovalSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ApprovalIntake,
            new ApprovalSummary,
        ];
    }
}
```

## Dispatch

```php
use App\Ai\Swarms\ApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ApprovalSwarm::make()
    ->dispatchDurable(
        RunContext::fromTask(['document_id' => $document->id])
            ->withLabels([
                'tenant_id' => $tenant->id,
                'document_id' => $document->id,
            ])
            ->withDetails([
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                ],
            ])
    )
    ->onQueue('swarm-durable');

$runId = $response->runId;
```

## Signal From Application Code

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function approve(string $runId, Request $request, DurableSwarmManager $manager): JsonResponse
{
    $result = $manager->signal(
        runId: $runId,
        name: 'approval_received',
        payload: [
            'approved' => true,
            'approved_by' => $request->user()->id,
        ],
        idempotencyKey: $request->header('Idempotency-Key'),
    );

    return response()->json([
        'run_id' => $result->runId,
        'accepted' => $result->accepted,
        'duplicate' => $result->duplicate,
        'status' => $result->status,
    ], $result->accepted ? 202 : 200);
}
```

`accepted` means a matching open wait was released. A signal can still be
recorded with `accepted=false` when there is no matching wait, the signal is a
duplicate, or the run cannot advance.

## Signal From The Console

```bash
php artisan swarm:signal <run-id> approval_received --payload='{"approved":true}' --idempotency-key=approval-123
```

Inspect the wait and signal state:

```bash
php artisan swarm:inspect <run-id> --json
```

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
Schedule::command('swarm:prune')->daily();
```

## What Happened

The first durable job ran `ApprovalIntake`, checkpointed the run, and entered
the declared wait. When the signal arrived, Laravel Swarm recorded it, released
the matching wait, and dispatched the next durable step. `ApprovalSummary`
received the updated context and could read the signal payload from the run
context.

## Related Documentation

- [Durable Waits And Signals](../../docs/durable-waits-and-signals.md)
- [Durable Execution](../../docs/durable-execution.md)
- [Durable Webhooks](../durable-webhook-ingress/README.md)
