# Maintenance

Laravel Swarm's database-backed persistence uses prune-based TTL retention.

`ttlSeconds` is still applied when context, artifacts, and run history rows are
written, but database records remain queryable until you prune expired rows.

## Pruning Expired Records

Use the built-in prune command to remove expired records from the swarm
database tables:

```bash
php artisan swarm:prune
```

The command prunes the history, context, artifact, and durable runtime tables in
bounded chunks to avoid long-running table locks on large datasets.

Laravel Swarm protects active runs across persistence stores. While a run is
`pending`, `running`, or `paused`, its history, context, artifact, and durable
runtime rows are not pruned, even if their retention window has elapsed.

History pruning only removes expired terminal rows (`completed`, `failed`, and
`cancelled`). Context and artifact pruning skip rows that belong to active runs.
Durable runtime pruning removes terminal runtime rows once their matching
history row is expired.

If you override `swarm.tables.*`, the prune command respects those configured
table roles directly. It does not rely on default table-name patterns to decide
which rows are safe to delete.

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

`swarm:recover` is not a cleanup task like pruning. It is the safety net for
durable runs that were checkpointed successfully but never dispatched their next
step because a worker crashed or exited at the wrong moment.

Prune-based retention is complementary to queue design, not a substitute for
it. The built-in lightweight queue mode is a good fit for normal background
jobs, but very long-running workflows may still outgrow the practical limits of
a single queued job. For those workflows, use `dispatchDurable()` instead of
stretching `queue()` beyond what one job should own.
