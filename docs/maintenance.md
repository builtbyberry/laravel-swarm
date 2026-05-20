# Maintenance

Laravel Swarm's database-backed persistence uses prune-based TTL retention.

`ttlSeconds` is still applied when context, artifacts, run history, and
persisted stream replay rows are written, but database records remain queryable
until you prune expired rows.

Swarm tables are **operational workflow storage**. Pruning **deletes** expired
rows; it does not meet immutable audit-log expectations by itself.

For regulated environments, Laravel Swarm provides a first-class audit evidence
contract. Bind `SwarmAuditSink` in your service provider to receive stable,
normalized evidence records from run lifecycle, step lifecycle, durable state
transitions, operator commands, wait/signal flows, webhook idempotency paths,
and prune/recover operations. See
[Audit Evidence Contract](audit-evidence-contract.md) for the full reference,
payload schema, and production checklist.

## Pruning Expired Records

Use the built-in prune command to remove expired records from the swarm
database tables:

```bash
php artisan swarm:prune
```

Preview how many rows would be deleted **without** deleting:

```bash
php artisan swarm:prune --dry-run
```

Dry-run prints the same category totals as a normal prune (prefixed with “Would
prune”) so operators can judge impact before scheduling aggressive retention.

To disable **destructive** pruning entirely (for example when retention is
handled elsewhere), set `SWARM_PREVENT_PRUNE=true` or
`swarm.retention.prevent_prune` to true in config. Scheduled `swarm:prune`
then exits successfully without deleting rows. **`--dry-run` still runs** so you
can inspect counts while pruning is disabled.

The command prunes the history, context, artifact, stream replay, durable
runtime, durable node state, durable run state, durable node-output, durable
branch, signal, wait, label, detail, progress, child-run, and durable webhook
idempotency tables in bounded chunks to avoid long-running table locks on large
datasets.

Laravel Swarm protects active runs across persistence stores. While a run is
`pending`, `running`, `waiting`, or `paused`, its history, context, artifact,
stream replay, durable runtime, durable node state, durable run state,
node-output, and branch rows are not pruned, even if their retention window has
elapsed.

History pruning only removes expired terminal rows (`completed`, `failed`, and
`cancelled`). Context and artifact pruning skip rows that belong to active runs.
Durable runtime pruning removes terminal runtime rows once their matching
history row is expired.

Durable webhook idempotency pruning removes rows tied to expired terminal run
history. It also removes stale no-run `failed` or `reserved` rows using
`swarm.durable.webhooks.idempotency_ttl`, which is configured through
`SWARM_WEBHOOK_IDEMPOTENCY_TTL`. Completed rows with a `run_id` remain aligned
with durable history retention.

`swarm:prune` is schema-aware. If the history table is missing, the command
skips all pruning because active-run safety depends on history. If history
exists but the context, artifact, or durable runtime table is missing, the
command skips that table role and reports the skip while pruning the tables
that are present. If the durable branch table is missing, branch pruning is
silently skipped so environments that have not run the durable branch migration
can still prune older persistence tables.

If you override `swarm.tables.*`, the prune command respects those configured
table roles directly. It does not rely on default table-name patterns to decide
which rows are safe to delete.

### Foreign-key constraints and prune order

The package migration
`2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables` adds `ON DELETE CASCADE`
foreign keys from every child table to its parent (`swarm_run_histories` for the
history family, `swarm_durable_runs` for the durable family). The prune command
deletes parents before children, so the cascade fires on already-targeted rows
and does not produce orphan rows or constraint errors.

`swarm_durable_runs.parent_run_id` and `swarm_durable_webhook_idempotency.run_id`
use `ON DELETE SET NULL` so a pruned parent does not block child-run or
idempotency-record retention. `swarm_durable_child_runs.child_run_id` has **no
foreign key**: the referenced durable run may be pruned on its own retention
timeline without affecting the parent's child-run registry.

**Custom table names:** If you publish and rename any of these tables, your
published migration copies must include the equivalent `ON DELETE CASCADE` /
`ON DELETE SET NULL` constraints. Without them, orphan rows can accumulate once
the default FKs are removed from the original table names.

## Migration Notes

Laravel Swarm's package migrations are intentionally simple Laravel migrations.
For most applications the swarm persistence tables are operational tables with
short retention windows, so standard package migration workflows are enough.

**Opting out of migration autoloading**

The package loads its migrations automatically by default regardless of the configured persistence driver. If your application uses only the `cache` persistence driver and you do not want the swarm tables created, call `LaravelSwarm::ignoreMigrations()` from `AppServiceProvider::register()`:

