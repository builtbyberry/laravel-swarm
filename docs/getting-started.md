# Getting Started

This guide walks a fresh Laravel application from `composer require` to a
running starter swarm in under five minutes.

If you have already used Laravel's own ecosystem installers — Cashier,
Sanctum, Pulse, Horizon, Filament — the flow will feel familiar. `swarm:install`
is the single command an operator runs after `composer require`; everything
the package needs (config, env keys, migrations, sub-installer wiring) flows
from that one entry point.

Prefer to wire things by hand? See [Advanced Setup](./advanced-setup.md) for
the manual equivalent of every step the installer performs.

## Prerequisites

- PHP **^8.5** (a deliberate floor — the package builds on 8.5 language features)
- Laravel **13** (`illuminate/*` **^13.0**)
- `laravel/ai` **^0.8** (a transitive dependency, installed by Composer)

As of **v0.13.0** the `laravel/ai` floor is **^0.8**; support for **0.6 / 0.7**
was dropped. Applications pinned below `laravel/ai` 0.8 must upgrade before
taking this release.

Because `laravel/ai` is still pre-1.0 and ships dev-tagged releases, your
application's `composer.json` must allow dev-stability resolution. Add the
following keys if they are not already present:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

`prefer-stable` keeps Composer biased toward tagged releases — only
dependencies without a stable release (today, `laravel/ai`) resolve to a
`dev-` constraint. This requirement disappears when `laravel/ai` reaches 1.0.

## Install the package

```bash
composer require builtbyberry/laravel-swarm
php artisan swarm:install
```

`swarm:install` is interactive. It walks you through the full setup in one
pass:

1. **Publishes `config/swarm.php`** into your application's `config/`
   directory (idempotent — skips when the file is already present; re-run
   with `--force` to overwrite).
2. **Seeds the canonical Swarm `.env` keys** with safe defaults. Existing
   values are left untouched. The seeded block is sentinel-fenced under a
   `# swarm:install — managed env keys` header so a future package version
   adding defaults extends the block in place instead of accumulating
   duplicate headers.
3. **Picks the persistence driver** — `database` (recommended) or `cache`.
   On `database`, the installer offers to run `php artisan migrate` now.
   On `cache`, it scaffolds a `LaravelSwarm::ignoreMigrations()` call into
   `AppServiceProvider::register()` so the package's migrations do not run.
4. **Warns when `QUEUE_CONNECTION=sync`.** Queued and durable execution
   require a real queue driver (`database`, `redis`, `sqs`). The installer
   does not edit `config/queue.php` — that remains your decision.
