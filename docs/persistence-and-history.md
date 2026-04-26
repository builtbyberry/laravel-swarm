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

With database-backed history, completed steps are stored in the normalized
`swarm_run_steps` table and assembled back into the same `steps` array returned
by `SwarmHistory`, `RunHistoryStore::find()`, and console commands. Legacy rows
that still have inline `steps` JSON remain readable.

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

## Application Run Inspector

Most production UIs need more than a history query. A useful run detail page
usually combines:

- run history for status, output, steps, usage, timing, and failure details
- context for the current or terminal run snapshot
- artifacts for stored step outputs and explicit application artifacts
- durable state when the run came from `dispatchDurable()`
- a short-lived pending record for queued runs that have not started yet

`SwarmHistory` remains the stable history surface for every execution mode. For
durable runs, database-backed persistence also exposes a durable runtime record
with operational state such as route progress, node state, leases, attempts,
operator controls, recovery markers, and failure metadata. Use that runtime
record as an additional inspection surface for active and terminal durable runs.

```php
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;

$runtime = app(DurableRunStore::class)->find($runId);
```

This durable operational state is only available with database-backed durable
execution. Cache-backed persistence keeps recent history visibility, but it does
not provide the durable runtime table or durable leases.

Laravel Swarm intentionally keeps those stores focused instead of shipping a
package-owned dashboard API. Compose them in application code into the stable
JSON shape your UI needs.

See [Run Inspector](../examples/run-inspector/README.md) for a controller and
service example.

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
    'artifacts' => false,
    'active_context' => true,
],
```

Or with environment variables:

```bash
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false
SWARM_CAPTURE_ARTIFACTS=false
SWARM_CAPTURE_ACTIVE_CONTEXT=true
```

When input capture is disabled, event payloads and persisted step history keep
their normal shape but replace captured input fields with `[redacted]`.
Persisted run history also stores a redacted context snapshot, including the
context `input` field and structured task data.

When output capture is disabled, event payloads and persisted step history keep
their normal shape but replace captured output fields with `[redacted]`.
Laravel Swarm also skips automatic `agent_output` artifact persistence and
redacts terminal context output fields such as `last_output`. For hierarchical
runs, terminal history redaction also covers hierarchical node outputs and the
durable route cursor snapshot.

When artifact capture is disabled, Laravel Swarm keeps captured input and output
text according to the input/output capture settings, but skips automatic
`agent_output` artifact persistence and omits artifact payloads from lifecycle
events and persisted context snapshots.

Capture settings do not change agent handoff behavior and do not change the
`SwarmResponse` returned to the current PHP process. They control what Laravel
Swarm emits and persists for later inspection.

`active_context` controls the runtime context store used while a swarm is in
flight. When it is `false`, synchronous and streamed swarms store a redacted
runtime context snapshot instead of raw task state. Queued and durable swarms
require active runtime context persistence so workers can continue or recover
the run; Laravel Swarm fails before dispatch if `active_context` is disabled for
those modes. This setting is not encryption and it is not a replay mechanism.
Use input/output/artifact capture settings to control terminal history and event
capture.

When either input or output capture is disabled, failed run history keeps the
original exception class but stores `[redacted]` as the exception message.
`SwarmFailed` events receive the same redacted exception message and expose the
original exception class on `exceptionClass`. The exception thrown back to the
caller remains the original exception so application control flow and logs
outside Laravel Swarm are not altered.

Queued and durable execution keep raw context in the runtime context store while
a run is active because workers need that state to continue the workflow. When a
run reaches a terminal state, Laravel Swarm overwrites that context store entry
with a redacted snapshot when capture is disabled.

Durable hierarchical runs also keep route plans, route cursors, neutral node
state, and per-node outputs in durable runtime storage while the run is active.
Active route plans can contain worker prompts because recovery needs the raw
route. Laravel Swarm replaces terminal route plans with an inspection-safe
projection after completion, failure, or cancellation, and deletes durable
node-output rows.

Capture flags cover captured inputs, captured outputs, automatic artifacts,
terminal context snapshots, durable runtime failure metadata, persisted failure
messages, and failure event messages. They do not redact active route plans or
developer-supplied metadata. Treat metadata as operational data only and do not
put secrets, raw prompts, or provider payloads in it.

## Payload Limits

Laravel Swarm can reject or truncate large payloads before they are written to
persisted context, history, artifacts, or lifecycle events:

```php
'limits' => [
    'max_input_bytes' => 100_000,
    'max_output_bytes' => 250_000,
    'overflow' => 'fail', // or 'truncate'
],
```

Input limits are checked before `run()`, `queue()`, `stream()`, or
`dispatchDurable()` starts execution. Queued and durable dispatches, and
explicit `RunContext` values in any execution mode, check the serialized runtime
context payload so large data, metadata, or artifacts cannot bypass the
configured input limit.

Input limits always fail before execution when the configured size is exceeded.
Laravel Swarm does not truncate task input or runtime context before handing it
to agents.

Output limits apply to captured output surfaces. If output capture is disabled,
output limit checks do not run because Laravel Swarm persists and emits
`[redacted]` instead of the provider output.

The default limit values are `null`, which keeps existing behavior. When a
limit is configured, the default output overflow strategy is `fail`.
Truncation is opt-in for captured output only and adds metadata such as
`output_truncated`, `output_original_bytes`, and `output_stored_bytes` to the
persisted step or completion record.

Payload limits are storage and event guardrails. They do not hard-cancel an
in-flight provider request, limit third-party SDK buffering, or cap the
temporary PHP memory used while an agent response is being produced.

## Production Cost And Privacy Tradeoffs

Database-backed history stores completed steps in a normalized table instead of
rewriting one growing JSON document on every step. That reduces write
amplification, but it does not make persistence free. A completed step can still
write run history, the active context snapshot, and automatic artifacts.

For cost-sensitive or regulated production workflows, start with automatic
artifact capture disabled unless step-output artifacts are required for
inspection:

```php
'capture' => [
    'inputs' => false,
    'outputs' => true,
    'artifacts' => false,
    'active_context' => true,
],
```

Queued and durable execution persist active runtime context by design so workers
can continue or recover a run. Terminal redaction can overwrite the context
snapshot after completion, but it does not mean raw active runtime state was
never stored while the run was in flight.

Payload limits protect persisted history, context, artifacts, and lifecycle
event payloads. They are not provider memory limits, token generation limits, or
third-party SDK buffering controls.

## Custom Table Names

If you change `swarm.tables.*`, Laravel Swarm will use those table names at
runtime.

Database history uses both `swarm.tables.history` and
`swarm.tables.history_steps`. If you customize the history table name, customize
the normalized step table name as well.

If you publish the package migrations, update the table names there as well so
your schema matches your runtime configuration.

Most applications can use the package defaults without customizing the
persistence contracts directly.
