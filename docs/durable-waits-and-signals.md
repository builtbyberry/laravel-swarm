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

Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)));
```

The scheduler mutex is defense in depth; the command-owned finite lease also
covers manual invocation. See [Command overlap leases](maintenance.md#command-overlap-leases).

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

Wait creation and branch-join release serialize on the run row. If creation
wins, the new wait holds the join. If the join has already been released,
`wait()` throws `SwarmException` before creating a wait or publishing a waiting
event. Register the obligation before releasing the join; retrying that late
call cannot retroactively stop a continuation already queued. This also applies
to a queued hierarchical continuation while its durable coordination row remains
in the released `pending` state. A running step-durable step can create its own
wait, and ordinary pending step boundaries remain supported.

The database coordination uses committed reads. Internally, a creation epoch on
the run detects a wait committed across the join's wait scan; no wait-row lock
is taken after the run lock. An older application snapshot can conservatively
hold a join after its waits have closed. A fresh post-commit release or
`swarm:recover` attempt can release it. Stale display summaries cannot strand the
join. The epoch is internal, has no configuration, and is retained and pruned
with its run. Apply the [upgrade steps](https://github.com/builtbyberry/laravel-swarm/blob/main/UPGRADING.md#wait-creation-at-branch-joins)
before replacing workers.

The package retries database concurrency failures up to three attempts for wait
creation, child-intent persistence, hierarchical fan-out and join release. If
you call these operations inside your own database transaction, retry that
**whole transaction** on a concurrency exception, for example with Laravel's
`DB::transaction($work, attempts: 3)`. A nested savepoint cannot retry an
InnoDB transaction that the engine has aborted. Keep retryable work free of
external side effects.

At a durable hierarchical fan-out, the framework persists its declared wait or
child intent with the checkpoint and branch dispatch records, before branches
can release the join. Notifications and child dispatch run after commit. A
notification failure leaves committed state available to `swarm:recover` and
`swarm:relay`. Inside an application transaction, these effects wait for the
swarm connection's outermost commit; a failure is reported without interrupting
the application's later commit callbacks. Rollback discards the effects.

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
    // The open wait of that name closed with this signal. The run continues
    // when nothing else holds it: not while it is paused, another wait is open,
    // or it is waiting on its branches.
}
```

Signals can still be recorded with `accepted` set to `false` when the run has no
matching open wait, the signal is a duplicate, or the run cannot advance. A
paused run's signal is recorded without being accepted only in the cases listed
under [Signals While Paused](#signals-while-paused). Use
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

Idempotency keys are scoped to a run, so retried webhook deliveries or button
clicks do not duplicate the same signal.

A signal that releases a wait is recorded in the same transaction that releases
it. If that transaction fails — a lock timeout, a deadlock, a crash before it
commits — nothing of the signal is kept, and sending it again with the same
idempotency key accepts it once. Such a failure reaches you only as the exception
`signal()` throws: no signal row, `SwarmSignalled` event or `signal.received`
audit record is left for it, so log the failure and send the signal again. That also holds when you send the signal inside
your own database transaction, and you catch the failure and commit. A signal
sent when no wait of that name is open is recorded without being accepted, and
sending the same key again returns it as a duplicate.

Atomic acceptance is a capability of the bundled database store
(`Contracts\AcceptsDurableSignals`). A custom `DurableRunStore` without it, or a
decorating store that does not implement and forward it, records the signal
before the acceptance: if that acceptance then fails, the signal stays recorded
and the same key comes back as a duplicate.

## Signals While Paused

A signal delivered to a paused run that has an open wait of that name is
accepted: the wait closes with it, its payload and outcome are written to the
run context, and the run stays paused. `accepted` is `true` and `SwarmSignalled`
and the `signal.received` audit record report it as accepted. Nothing runs
while the run is paused. When it is resumed, it has nothing left to wait on and
its next step is queued (or, at a parallel join, it returns to its branches). A
timeout that passed during the pause does not undo it: the wait is already
closed.

Sending the same delivery again after resume with a new idempotency key (the
workaround earlier releases needed) does not apply it twice. The wait it was
meant for is already satisfied, so the second delivery is recorded and not
accepted, and the run is not moved again. That holds for the wait the first
delivery satisfied; if the run later opens a new wait with the same name, a new
signal is needed for that one.

A paused run's signal is recorded but **not** accepted in three cases:

- **It is sent inside a database transaction.** Acceptance depends on the
  transaction level of the swarm's database connection when `signal()` is
  called. None of the package's entry points (the webhook route,
  `swarm:signal`, `SwarmOperator::signal()`, `$response->signal()`) opens a
  transaction, but they run inside yours if you call them from one: a
  transaction middleware, a job or controller using `DB::transaction()`, or
  `Artisan::call('swarm:signal')` inside one. Laravel's `RefreshDatabase` and
  `DatabaseTransactions` test traits count too, including `DB::afterCommit()`
  callbacks that run while the test's own transaction is still open. Inside a
  transaction an acceptance would read the run from your transaction's older
  snapshot and keep the run row locked until you commit.