```php
use BuiltByBerry\LaravelSwarm\LaravelSwarm;

public function register(): void
{
    LaravelSwarm::ignoreMigrations();
}
```

This follows the same idiom as Cashier, Sanctum, Passport, Horizon, and Telescope. The `swarm-migrations` publish tag remains available regardless, so the migrations can still be published and customized if needed.

If you later switch to the `database` persistence driver, remove the `ignoreMigrations()` call and run `php artisan migrate`. Alternatively, publish the migrations with `php artisan vendor:publish --tag=swarm-migrations` and manage them from your application's migration directory.

The v0.1.5 migration widens `swarm_contexts.input` from `text` to `longText` so
structured and durable prompts are not truncated by database context
persistence. On a heavily populated MySQL or MariaDB table, even a widening
change can take a table lock depending on engine and version. Run package
migrations during a normal maintenance window if you already have high-volume
swarm context data.

The rollback narrows that column back to `text`. Do not roll it back after
storing prompts larger than the database `text` limit unless you have already
pruned or exported those rows.

## Scheduling

If you are using the database persistence driver in production, schedule the
prune command in Laravel's scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:prune')->daily();
```

Use `swarm:health` in deployment checks to verify the configured context,
artifact, history, and stream replay stores before application traffic reaches
swarm execution:

```bash
php artisan swarm:health
```

For deployments using `dispatchDurable()` or coordinated multi-worker
hierarchical queueing, include the durable runtime tables:

```bash
php artisan swarm:health --durable
```

If you are using durable execution, also schedule the relay and recovery commands:

```php
use Illuminate\Support\Facades\Schedule;

// Required: drain the durable outbox so steps/branches are dispatched after each checkpoint.
Schedule::command('swarm:relay')->everyMinute();

// Safety net: redispatch runs that were checkpointed but never received a queue job.
Schedule::command('swarm:recover')->everyFiveMinutes();
```

`swarm:relay` must run at least as frequently as you want checkpointed steps to
advance. The default limit is 100 rows per run; pass `--limit` to tune, or
`--drain-until-empty` to clear a backlog in a single invocation. Without the
relay scheduled, durable runs stall at the first step.

The same `swarm:recover` loop redispatches **coordinated queue hierarchical parallel** joins (`coordination_profile = queue_hierarchical_parallel` on `swarm_durable_runs`): stale `waiting` parents with terminal branch rows release to `pending` and enqueue `ResumeQueuedHierarchicalSwarm`, and branch rows are recovered the same way as durable parallel branches.

`swarm:recover` is not a cleanup task like pruning. It is the safety net for
durable runs that were checkpointed successfully but never dispatched their next
step because a worker crashed or exited at the wrong moment. For durable
parallel work, recovery also releases waiting parents whose branch rows are all
terminal, covering the crash window between a branch checkpoint and parent join
dispatch.
For durable run waits, recovery releases timed-out waits back to pending so the
next durable step can observe a timeout outcome.

**Two intentional exceptions to the outbox guarantee:**

- **Step 0 (`dispatchDurable()`)** — The very first job (advancing step 0) is
  dispatched inline, not through the outbox. The run row doesn't exist yet at
  dispatch time, so there is no FK target for an outbox row. If the worker
  crashes between the run-creation transaction committing and the queue driver
  accepting the job, `swarm:recover` picks up the stuck `pending` run and
  redispatches it on the next poll.

- **`swarm:recover` itself** — Recovery dispatches directly without writing to
  the outbox. This is by design: recover is the repair tool for exactly the
  crash window the outbox prevents, so it cannot use the outbox to dispatch
  (that would be circular). Recovery has the same narrow crash window as
  pre-outbox dispatch; if recover crashes mid-dispatch, the run remains
  `pending` and the next `swarm:recover` invocation redispatches it.

Prune-based retention is complementary to queue design, not a substitute for
it. The built-in lightweight queue mode is a good fit for normal background
jobs, but very long-running workflows may still outgrow the practical limits of
a single queued job. For those workflows, use `dispatchDurable()` instead of
stretching `queue()` beyond what one job should own.

### Recommended queue topology

Three opinionated patterns from simplest to most isolated. Choose the one that matches your deployment.

**Minimal** — single queue, suitable for development or non-durable use:

```bash
php artisan queue:work --queue=default
```

When to use: no durable execution, or a short-lived pilot where worker isolation is not yet needed.

**Durable sequential** — dedicated queue and worker pool for durable work:

```bash
# Application workers
php artisan queue:work --queue=default --timeout=60

