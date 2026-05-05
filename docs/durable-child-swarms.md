# Durable Child Swarms

Durable child swarms let a parent durable run start another durable swarm and
retain lineage for inspection. The child is a normal durable run with its own
run ID, history, labels, details, progress, waits, signals, and terminal state.

Use child swarms when one durable workflow needs to delegate a separately
observable unit of work without copying all child output back into the parent
context.

## When To Use Child Swarms

Use a child swarm when:

- a parent workflow needs to start a reusable durable workflow
- the child should have its own operator controls and history
- parent and child retention or inspection should be separate
- the parent should wait for the child terminal state before continuing
- the child may be cancelled with the parent

Do not use a child swarm just to split one sequential workflow into smaller
files. If the work is always part of one durable run and does not need separate
lineage, keep it as agents inside the same swarm.

## Prerequisites

Child swarms require durable execution:

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true
```

Schedule recovery so child dispatch intent and terminal reconciliation are
processed:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyMinute();
```

## Dispatching A Child Swarm

Dispatch a child through `DurableSwarmManager` when the parent run ID is known:

```php
use App\Ai\Swarms\ReviewSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;

$child = app(DurableSwarmManager::class)->dispatchChildSwarm(
    parentRunId: $parentRunId,
    childSwarmClass: ReviewSwarm::class,
    task: ['document_id' => $documentId],
    dedupeKey: 'review:'.$documentId,
);

$child->parentRunId;
$child->childRunId;
$child->childSwarmClass;
```

`dedupeKey` is optional. Use it when retried parent work might request the same
child intent again.

The parent-child relation is stored in `swarm_durable_child_runs`. UI surfaces
should use that lineage instead of inferring relationships from prompt text,
metadata, or labels.

## Declaring Child Swarms From A Parent

Use `DispatchesChildSwarms` when the parent swarm should declare child work from
its current context:

```php
use App\Ai\Swarms\ReviewSwarm;
use BuiltByBerry\LaravelSwarm\Contracts\DispatchesChildSwarms;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class ComplianceParentSwarm implements Swarm, DispatchesChildSwarms
{
    use Runnable;

    public function durableChildSwarms(RunContext $context): array
    {
        $documentId = $context->data['document_id'] ?? null;

        if ($documentId === null) {
            return [];
        }

        return [
            [
                'swarm' => ReviewSwarm::class,
                'task' => ['document_id' => $documentId],
            ],
        ];
    }
}
```

The declared child is converted into durable child dispatch intent after a
checkpoint, so the parent can recover without replaying the provider call that
created the intent.

## What Happens At Runtime

Dispatching a child checkpoints the parent into a durable child wait. The child
durable run is dispatched with its own run ID and runtime state.

When the child reaches a terminal state, recovery reconciles the child row,
writes the terminal status to parent metadata at
`durable_child_runs.{childRunId}`, releases the parent wait, and dispatches the
parent next step.

Child output and failure details stay on the child lineage row and child run
history. They are not copied wholesale into the parent runtime context.

## Inspecting Parent And Child Runs

Inspect the parent:

```bash
php artisan swarm:inspect <parent-run-id> --json
```

Inspect the child:

```bash
php artisan swarm:inspect <child-run-id> --json
```

Use `SwarmHistory` or the run inspector example to combine parent history,
child lineage, progress, and operator state for application dashboards.

## Cancellation And Failure

Parent cancellation cancels active child durable runs by default. Completed
children remain terminal. Child failures are recorded on the child run and
lineage row; the parent observes the terminal child state through reconciliation.

Use application code or a later agent step to decide how the parent should
interpret failed child work.

## Capture And Privacy

Child outputs and failures can contain sensitive data. They follow the same
capture, encryption, redaction, and pruning rules as other durable operational
records.

Keep child task payloads plain and minimal:

```php
['document_id' => $document->id]
```

Avoid sending full documents, secrets, or unbounded arrays unless your
application has explicitly configured capture and retention for that data.

## Testing

Use fake assertions for intent:

```php
ComplianceParentSwarm::fake()
    ->recordDurableChildSwarm(ReviewSwarm::class, ['document_id' => 100]);

ComplianceParentSwarm::assertDurableChildSwarmDispatched(ReviewSwarm::class);
```

Use database-backed feature tests for child dispatch rows, parent wait release,
terminal reconciliation, dedupe behavior, recovery, and parent cancellation.

## Related Documentation

- [Durable Execution](durable-execution.md)
- [Durable Waits And Signals](durable-waits-and-signals.md)
- [Run Inspector example](../examples/run-inspector/README.md)
- [Durable Runtime Architecture](durable-runtime-architecture.md)
