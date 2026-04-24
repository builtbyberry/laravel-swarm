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

When output capture is enabled, Laravel Swarm also creates an automatic
`agent_output` artifact for each completed agent step. That output can appear in
several places: the step history, the final history output, the run context's
artifact list, and the artifact repository table or cache entry. This
duplication is intentional so each inspection surface is useful on its own, but
teams with sensitive prompts or outputs should review the capture settings
below before enabling production persistence.

Run history also includes timing fields for inspection and duration
calculations:

- `started_at`
- `finished_at`
- `updated_at`

Durable runs continue to write to the same run history surface. That means
inspection commands and `SwarmHistory` still work even when execution is
checkpointed across many jobs.

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

Per-store drivers should only be set when you intentionally want an override.
If you published `config/swarm.php` from an older package version, verify that
the `context`, `artifacts`, and `history` driver values are not hardcoded to
`cache`, or they will override `swarm.persistence.driver`.

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

Durable execution also requires the database driver. Durable runtime state is
stored separately from run history, but the public inspection surface remains
the same history, context, and artifact records.

Database TTL is prune-based retention. Expired rows remain queryable until you
run the prune command described in [Maintenance](maintenance.md).

While a run is still `running`, Laravel Swarm keeps the run coherent across
history, context, and artifact storage. Active database-backed runs are not
partially pruned out of one store while they are still in flight.

Laravel Swarm always loads its package migrations through the service provider.
The default swarm tables are created during normal application migrations even
when your current persistence driver is `cache`. This keeps local and
production migration behavior predictable. If you do not want the default table
names, publish the migrations and update them to match `swarm.tables.*`.

## Privacy And Data Capture

Swarm prompts and outputs often contain customer text, documents, or other
sensitive data. By default, Laravel Swarm captures inputs and outputs in
lifecycle events, run history, and automatic step artifacts because that gives
developers useful inspection data.

You can disable input or output capture in `config/swarm.php`:

```php
'capture' => [
    'inputs' => false,
    'outputs' => false,
],
```

Or with environment variables:

```bash
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false
```

When input capture is disabled, event payloads and persisted step history keep
their normal shape but replace captured input fields with `[redacted]`.

When output capture is disabled, event payloads and persisted step history keep
their normal shape but replace captured output fields with `[redacted]`.
Laravel Swarm also skips automatic `agent_output` artifact persistence.

Capture settings do not change agent handoff behavior and do not change the
`SwarmResponse` returned to the current PHP process. They control what Laravel
Swarm emits and persists for later inspection.

## Custom Table Names

If you change `swarm.tables.*`, Laravel Swarm will use those table names at
runtime.

If you publish the package migrations, update the table names there as well so
your schema matches your runtime configuration.

Most applications can use the package defaults without customizing the
persistence contracts directly.