# Durable workers — timeout must exceed swarm.durable.step_timeout + swarm.durable.job.timeout_margin_seconds
php artisan queue:work --queue=swarm-durable --timeout=120 --tries=3
```

Set `SWARM_DURABLE_QUEUE=swarm-durable`. The queue connection `retry_after` must exceed the worker `--timeout`; set it in `config/queue.php` for the connection your durable queue uses.

When to use: any production durable workflow. Keeps durable step jobs off the application queue so a slow AI step does not delay unrelated background work.

**Durable with parallel branches** — adds a third pool for branch jobs:

```bash
php artisan queue:work --queue=default --timeout=60
php artisan queue:work --queue=swarm-durable --timeout=120 --tries=3
php artisan queue:work --queue=swarm-branches --timeout=120 --tries=3
```

Set `SWARM_DURABLE_PARALLEL_QUEUE=swarm-branches`. Without this separation, branch jobs queue behind sequential step jobs on the same connection. On a saturated step queue, branches can never start — a deadlock that silently stalls the entire parallel group.

When to use: parallel or hierarchical durable swarms in production.

## Audit outbox

If you are responding to a page, see the
[Operator Runbook: Audit Outbox Triage](operator-runbook-audit-outbox.md).
This section is the reference; the runbook is the decision tree.

Since v0.5.0, `SWARM_AUDIT_FAILURE_POLICY` defaults to `queue` and audit
sink failures are persisted to a new `swarm_audit_outbox` table for retry
through the same `swarm:relay` schedule that handles durable dispatches.

### Migration and scheduling

On database persistence, run `php artisan migrate` to create the
`swarm_audit_outbox` table. The existing relay schedule covers both lanes:

```php
Schedule::command('swarm:relay')->everyMinute();
```

To drain a single lane during focused recovery:

```bash
php artisan swarm:relay --type=audit    # audit only
php artisan swarm:relay --type=step --type=branch    # durable only
```

On cache persistence the audit outbox is unavailable and the dispatcher
falls back to log-and-swallow automatically; no migration required.

### Health checks

`swarm:health` runs two audit-outbox checks on every invocation by default:

- **Staleness** — pending rows whose `reserved_at` aged past 2× the relay
  reservation timeout. A warning here means `swarm:relay` is not running.
- **Dead-letter count** — any non-zero count of `status='dead_letter'`
  rows. For Part 11 / regulated callers, every dead-letter row is a
  compliance signal: an audit event that was supposed to land in the sink
  but never will without operator reconciliation. The dispatcher also
  emits `Log::error` at the moment of transition so log aggregators can
  alert on it.

Use `swarm:health --audit` to run only the audit checks during incident
investigation. Both checks skip silently on the cache persistence driver.

### Retention

Dead-letter records persist indefinitely by default
(`swarm.audit.outbox.dead_letter_retention_days = null`). This is the
audit-safe default — deleting unreconciled audit evidence before the
operator reviews it erases compliance signal. High-volume installs can
opt in to a retention window:

```bash
SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS=90
```

With retention set, `swarm:prune` deletes dead-letter rows whose
`last_attempted_at` is older than the configured window. Pending and
reserved rows are never pruned by this lane (the relay owns their
lifecycle). `swarm.retention.prevent_prune=true` overrides as usual.

See [Audit Evidence Contract](audit-evidence-contract.md) for the full
retry, encryption-at-rest, and signer-rotation behavior.

### Forensic triage: `swarm:audit:reconcile`

`swarm:audit:reconcile` is the operator command for inspecting and
reconciling rows that the relay has either failed or dead-lettered. It
requires the database persistence driver; on cache the command exits
non-zero with a clear error.

Four sub-modes (mutually exclusive):

```bash
# List pending and dead_letter rows (capped at --limit, default 50)
php artisan swarm:audit:reconcile
php artisan swarm:audit:reconcile --status=dead_letter --limit=200

# Inspect a single row, including unsealed payload and last_error
php artisan swarm:audit:reconcile --show=42

# Reset a dead_letter row to pending so the relay re-attempts emission.
# attempts is zeroed; last_error is preserved as forensic evidence.
php artisan swarm:audit:reconcile --requeue=42 --reason="sink restored"

