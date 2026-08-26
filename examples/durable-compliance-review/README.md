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

## v0.5 Audit Chain Walkthrough

`halt` is still the right setting when you absolutely cannot let a run continue
without proof of evidence emission. But most regulated workloads now want the
**default** v0.5 policy, `queue`, which keeps the run moving while persisting
the failed evidence record to `swarm_audit_outbox` for retry. The package then
ships with `swarm:audit:status`, `swarm:audit:reconcile`, and the existing
`swarm:relay --type=audit` lane so operators have a complete forensic loop.

This section walks through that loop end-to-end against a simulated downstream
sink outage. Everything below is **copy-pasteable** into a Tinker session
against a database-backed install once you have a sink bound and migrations
run.

### Baseline Configuration

For a regulated compliance review with the v0.5 default chain, your `.env`
should look like:

```bash
SWARM_PERSISTENCE_DRIVER=database
SWARM_CAPTURE_ACTIVE_CONTEXT=true

# v0.5 defaults — listed explicitly so operators see them in one place
SWARM_AUDIT_FAILURE_POLICY=queue
SWARM_ENCRYPT_AT_REST=true
SWARM_AUDIT_ACTOR_REQUIRED=true

# Audit outbox tuning — keep the retention window inside your compliance
# review SLA (90 days is a common Part 11-style starting point).
SWARM_AUDIT_OUTBOX_MAX_ATTEMPTS=5
SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS=90
```

`failure_policy=queue` is the v0.5 default; `encrypt_at_rest=true` is the
default on database persistence. They are listed here so the configuration
surface for regulated callers is visible in one place.

You also need a bound `SwarmAuditSink` (the no-op default does not emit
anywhere) and a bound `SwarmAuditSigner` so emitted records carry a signature
under your current key.

### Bind The Sink And Signer

```php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use App\Audit\HmacAuditSigner;
use App\Audit\HttpAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SwarmAuditSink::class, HttpAuditSink::class);

        $this->app->singleton(
            SwarmAuditSigner::class,
            fn () => new HmacAuditSigner(config('audit.hmac_secret')),
        );
    }
}
```

