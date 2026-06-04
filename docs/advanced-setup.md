# Advanced Setup

This page covers the manual equivalent of every step the
[`swarm:install`](./getting-started.md) installer performs. Use it when:

- The installer cannot run in your environment (locked-down CI, image
  builds with no TTY where `--no-interaction` is also off the table,
  applications with hand-rolled service providers the installer does not
  recognize).
- You want to understand exactly what gets wired up before letting the
  installer touch your application.
- You are integrating into an existing Laravel app where some of these
  steps are already in place.

The recommended flow is still
[`php artisan swarm:install`](./getting-started.md). Read this page to
understand the underlying contract, then choose whichever path fits.

## Hybrid: installer + selective opt-outs

You do not have to pick all-installer or all-manual. `swarm:install` accepts
`--without-<name>` flags for every sub-installer, so you can let the
orchestrator handle the base setup (config publish, env seeding, persistence
path, queue check) and skip the sub-installers you want to wire by hand:

```bash
php artisan swarm:install --without-pulse --without-examples
```

Each `swarm:install:<name>` command is also runnable on its own at any time
— so the inverse pattern works too: skip everything in the main installer
and run the specific sub-installers manually as you adopt their features.
Sub-installer flags are documented under each section below.

## Install the package

```bash
composer require builtbyberry/laravel-swarm
```