# Permanently delete a dead_letter row. --reason is REQUIRED — audit
# evidence cannot be discarded without a chain-of-custody justification.
php artisan swarm:audit:reconcile --dismiss=42 --reason="duplicate of r-7"
```

Pending rows can be listed and shown but cannot be requeued or
dismissed; the relay owns their lifecycle. Both `--requeue` and
`--dismiss` prompt for confirmation; use `--force` for scripted
recovery. Every sub-mode accepts `--json` for automation.

Every requeue or dismiss emits a `command.audit_reconcile` audit record
carrying `action`, `target_id`, `target_category`, `target_run_id`,
`prior_attempts`, and `reason` **before** mutating the row. `--show`
emits the same category with `action=show` and no payload contents so
reads are counted in the audit chain. Dismiss emits also include a
`target_payload_digest` (sha256 hex of the stored payload bytes) so the
deleted row can be tied back to a forensic backup of the table without
unsealing. If the audit emit fails outright (for example under
`failure_policy=halt`), the row is left untouched. Under the default
`queue` policy, the reconcile record is itself enqueued for retry —
evidence is preserved in the outbox even when the sink is unavailable.

## High-volume dashboards

Swarm database tables are sized for operational throughput. List and aggregation
queries should use **run history** plus **typed durable columns** and
**satellite tables** (labels, waits, signals, progress, child runs, branches,
`swarm_durable_run_state`, `swarm_durable_node_states`).
Avoid driving dashboards from SQL filters or sorts on arbitrary JSON paths in
checkpoint side tables or the main durable row; that pattern scales poorly and
fights indexing.

**Cache-backed persistence does not participate in the durable operational query
contract** — there are no durable tables to index or join. Monitored production
deployments that rely on `swarm:recover`, `swarm:inspect`, or dashboard queries
over durable rows must use the `database` driver.

See [Operational query contract](durable-execution.md#operational-query-contract)
in `docs/durable-execution.md` for package-maintained surfaces, supported
predicates, Pulse behavior, projection patterns, and anti-patterns.

For read-heavy reporting without impacting writers, point dashboards at a
**read replica** or an **application-owned projection** fed by lifecycle events
(see the durable execution doc); the package does not ship built-in table
partitioning.

## Durable storage growth and archival

Durable execution spreads state across `swarm_durable_runs` (scheduler and lease
columns), `swarm_durable_run_state` (route plan and run-level failure / retry
policy), `swarm_durable_node_states` (per-node snapshots), and existing side
tables for branches, waits, signals, and node outputs. **Prune expired history**
on a schedule so terminal rows and their companion durable tables are reclaimed;
`swarm:prune` already targets these roles in bounded batches.

For regulated or long-retention environments, **pruning is not an audit archive**:
export terminal history, context, artifacts, and any durable side rows you need
for compliance before TTL expiry, or stream lifecycle events into an
append-only application store.

**Partitioning** (for example by time or tenant) is not built into the package.
If a single logical table outgrows comfortable maintenance windows after pruning
and archival, plan an application-specific partitioning or archival tier before
expecting database-native partitioning alone to solve throughput.

When you add **application-owned** covering indexes on swarm tables in very large
databases, prefer your engine’s **online / concurrent** index build options (for
example PostgreSQL `CREATE INDEX CONCURRENTLY` or MySQL/InnoDB online DDL) and run
them in a controlled window; package migrations use standard Laravel index
creation and may take stronger locks on huge tables than a hand-tuned rollout.

## Release Checklist

Before cutting a release tag, work through the checklist in [CONTRIBUTING.md § Release Discipline](../CONTRIBUTING.md#release-discipline).

## Production Checklist

For production database persistence:

- schedule `swarm:prune`
- schedule `swarm:relay` (required for durable execution — drains the outbox after each checkpoint)
- schedule `swarm:recover` when using durable execution
- treat pruning, relay, and recovery as required operating discipline for
  database-backed durable workflows, not optional cleanup
- use a dedicated queue for durable workflows that should not compete with
  ordinary application jobs
- set the queue worker timeout above the longest expected provider call for one
  step, and at or above `AdvanceDurableSwarm` / `AdvanceDurableBranch`
  `timeout()` (`swarm.durable.step_timeout` +
  `swarm.durable.job.timeout_margin_seconds`)
- set the queue connection `retry_after` above the worker timeout and above
  `swarm.durable.step_timeout` (and therefore above the durable advance job
  timeout, which adds the configured margin on top of the step window)
- tune durable advance job retries with `swarm.durable.job.tries` and
  `swarm.durable.job.backoff_seconds` (`SWARM_DURABLE_JOB_TRIES`,
  `SWARM_DURABLE_JOB_BACKOFF_SECONDS`) so transient failures do not fall back to
  queue-worker defaults silently
- keep retention windows short for high-volume or sensitive workflows
- disable automatic artifact capture for cost-sensitive or regulated workflows
  unless step-output artifacts are required for inspection
- monitor run count, step count, artifact count, table growth, and run latency
  after launch

For a conservative enterprise pilot, start with one sequential durable workflow
using lower-sensitivity data, a dedicated queue, short retention, and
conservative capture settings. Hierarchical durable workflows are supported, but
they introduce coordinator prompts, route plans, and intermediate node outputs
as runtime state. Do not begin with broad rollout across document-heavy or
approval-critical workflows until storage growth, recovery behavior, and
operator procedures have been proven in production-like use.

## Informational CI workflows

Laravel Swarm ships two scheduled GitHub Actions workflows that are
intentionally non-blocking. Both run with `continue-on-error: true`, so a red
run never gates a PR merge or release. They produce a signal the maintainer is
expected to act on, not a gate the CI system enforces.

These workflows rot silently if no one looks at them. The maintainer reviews
their state weekly as part of release-readiness, and again before tagging any
release.

### Nightly Laravel dev-main

`.github/workflows/nightly.yml`

- **Purpose.** Canary against `laravel/framework:dev-main` and the matching
  `illuminate/*` packages aliased to `13.x-dev`. Surfaces breakage from
  upstream Laravel changes before a tagged release reaches the package's
  supported version matrix.
- **What it runs.** `composer test`, `composer test:process-concurrency:ci`,
  and `composer analyse` on PHP 8.5 against the dev-main dependency set.
- **Trigger.** Daily at 06:17 UTC and on `workflow_dispatch`.
- **Owner and cadence of review.** The maintainer reviews failures weekly
  during release-readiness, and rechecks before cutting a release.
- **What to do when it fails.** Open the failing run and read the test or
  analyse output. If the failure reflects a real upstream change, file an
  issue tagged `laravel-canary` describing the breaking change, the offending
  Laravel commit (if identifiable), and the package code affected. If the
  failure is transient (network, package source flake), re-run the workflow
  manually before filing. Do not block a release on a nightly failure unless
  the same breakage is reproducible against a tagged Laravel release in the
  supported matrix.

### Daily Pest mutation

`.github/workflows/mutation.yml`

- **Purpose.** Tracks test-quality trend over time by mutating covered source
  and verifying the affected tests fail. Surfaces test gaps that line coverage
  alone hides. Replaced Infection in v0.3.5.
- **What it runs.** `composer test:mutation`, which invokes
  `vendor/bin/pest --mutate --coverage --parallel --covered-only` on PHP 8.5
  with pcov. Wall time is hours, not minutes, which is why it runs daily on a
  schedule rather than per PR.
- **Trigger.** Daily at 07:17 UTC and on `workflow_dispatch`. Timeout is 120
  minutes.
- **Owner and cadence of review.** The maintainer reviews the mutation score
  trend weekly during release-readiness. There is no per-PR signal to react
  to.
- **What to do when it fails.** Two failure modes:
  - **Job error** (composer install, timeout, infrastructure). Re-run
    manually. If it persists, treat it as a workflow bug.
  - **Surviving mutants reported.** Open the run output and read the surviving
    mutations. For each, decide whether the gap warrants a new test case, an
    assertion strengthening, or an explicit ignore comment. Do not chase a
    perfect score; the goal is a stable or rising trend.

### Raising the Pest mutate `--min` floor

The mutation workflow does not enforce a minimum mutation score today. Adding
`--min=<score>` to `composer test:mutation` would turn surviving-mutant counts
into a hard failure.

Raise the floor only after the baseline is stable. The criteria:

- The unfloored mutation score has held at or above the candidate floor for
  **four consecutive weekly reviews**.
- No recent surviving mutant reflects an intentional test gap that the
  maintainer is unwilling to close.
- The candidate floor is set **at least five points below the current stable
  score** so a single new uncovered branch does not immediately turn the
  workflow red.

When those hold, set `--min=<floor>` in the `test:mutation` composer script
and note the change in `CHANGELOG.md` under the release that adopts it.
Continue to keep the workflow `continue-on-error: true` for one additional
release after introducing a floor, so a regression surfaces as a red badge
rather than a blocked merge. Promote to a blocking job only after the floor
has held through a full release cycle.
