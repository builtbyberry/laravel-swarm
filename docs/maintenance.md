# Maintenance

Laravel Swarm's database-backed persistence uses prune-based TTL retention.

`ttlSeconds` is still applied when context, artifacts, run history, and
persisted stream replay rows are written, but database records remain queryable
until you prune expired rows.

## Pruning Expired Records

Use the built-in prune command to remove expired records from the swarm
database tables:

```bash
php artisan swarm:prune
```

The command prunes the history, context, artifact, stream replay, durable
runtime, durable node-output, and durable branch tables in bounded chunks to
avoid long-running table locks on large datasets.

Laravel Swarm protects active runs across persistence stores. While a run is
`pending`, `running`, `waiting`, or `paused`, its history, context, artifact,
stream replay, durable runtime, node-output, and branch rows are not pruned,
even if their retention window has elapsed.

History pruning only removes expired terminal rows (`completed`, `failed`, and
`cancelled`). Context and artifact pruning skip rows that belong to active runs.
Durable runtime pruning removes terminal runtime rows once their matching
history row is expired.

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

## Migration Notes

Laravel Swarm's package migrations are intentionally simple Laravel migrations.
For most applications the swarm persistence tables are operational tables with
short retention windows, so standard package migration workflows are enough.

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

If you are using durable execution, also schedule the recovery command
frequently:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:recover')->everyFiveMinutes();
```

The same `swarm:recover` loop redispatches **coordinated queue hierarchical parallel** joins (`coordination_profile = queue_hierarchical_parallel` on `swarm_durable_runs`): stale `waiting` parents with terminal branch rows release to `pending` and enqueue `ResumeQueuedHierarchicalSwarm`, and branch rows are recovered the same way as durable parallel branches.

`swarm:recover` is not a cleanup task like pruning. It is the safety net for
durable runs that were checkpointed successfully but never dispatched their next
step because a worker crashed or exited at the wrong moment. For durable
parallel work, recovery also releases waiting parents whose branch rows are all
terminal, covering the crash window between a branch checkpoint and parent join
dispatch.

Prune-based retention is complementary to queue design, not a substitute for
it. The built-in lightweight queue mode is a good fit for normal background
jobs, but very long-running workflows may still outgrow the practical limits of
a single queued job. For those workflows, use `dispatchDurable()` instead of
stretching `queue()` beyond what one job should own.

## Production Checklist

For production database persistence:

- schedule `swarm:prune`
- schedule `swarm:recover` when using durable execution
- treat pruning and recovery as required operating discipline for
  database-backed durable workflows, not optional cleanup
- use a dedicated queue for durable workflows that should not compete with
  ordinary application jobs
- set the queue worker timeout above the longest expected provider call for one
  step
- set the queue connection `retry_after` above the worker timeout and above
  `swarm.durable.step_timeout`
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