This pulls `builtbyberry/laravel-swarm` and its `laravel/ai` dependency into
your application. See the [Getting Started prerequisites](./getting-started.md#prerequisites)
for the `minimum-stability` constraint your `composer.json` must allow.

## Publish the configuration

```bash
php artisan vendor:publish --tag=swarm-config
```

This drops `config/swarm.php` into your application's config directory. The
file is fully commented; see [Configuration](./configuration.md) for the
canonical reference of every key.

`swarm:install` performs the same publish, but via a direct file copy so it
works inside the installer test harness — the on-disk result is identical.

## Seed Swarm environment keys

The package reads safe defaults from `config/swarm.php`, so explicit
environment overrides are optional. The installer seeds the canonical set
for visibility and IDE/secret-scanner discoverability:

```env
SWARM_PERSISTENCE_DRIVER=database
SWARM_TOPOLOGY=sequential
SWARM_TIMEOUT=300
SWARM_MAX_AGENT_STEPS=10
SWARM_AUDIT_FAILURE_POLICY=queue
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false
SWARM_CAPTURE_ARTIFACTS=false
SWARM_CAPTURE_ACTIVE_CONTEXT=false
```

Add the same keys to `.env.example` so other developers on your team know
what to set. The capture defaults are deliberately conservative — see
[Persistence And History](./persistence-and-history.md) before enabling
input, output, artifact, or active-context capture in production.

## Choose a persistence driver

Set `SWARM_PERSISTENCE_DRIVER` (or `swarm.persistence.driver`) to one of:

- `database` (recommended) — durable runtime, audit outbox, full history.
- `cache` — fast, ephemeral; no durable execution, no audit outbox.

### Database persistence

Run the package migrations to create the swarm runtime tables:

```bash
php artisan migrate
```

This creates `swarm_runs`, `swarm_steps`, `swarm_contexts`,
`swarm_artifacts`, `swarm_durable_runs`, `swarm_durable_outbox`,
`swarm_audit_outbox`, and the supporting indexes.

If you ever want to manage the migrations from your own application's
`database/migrations/` directory, publish them with:

```bash
php artisan vendor:publish --tag=swarm-migrations
```

This is rarely necessary; the package's autoloaded migrations are stable
Laravel migrations and follow standard package conventions.

### Cache persistence (cache-only deployments)

Cache-only deployments do not run the package migrations. Tell Laravel
Swarm to skip its migration autoloading by calling `ignoreMigrations()`
from `AppServiceProvider::register()`:

```php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use BuiltByBerry\LaravelSwarm\LaravelSwarm;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        LaravelSwarm::ignoreMigrations();
    }

    public function boot(): void
    {
        //
    }
}
```

This follows the same idiom as Cashier, Sanctum, Passport, Horizon, and
Telescope. The `swarm-migrations` publish tag remains available if you
later switch to the `database` driver — see
[Maintenance: Opting out of migration autoloading](./maintenance.md#opting-out-of-migration-autoloading).

`swarm:install --persistence=cache` writes the same `ignoreMigrations()`
call into `AppServiceProvider`, fenced with sentinel comments so re-runs
are no-ops.

## Verify a real queue driver

Queued and durable execution require a real queue driver. Check your
current setting:

```bash
php artisan config:show queue.default
```

If it returns `sync`, switch to `database`, `redis`, or `sqs` in your
`.env` before scheduling durable runs:

```env
QUEUE_CONNECTION=database
```

`swarm:install` does **not** mutate `config/queue.php` — that remains an
explicit operator decision.

## Wire durable execution (manual equivalent of `swarm:install:durable`)

Three pieces of plumbing turn on the durable runtime:

### 1. Schedule the relay, recovery, and prune commands

Add the following to `routes/console.php` (Laravel 11+) or
`app/Console/Kernel.php` `schedule()` (Laravel 10):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:relay')->everyMinute();        // drains the outbox after each checkpoint
Schedule::command('swarm:recover')->everyFiveMinutes(); // safety net: redispatches stranded runs
Schedule::command('swarm:prune')->daily();              // retention: removes expired persistence rows
Schedule::command('swarm:memory:purge --pause=100')->dailyAt('03:00'); // memory retention: off-peak, throttled between batches
```

On large `swarm_memories` tables, run the purge **off-peak** and **throttled** (as
above) so a flat-out sweep does not pressure the database or a read replica —
`--pause=<ms>` sleeps between delete batches. On small tables a bare
`->daily()` is fine.

`swarm:memory:purge` is opt-in retention enforcement for the SwarmMemory
subsystem. Configure per-scope windows in `config/swarm.php` under
`memory.retention.days` (`run`, `conversation`, `agent`, `swarm`) — `null`
disables enforcement for that scope. The command dispatches a
`MemoryPurged` event with per-scope counts and the criteria it ran with so
app-level audit listeners can record deletions before they happen. Use
`--dry-run` to preview, `--scope=<value>` to limit a run to a single scope,
`--keep-snapshots` to skip the `swarm_memory_snapshots` cascade, and
`--pause=<ms>` to throttle between delete batches on large tables. See
[Compliance & Audit](./compliance-audit.md) for how memory retention fits
into the wider compliance evidence chain.

`swarm:relay` is required — durable runs stall permanently if the relay is
not running. It also drains the v0.5 audit outbox, so a single schedule
covers both the durable and audit lanes. Use `swarm:relay --type=audit` or
`--type=step` to drain a single lane during focused recovery.

### 2. Set the durable queue name

The durable runtime defaults to a `swarm-durable` queue. Override it via
`SWARM_DURABLE_QUEUE=<name>` in `.env` if you want a different queue
identity in your worker config.

### 3. Run a worker

Run a worker against the durable queue (plain `queue:work`, Horizon, or a
Supervisor / Forge `.conf` block — pick whichever your application
already uses):

```bash
php artisan queue:work --queue=swarm-durable
```

For Horizon, add `swarm-durable` to the appropriate supervisor's `queue`
list in `config/horizon.php`. `swarm:install:durable` prints copy-paste
snippets for all three patterns.

See [Durable Execution](./durable-execution.md) for the full operational
contract.

## Bind the audit sink (manual equivalent of `swarm:install:audit`)

By default, `SwarmAuditSink` is bound to `NoOpSwarmAuditSink` — every
audit record is silently discarded. To capture audit evidence, bind a
concrete sink in `app/Providers/AppServiceProvider::register()`:

```php
use BuiltByBerry\LaravelSwarm\Audit\LogChannelSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

public function register(): void
{
    $this->app->bind(SwarmAuditSink::class, LogChannelSwarmAuditSink::class);
}
```

`LogChannelSwarmAuditSink` is the zero-config dev/staging default — it
writes every audit record as a structured log entry to the configured
Laravel log channel (defaults to `audit`, falls back to the default
channel when `audit` is not configured). Production deployments should
ship a bounded backend (database, queue, SIEM export); see
[Audit Evidence Contract](./audit-evidence-contract.md) for the contract
shape and the regulated-deployment extension points
(`SwarmAuditSigner`, `ActorResolver`, `CapturePolicy`,
`SinkFailureHandler`, `ReadableSwarmAuditSink`).

If you are on the database persistence driver, also confirm
`swarm_audit_outbox` is migrated — the v0.5 default
`SWARM_AUDIT_FAILURE_POLICY=queue` persists failed sink writes to the
outbox for retry through `swarm:relay --type=audit`.

## Wire Pulse (manual equivalent of `swarm:install:pulse`)

If your application already uses Laravel Pulse, the Swarm package ships
two recorders and three Livewire cards. Install Pulse first (if you have
not already):

```bash
composer require laravel/pulse
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

Add the recorders to `config/pulse.php`:

```php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;

'recorders' => [
    // ...
    SwarmRuns::class => [
        'enabled' => env('PULSE_SWARM_RUNS_ENABLED', true),
    ],
    SwarmStepDurations::class => [
        'enabled' => env('PULSE_SWARM_STEP_DURATIONS_ENABLED', true),
    ],
],
```

Publish the Pulse dashboard view if you have not already, and add the
swarm cards to `resources/views/vendor/pulse/dashboard.blade.php`:

```bash
php artisan vendor:publish --tag=pulse-dashboard
```

```blade
<livewire:swarm.runs cols="6" />
<livewire:swarm.steps cols="6" />
<livewire:swarm.audit-outbox cols="6" />
```

See [Pulse](./pulse.md) for what each card surfaces.

## Install the starter examples (manual equivalent of `swarm:install:examples`)

The starter pack lives at `stubs/examples/` inside the package. Each
example is a complete `app/Ai/Swarms/<Name>/`,
`app/Ai/Agents/<Name>/`, and `app/Console/Commands/SwarmExample<Name>Command.php`
tree.

To install by hand, copy the desired example tree into your application
and rewrite the `{{ rootNamespace }}` placeholder (and the legacy
`{{ namespace }}` placeholder) in every PHP file to your application's
PSR-4 root (defaults to `App\`):

```bash
cp -R vendor/builtbyberry/laravel-swarm/stubs/examples/sequential-blog-pipeline/app/* app/

# Then, in each copied PHP file:
#   replace {{ rootNamespace }} with App\
```

The runner commands (`app/Console/Commands/SwarmExample<Name>Command.php`)
are picked up automatically by Laravel 11+ Artisan auto-discovery — no
`routes/console.php` edit needed. Run them with the `swarm:example:<name>`
signature each command declares.

`swarm:install:examples` automates the copy + namespace rewrite and picks
the host app's PSR-4 root from `composer.json`. See
[Starter Examples](./examples.md) for the full inventory and per-example
runner command.

## Set up Swarm Memory (manual equivalent of `swarm:install:memory`)

The memory subsystem provides scoped, snapshot-replayable in-process memory
for agent runs. On the `database` persistence driver it requires two tables —
`swarm_memories` and `swarm_memory_snapshots` — introduced in v0.9.0.

### Run the sub-installer

```bash
php artisan swarm:install:memory
```

The command:

1. **Resolves the effective memory driver** — checks `swarm.memory.driver`
   first (per-subsystem override), falls back to `swarm.persistence.driver`.
   When driver is `cache`, memory is ephemeral and the tables are not
   required; the command warns and exits cleanly rather than refusing.
2. **Detects missing memory tables** and offers to run `php artisan migrate`.
   Pass `--migrate` to skip the prompt; `--skip-migrate` to defer.
3. **Prints your current `SWARM_MEMORY_REPLAY_MODE`** so you can confirm
   the replay strategy is what you expect.

You can re-run `swarm:install:memory` at any time — it is idempotent.

### Configure the replay mode

The replay mode controls what memory an agent sees when a durable run is
retried after a crash:

| Mode | Behaviour | When to use |
|---|---|---|
| `frozen_view` (default) | Agent reads the snapshot captured at the start of the failed step — deterministic, same tool calls replayed from the same context | Most workloads |
| `fresh_execution` | Agent reads live memory at retry time — non-deterministic, may produce different tool calls | Only when idempotency is guaranteed externally |

Set via `.env`:

```dotenv
SWARM_MEMORY_REPLAY_MODE=frozen_view
```

Or override per-swarm class:

```php
use BuiltByBerry\LaravelSwarm\Memory\ReplayMode;
use BuiltByBerry\LaravelSwarm\Memory\Attributes\MemoryReplay;

#[MemoryReplay(mode: ReplayMode::FreshExecution)]
class MySwarm extends Swarm { ... }
```

### Manual migration

If you prefer to control migrations yourself rather than using the
sub-installer prompt:

```bash
php artisan vendor:publish --tag=swarm-migrations
php artisan migrate
```

See [Memory](./memory.md) for the full memory model reference.

## Publish the generator stubs (optional)

Customize the `make:swarm:swarm` and `make:swarm:agent` output by
publishing the generator stubs into your project:

```bash
php artisan vendor:publish --tag=swarm-stubs
```

This drops the five stub files (`swarm.stub`, `swarm.parallel.stub`,
`swarm.hierarchical.stub`, `swarm.static-hierarchical.stub`,
`swarm.agent.stub`) into your project's `stubs/` directory. Both
generators check for a published copy first and fall back to the shipped
stub if none is present. See [Generators](./generators.md) for the full
generator surface.

## Verify the install

After every step, the verification command is the same as the installer
path:

```bash
php artisan swarm:health
php artisan swarm:health --durable
php artisan swarm:health --audit
```

`--durable` verifies the database tables required by `dispatchDurable()`
and coordinated multi-worker hierarchical queueing. `--audit` runs the
two audit outbox checks (pending staleness + dead-letter count). Both
flags degrade cleanly on the cache persistence driver, where the
underlying tables are unavailable by design.

## Where to next

- [Getting Started](./getting-started.md) — the installer-driven path.
- [Configuration](./configuration.md) — every config key, grouped and
  searchable.
- [Maintenance](./maintenance.md) — retention, pruning, and long-term
  operational hygiene.
- [Durable Execution](./durable-execution.md) — checkpointing, the relay,
  recovery, and operator controls.
- [Memory](./memory.md) — scoped memory, replay modes, snapshot
  determinism, and the `#[MemoryReplay]` attribute.
- [Audit Evidence Contract](./audit-evidence-contract.md) — the full
  audit contract surface and regulated-deployment extension points.
- [Pulse](./pulse.md) — recorders, cards, and aggregate observability.
- [Starter Examples](./examples.md) — the runnable starter pack.