`HttpAuditSink` is your application's real sink — typically a thin wrapper that
ships the payload to a SIEM, append-only object store, or signed log service.
The signer pattern lives in
[`privacy-capture/README.md`](../privacy-capture/README.md#sign-audit-evidence).

### Simulated Sink Outage

To demonstrate the recovery loop you can substitute a **test-only sink** that
throws on its first N emit attempts, then succeeds. The package's test suite
uses `tests/Fixtures/CountingThrowingSink.php` for the same purpose. Examples
cannot import test fixtures, so define an example-side equivalent:

```php
// app/Audit/RecoveringSinkDemo.php
namespace App\Audit;

use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WHY: This sink exists ONLY to demonstrate the v0.5 audit-outbox recovery
 * loop. It is not a real sink. It deterministically fails its first N emit
 * attempts and then begins succeeding — which is exactly the shape of a
 * downstream incident (a few queued retries, then a recovered sink).
 *
 * In a real deployment, your bound sink should be backed by an actual
 * append-only store; bind THIS class only inside a Tinker session, a feature
 * test, or a dedicated demo environment.
 */
final class RecoveringSinkDemo implements SwarmAuditSink
{
    public int $attempts = 0;

    public function __construct(private readonly int $failFirstN = 3) {}

    public function emit(string $category, array $payload): void
    {
        $this->attempts++;

        if ($this->attempts <= $this->failFirstN) {
            Log::warning("RecoveringSinkDemo: simulated failure #{$this->attempts} for {$category}");
            throw new RuntimeException("simulated sink outage #{$this->attempts}");
        }

        // After the simulated outage, succeed silently. A real sink would
        // ship the payload here.
    }
}
```

Bind it in a Tinker session for the duration of the walkthrough:

```php
use App\Audit\RecoveringSinkDemo;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

app()->singleton(SwarmAuditSink::class, fn () => new RecoveringSinkDemo(failFirstN: 3));
```

Now dispatch the compliance review:

```php
use App\Ai\Swarms\ComplianceReviewSwarm;
use Illuminate\Support\Facades\Context;

Context::add('swarm:actor', auth()->user()); // satisfies actor.required=true

ComplianceReviewSwarm::make()->dispatchDurable([
    'document_id' => 1234,
    'document_type' => 'vendor contract',
    'jurisdiction' => 'US',
    'review_goal' => 'identify renewal and termination risk',
]);
```

The run advances normally — `failure_policy=queue` means audit-sink failures
**do not halt the swarm**. They land in `swarm_audit_outbox` instead.

### Step 1 — Rows Accumulating During The Outage

After a few lifecycle events have tried to emit (`run.started`,
`step.completed`, etc.) and the sink has refused them, the outbox starts
filling up. `swarm:audit:status` is your one-shot summary:

```bash
php artisan swarm:audit:status
```

```
  INFO  Audit outbox summary.

  ----------------------- -------
   Status                  Count
  ----------------------- -------
   pending (unclaimed)     3
   reserved                0
     stale (> 120s)        0
   dead_letter             0
  ----------------------- -------

  INFO  Age distribution.

  ------------- ------ ------- ------ ------
   Status        < 1h   1-24h   1-7d   > 7d
  ------------- ------ ------- ------ ------
   pending       3      0       0      0
   dead_letter   0      0       0      0
  ------------- ------ ------- ------ ------

  INFO  Top dead-letter categories.

  • no dead-letter rows

  INFO  Oldest rows.

  ------------- ----- -----
   Status        ID    Age
  ------------- ----- -----
   pending       12    42s
   dead_letter   n/a   n/a
  ------------- ----- -----

  INFO  Retention.

  • dead_letter_retention_days: 90
  • next-prune count: 0
```

`swarm:health --audit` raises the same signal in a format suited to a
healthcheck endpoint or alerting integration:

```bash
php artisan swarm:health --audit
```

```
  -------------------------- ---------- ------- --------- ------------------------------------
   Component                  Driver     Store   Status    Details
  -------------------------- ---------- ------- --------- ------------------------------------
   Audit outbox staleness     database   n/a     ok        3 pending row(s), relay appears active
   Audit outbox dead-letter   database   n/a     ok        0 dead-letter row(s)
  -------------------------- ---------- ------- --------- ------------------------------------
```

If `swarm:relay` is not scheduled (or has stopped running for some reason),
the staleness check escalates to `warning` after 2× the reservation timeout.
For Part 11 / regulated callers, treat any staleness warning as page-worthy:
it means evidence is queued but not draining.

### Step 2 — Recovery Via The Relay

The scheduled `swarm:relay` runs every minute by default. To trigger it
immediately in the walkthrough:

```bash
php artisan swarm:relay --type=audit --drain-until-empty
```

```
  INFO  Replayed 3 audit records.
```

The relay calls `RecoveringSinkDemo::emit()` for each pending row. With
`failFirstN=3` already exhausted by the original synchronous attempts, every
replay call now succeeds and the rows are deleted from `swarm_audit_outbox`.
A follow-up `swarm:audit:status` shows an empty outbox.

The `command.relay` audit record itself is emitted at the end of the run with
`audit_replayed_count`, `audit_dead_lettered_count`, and a `status` field so
you can audit the recovery action.

### Step 3 — Dead-Letter Triage

To demonstrate the dead-letter path, raise the failure window past
`max_attempts`. With `SWARM_AUDIT_OUTBOX_MAX_ATTEMPTS=5`, a sink that fails
six times will push at least one row into `dead_letter` status:

```php
app()->singleton(SwarmAuditSink::class, fn () => new RecoveringSinkDemo(failFirstN: 999));
```

Dispatch a run, then drain repeatedly (or wait for the scheduled relay to run
through five attempts on the same row over its reservation-timeout cycles).
After the row exceeds `max_attempts`:

```bash
php artisan swarm:audit:status
```

```
  INFO  Audit outbox summary.

  ----------------------- -------
   Status                  Count
  ----------------------- -------
   pending (unclaimed)     0
   reserved                0
     stale (> 120s)        0
   dead_letter             1
  ----------------------- -------

  INFO  Top dead-letter categories.

  ------------- -------
   Category      Count
  ------------- -------
   run.started   1
  ------------- -------

  INFO  Oldest rows.

  ------------- ----- ------
   Status        ID    Age
  ------------- ----- ------
   pending       n/a   n/a
   dead_letter   18    4m
  ------------- ----- ------
```

`swarm:health --audit` now warns:

```
  -------------------------- ---------- ------- ---------- -----------------------------------------------------------------
   Component                  Driver     Store   Status     Details
  -------------------------- ---------- ------- ---------- -----------------------------------------------------------------
   Audit outbox staleness     database   n/a     ok         no pending rows
   Audit outbox dead-letter   database   n/a     warning    1 dead-letter row(s) — undelivered audit evidence requires operator reconciliation
  -------------------------- ---------- ------- ---------- -----------------------------------------------------------------
```

Inspect the row. `--show` unseals the payload from `swarm_audit_outbox`,
which is normally encrypted at rest, and prints it for human review:

```bash
php artisan swarm:audit:reconcile --show=18
```

```
  -------------- -------------------------------------
   Field          Value
  -------------- -------------------------------------
   ID             18
   Status         dead_letter
   Category       run.started
   Run ID         r-019035fa-b1d2-7c8a-...
   Attempts       5
   Created        2026-05-19T14:02:11+00:00
   Updated        2026-05-19T14:06:48+00:00
   Last attempted 2026-05-19T14:06:48+00:00
   Reserved       -
  -------------- -------------------------------------

  INFO  Last error.

  simulated sink outage #5

  INFO  Payload.

  {
      "schema_version": "2",
      "category": "run.started",
      "occurred_at": "2026-05-19T14:02:11+00:00",
      "run_id": "r-019035fa-b1d2-7c8a-...",
      "swarm_class": "App\\Ai\\Swarms\\ComplianceReviewSwarm",
      "topology": "sequential",
      "execution_mode": "durable",
      "actor": {
          "type": "user",
          "id": "u-42",
          "name": "Daniel Berry"
      },
      "signature": "...",
      "signature_algorithm": "HMAC-SHA256",
      "signed_at": "2026-05-19T14:02:11+00:00"
   }
```

Operators now have two choices, both backed by a forced `command.audit_reconcile`
audit record that is emitted **before** the row is mutated. If that audit emit
fails, the dead-letter row is left untouched, so reconciliation cannot silently
erase evidence.

**Requeue** — the downstream sink is restored and you want the relay to
re-attempt emission. `attempts` is zeroed; `last_error` is preserved as
forensic context:

```bash
php artisan swarm:audit:reconcile --requeue=18 --reason="downstream sink restored after vendor outage 2026-05-19"
```

```
 Requeue audit outbox row [18] (category=run.started, attempts=5)? (yes/no) [no]:
 > yes

  INFO  Audit outbox row [18] requeued. Status=pending, attempts=0. last_error preserved for forensics.
```

**Dismiss** — the record is a verified duplicate, was emitted out-of-band, or
otherwise cannot be delivered. `--reason` is required:

```bash
php artisan swarm:audit:reconcile --dismiss=18 --reason="duplicate of run.failed emitted manually via vendor support ticket SUP-4419"
```

```
 Permanently delete audit outbox row [18] (category=run.started, run_id=r-019035fa-...)? (yes/no) [no]:
 > yes

  INFO  Audit outbox row [18] dismissed. A command.audit_reconcile evidence record preserves the deletion.
```

For scripted recovery (for example, a runbook automation), pass `--force` to
skip the confirmation prompt. Both sub-modes accept `--json` for machine
parsing.

### Forward Reference: Operator Runbook

This section is a **demonstration**, not a full runbook. The end-to-end
operator decision tree for audit-outbox triage — incident playbooks, on-call
escalation, retention-policy alignment, and chain-of-custody templates — lives
in [`docs/operator-runbook-audit-outbox.md`](../../docs/operator-runbook-audit-outbox.md)
(GitHub issue #45).

See also:

- [`docs/audit-evidence-contract.md`](../../docs/audit-evidence-contract.md) — frozen audit evidence and outbox contract.
- [`docs/maintenance.md`](../../docs/maintenance.md) — `Audit outbox` and `Forensic triage` operations sections.

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

Schedule::command('swarm:recover')->everyFiveMinutes()->withoutOverlapping(max(1, (int) ceil(config('swarm.commands.overlap.lease_seconds', 3600) / 60)));
Schedule::command('swarm:prune')->daily();
```

`swarm:recover` supervises checkpointed durable runs. `swarm:prune` handles
retention cleanup for terminal history, context, artifact, and durable rows.