5. **Offers the targeted sub-installers** in the same pass:
   - [`swarm:install:durable`](./durable-execution.md) — scheduler entries
     for `swarm:relay`, `swarm:recover`, and `swarm:prune`, plus copy-paste
     worker snippets.
   - [`swarm:install:audit`](./audit-evidence-contract.md) — binds a
     `SwarmAuditSink` (and the optional `SwarmAuditSigner`,
     `ActorResolver`, `CapturePolicy` stubs) into `AppServiceProvider`.
   - [`swarm:install:memory`](./memory.md) — verifies the memory tables
     (`swarm_memories`, `swarm_memory_snapshots`) and prints the effective
     replay mode. Run standalone at any time to validate your memory config.
   - [`swarm:install:examples`](./examples.md) — copies the runnable
     starter pack into `app/Ai/`.

   Pulse observability lives in a separate companion package —
   [`builtbyberry/laravel-swarm-pulse`](https://github.com/builtbyberry/laravel-swarm-pulse)
   — and ships its own `swarm:install:pulse` once installed. See
   [Pulse](./pulse.md).

### Non-interactive install

Every prompt has a flag override so the installer is scriptable in CI,
Docker images, and Forge provisioning recipes:

```bash
php artisan swarm:install \
    --no-interaction \
    --persistence=database \
    --with-durable \
    --with-audit \
    --with-memory \
    --with-examples
```

Pass `--without-<name>` to skip a sub-installer in `--no-interaction` mode,
`--persistence=cache` for cache-only deployments, `--skip-migrate` to defer
migrations, `--force` to overwrite an existing `config/swarm.php`, and
`--force-env` to overwrite a pre-existing `SWARM_PERSISTENCE_DRIVER` value
that disagrees with `--persistence`.

### Cache-only deployments

If your application uses only the `cache` persistence driver — no durable
execution, no audit outbox, no long-lived run history — pass
`--persistence=cache`:

```bash
php artisan swarm:install --persistence=cache
```

Instead of running `php artisan migrate`, the installer scaffolds a
`LaravelSwarm::ignoreMigrations()` call into your `AppServiceProvider`,
fenced with sentinel comments so re-runs are byte-level no-ops:

```php
// app/Providers/AppServiceProvider.php

use BuiltByBerry\LaravelSwarm\LaravelSwarm;

public function register(): void
{
    // swarm:install — cache-only persistence; do not edit between markers
    LaravelSwarm::ignoreMigrations();
    // swarm:install — end cache-only persistence
}
```

This is the same idiom Cashier, Sanctum, Passport, Horizon, and Telescope
use to opt out of migration autoloading. Cache-only deployments cannot use
`dispatchDurable()` or the audit outbox; everything else (sync, queued,
streamed, parallel, hierarchical) works.

## Verify the install

After the installer finishes, confirm everything wired up cleanly:

```bash
php artisan swarm:health
```

For database persistence, also verify the durable runtime tables:

```bash
php artisan swarm:health --durable
```

The audit outbox has its own focus flag: `swarm:health --audit`. If you've
installed the [Pulse companion package](./pulse.md), the operator-facing
`<livewire:swarm.audit-outbox />` card surfaces the same signal on your
dashboard.

## Run your first swarm

The fastest way to see a swarm execute end-to-end is the starter pack
installed by `swarm:install:examples`. If you accepted the examples offer
during install, three runnable starter swarms are already in your app
under `app/Ai/Swarms/`. If you skipped it, install them now:

```bash
php artisan swarm:install:examples --example=sequential-blog-pipeline
```

Then run the included Artisan command:

```bash
php artisan swarm:example:blog-pipeline "Laravel queue visibility timeouts"
```

You should see polished text output from the final agent. The intermediate
replies are recorded in `$response->steps`.

### What just happened

The `sequential-blog-pipeline` example ships three agents — `OutlineWriter`,
`Drafter`, `Polisher` — and a swarm class that chains them sequentially:

```php
#[Topology(TopologyEnum::Sequential)]
class BlogPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new OutlineWriter,
            new Drafter,
            new Polisher,
        ];
    }
}
```

Every agent extends `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` — a
runnable, provider-free agent base class that returns canned text. That is
what lets the starter execute on a fresh install with no API key, no
provider configured, and no environment setup beyond the installer.

Each agent file carries a `TODO` comment pointing at the one-line edit that
swaps `ScriptedAgent` for a real `Promptable` Laravel AI agent (the swarm
class, the runner command, and any tests keep working unchanged):

```php
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class OutlineWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Draft a five-point outline for a blog post on the given topic.';
    }
}
```

The starter pack ships two more examples covering Parallel topology and the
durable human-in-the-loop pattern. See [Starter Examples](./examples.md) for
the full inventory.

## Scaffold your own swarm

Once you have run a starter swarm end-to-end, generate your own:

```bash
php artisan make:swarm:swarm ContentPipeline
php artisan make:swarm:agent ArticlePlanner
```

`make:swarm:swarm` scaffolds a swarm class under `app/Ai/Swarms/` and
accepts `--topology=sequential|parallel|hierarchical|static-hierarchical`
(defaults to `sequential`). `make:swarm:agent` scaffolds an agent under
`app/Ai/Agents/` extending `ScriptedAgent` (the same shape the starter
examples use), with `TODO` markers pointing at the upgrade path for
plugging in a real LLM.

See [Generators](./generators.md) for the full generator surface.

## Where to next

You now have a working Laravel Swarm install and a running starter swarm.
From here:

- [Sequential Topology](./sequential.md) — the default; build your first
  production pipeline.
- [Choosing an Execution Mode](./execution-modes.md) — `prompt()`,
  `queue()`, `stream()`, `dispatchDurable()`, and when to reach for each.
- [Testing](./testing.md) — the fake/assert layer that covers every
  execution mode.
- [Starter Examples](./examples.md) — the full starter pack inventory.
- [Advanced Setup](./advanced-setup.md) — the manual equivalent of every
  installer step, for environments where the installer cannot run.
- [Durable Execution](./durable-execution.md) — checkpointed, recoverable,
  long-running workflows.
- [Swarm Memory](./memory.md) — scoped, snapshot-replayable memory; run,
  conversation, agent, and swarm scopes with replay semantics. (v0.9.0+)
- [Audit Evidence Contract](./audit-evidence-contract.md) — regulated
  evidence export with `SwarmAuditSink`.
- [Pulse](./pulse.md) — Pulse cards for run counts, step latencies, and
  audit outbox health.