- **The run has no open wait of that name.** As for any run, a signal sent
  before its wait opens is recorded and never satisfies that wait.
- **The store cannot accept atomically.** A custom `DurableRunStore` without
  `Contracts\AcceptsDurableSignals`, or a decorator that does not forward it,
  keeps the earlier behaviour for paused runs.

A recorded paused-run signal is not applied on resume. Send it again after the
run resumes, with a **new** idempotency key: the original key returns the
earlier delivery as a `duplicate`.

## Operator Signals

Use `swarm:signal` when an operator should continue a run from the console:

```bash
php artisan swarm:signal <run-id> approval_received --payload='{"approved":true}' --idempotency-key=approval-123
```

It prints what happened to the signal and to the run:

| Output | What to do |
| --- | --- |
| `Signal accepted and run released.` | Nothing: the next step is queued. |
| `Signal accepted. The run is paused and continues when it is resumed.` | Resume the run when you are ready (`swarm:resume`). |
| `Signal accepted. The run is still waiting ...` | Nothing: another wait is open, or the run is waiting on its branches. |
| `Duplicate signal ...` | Nothing: that idempotency key is already recorded. |
| `Signal recorded. The wait named [...] already closed ...` | Nothing: an earlier signal or outcome already satisfied it; this one is not applied. |
| `Signal recorded. The run is [completed] ...` | Nothing: the run has finished; no wait will accept it. |
| `Signal recorded. No open wait named [...] ...` | Check the signal name, or wait until the run opens that wait and send it again. |
| `Signal recorded but not accepted. The run is paused ...` | Re-send it after resume with a new idempotency key (see [Signals While Paused](#signals-while-paused)). |

Inspect the run before or after signalling. `--json` lists each wait with its
name and status:

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
Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)));
```

When recovery observes that a wait timeout elapsed, it releases the wait with a
timeout outcome and dispatches the next durable step when the run can continue.

## Edge Cases

- A signal delivered while the run is paused is accepted when the run has an
  open wait of that name, and the run continues once it is resumed. See
  [Signals While Paused](#signals-while-paused) for when it is recorded instead.
- A run waiting at a parallel join with an open wait is not released to its
  join until every wait is closed and every branch is done, whichever comes
  last. Under `fail_run` a failing branch still fails the run at once. See
  [Durable Hierarchical Parallel Flow](durable-execution.md#durable-hierarchical-parallel-flow).
- Accepting a signal while another wait is still open, or while the run is still
  waiting on its branches, leaves the run (and its history) `waiting`.
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
execute the durable runtime. A paused run accepts a signal only outside a
database transaction, so test that with `DatabaseMigrations` or
`DatabaseTruncation` rather than `RefreshDatabase` (see
[Signals While Paused](#signals-while-paused)).

## Related Documentation

- [Durable Execution](durable-execution.md)
- [Durable Webhooks](durable-webhooks.md)
- [Testing](testing.md#database-backed-durable-execution)
- [Durable Runtime Architecture](durable-runtime-architecture.md)
