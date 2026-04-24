# Persistence And History

Laravel Swarm can persist three kinds of run data:

- context
- artifacts
- run history

This gives you a consistent way to inspect what happened during a swarm run
after it finishes.

## What Gets Stored

Persisted context includes the task input, structured data, metadata, and
artifacts attached to the run.

Persisted run history includes the swarm class, topology, status, steps,
output, usage, and completion metadata.

Run history also includes timing fields for inspection and duration
calculations:

- `started_at`
- `finished_at`
- `updated_at`

## Inspecting Run History In Application Code

Use the `SwarmHistory` facade or service when you want to query persisted runs:

```php
use App\Ai\Swarms\ArticlePipeline;
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;

$run = SwarmHistory::find($runId);

$latest = SwarmHistory::latest();

$completed = SwarmHistory::forSwarm(ArticlePipeline::class)
    ->withStatus('completed')
    ->limit(10)
    ->get();
```

## Inspecting Run History In The Console

Laravel Swarm also includes read-only inspection commands:

```bash
php artisan swarm:status
php artisan swarm:status --run-id=<run-id>
php artisan swarm:history --swarm="App\\Ai\\Swarms\\ArticlePipeline" --status=completed --limit=10
```

Use `swarm:status` for a quick current view. Use `swarm:history` when you
want filtered history.

## Choosing A Persistence Driver

Laravel Swarm supports both cache-backed and database-backed persistence.

Set the global persistence driver in `config/swarm.php`:

```php
'persistence' => [
    'driver' => 'database', // or 'cache'
],
```

Individual stores can override the global driver if needed:

```php
'history' => [
    'driver' => 'database',
],
'artifacts' => [
    'driver' => 'cache',
],
```

### Cache

The cache driver is lightweight and works well when you want recent run
visibility without durable storage. It respects the configured TTL values.

> **Note:** If a process is killed mid-run, a stale `running` entry may remain
> visible in cache-backed history until the underlying record expires. Use the
> database driver when inspection accuracy matters.

### Database

The database driver is the better choice when you want durable inspection data.
It stores run data in package-managed tables and preserves the same read shape
as the cache-backed stores.

The package migrations are loaded automatically. Run `php artisan migrate` to
create the swarm tables if you have not already done so.

Database-backed history does not have the stale-index behavior described above.
It is usually the better fit for production inspection and auditability.

For queued runs, the database-backed history store uses lease-based ownership
to guard against duplicate queue deliveries replaying the same swarm work while
another worker still owns the run.

Database TTL is prune-based retention. Expired rows remain queryable until you
run the prune command described in [Maintenance](maintenance.md).

While a run is still `running`, Laravel Swarm keeps the run coherent across
history, context, and artifact storage. Active database-backed runs are not
partially pruned out of one store while they are still in flight.

## Custom Table Names

If you change `swarm.tables.*`, Laravel Swarm will use those table names at
runtime.

If you publish the package migrations, update the table names there as well so
your schema matches your runtime configuration.

Most applications can use the package defaults without customizing the
persistence contracts directly.
