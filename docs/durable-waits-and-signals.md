# Durable Waits And Signals

Durable waits let a swarm checkpoint at an external boundary and stop until an
operator, webhook, or application process sends a matching signal. They are for
approval gates, human review, provider callbacks, document arrival, and other
workflow points where the next agent step should not run yet.

This is Swarm-native checkpointing. Provider calls are never replayed as a
coroutine. A durable run advances at agent-step boundaries, persists its state,
and can be resumed by recovery after the wait is released.

## When To Use Waits

Use a durable wait when:

- the workflow needs approval before continuing
- an external system will call back later
- an operator needs to inspect intermediate state
- a missing document, attachment, or integration result should pause the run
- the workflow needs timeout behavior around an external dependency

Do not use waits for short in-process delays. If the work can continue inside
the same provider call or queue job, keep it inside the agent or dispatch a
normal queued swarm.

## Prerequisites

Durable waits require:

- database-backed swarm persistence
- package migrations
- active context capture for durable dispatch
- a scheduled `swarm:recover` command

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
```

## Starting A Durable Run

Use labels and details when operators or UI surfaces need to find the run later:

```php
use App\Ai\Swarms\ApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$response = ApprovalSwarm::make()->dispatchDurable(
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
);

$runId = $response->runId;
```

## Labels And Details

Use `#[DurableLabels]` and `#[DurableDetails]` when every run of a swarm should
start with the same operator metadata:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableDetails;
use BuiltByBerry\LaravelSwarm\Attributes\DurableLabels;

#[DurableLabels(['workflow' => 'approval'])]
#[DurableDetails(['owner' => 'regulatory'])]
class ApprovalSwarm implements Swarm
{
    use Runnable;
}
```

Use `RunContext::withLabels()` and `RunContext::withDetails()` when the values
are known only for a specific run, such as tenant IDs, document IDs, user IDs,
or request-specific display data.

Runtime context values are applied to the durable run at dispatch. Attribute
values are applied while the durable run is being started. If both set the same
key, use one source consistently for that key so operator filters stay
predictable.

## Creating A Wait From Application Code

Create a wait through `DurableSwarmManager` when the boundary is owned by your
application rather than declared on the swarm:

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

app(DurableSwarmManager::class)->wait(
    runId: $runId,
    name: 'approval_received',
    reason: 'Waiting for regulatory approval',
    timeoutSeconds: 86400,
    metadata: [
        'requested_by' => $request->user()->id,
    ],
);
```

The wait is stored in the durable runtime tables. A waiting run remains active
operational state until it is signalled, times out, fails, completes, or is
cancelled.

## Declaring Waits On A Swarm

Use `#[DurableWait]` when a swarm always enters the same wait after a checkpoint:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableWait;

#[DurableWait('approval_received', timeout: 86400, reason: 'Waiting for approval')]
class ApprovalSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new IntakeAgent,
            new RiskReviewAgent,
            new ApprovalSummaryAgent,
        ];
    }
}
```

Use `RoutesDurableWaits` when waits depend on the current run context:

```php
use BuiltByBerry\LaravelSwarm\Contracts\RoutesDurableWaits;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class ApprovalSwarm implements Swarm, RoutesDurableWaits
{
    use Runnable;

    public function durableWaits(RunContext $context): array
    {
        if ($context->label('requires_review') !== true) {
            return [];
        }

        return [
            [
                'name' => 'approval_received',
                'timeout' => 86400,
                'reason' => 'Waiting for reviewer approval',
                'metadata' => ['queue' => 'regulatory-review'],
            ],
        ];
    }
}
```

Declared waits are entered after an agent-step checkpoint. That means recovery
can resume the run without replaying the provider call that completed before the
wait.

## Sending Signals

Send a signal through the durable response:

```php
$result = $response->signal(
    'approval_received',
    ['approved' => true, 'approved_by' => $request->user()->id],
    idempotencyKey: $request->header('Idempotency-Key'),
);

if ($result->accepted) {
    // A matching wait was released and the next durable step can be dispatched.
}
```

Signals can still be recorded with `accepted` set to `false` when the run has no
matching open wait, the signal is a duplicate, or the run cannot advance. Use
`$result->status` and `$result->duplicate` when your application needs to
distinguish those outcomes.

Or send it through the manager when you only have the run ID:

```php
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

$result = app(DurableSwarmManager::class)->signal(
    runId: $runId,
    name: 'approval_received',
    payload: ['approved' => true],
    idempotencyKey: 'approval-123',
);
```

Signals are stored before release is attempted. Idempotency keys are scoped to a
run, so retried webhook deliveries or button clicks do not duplicate the same
signal.

## Operator Signals

Use `swarm:signal` when an operator should continue a run from the console:

```bash
php artisan swarm:signal <run-id> approval_received --payload='{"approved":true}' --idempotency-key=approval-123
```

Inspect the run before or after signalling:

```bash
php artisan swarm:inspect <run-id>
php artisan swarm:inspect <run-id> --json
```

## Reading Signals In Agents

Agents receive the updated `RunContext` on later durable steps. Use the context
helpers when your agent or swarm logic needs signal data:

```php
$approval = $context->signalPayload('approval_received');
$outcome = $context->waitOutcome('approval_received');
```

The signal payload is sensitive operational data. Store only the fields needed
for the next step, and avoid secrets in metadata.

## Timeouts

Wait timeouts are observed by recovery. Schedule `swarm:recover` frequently
enough for your operational expectations:

```php
Schedule::command('swarm:recover')->everyMinute();
```

When recovery observes that a wait timeout elapsed, it releases the wait with a
timeout outcome and dispatches the next durable step when the run can continue.

## Edge Cases

- Paused runs retain signals but do not advance until resumed.
- Cancelled runs ignore later signals.
- Duplicate signals with the same idempotency key return the stored outcome.
- Signals sent before a matching wait exists are still recorded on the run.
- Waits, signals, payloads, and metadata follow the same retention, capture, and
  redaction rules as other durable operational records.

## Testing

Use fakes for application-level intent:

```php
ApprovalSwarm::fake();

ApprovalSwarm::make()->dispatchDurable(['document_id' => 100]);

ApprovalSwarm::assertDispatchedDurably(['document_id' => 100]);
```

Use database-backed feature tests when you need to prove wait creation, signal
release, timeout recovery, paused behavior, or idempotency. `SwarmFake` does not
execute the durable runtime.

## Related Documentation

- [Durable Execution](durable-execution.md)
- [Durable Webhooks](durable-webhooks.md)
- [Testing](testing.md#database-backed-durable-execution)
- [Durable Runtime Architecture](durable-runtime-architecture.md)
