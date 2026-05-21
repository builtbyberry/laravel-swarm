# Starter Examples

Laravel Swarm ships a small, runnable starter pack alongside the larger
reference collection.

- **Starter pack** — three curated, fully runnable examples published by
  `php artisan swarm:install:examples` into the user's app. Each runs
  end-to-end with no API keys and no extra config. Source lives at
  `stubs/examples/` inside the package.
- **Reference examples** — the older, larger collection of README-only
  walkthroughs under the top-level `examples/` directory. Those cover the
  advanced surfaces (hierarchical routing, streaming, broadcasts, Pulse,
  guardrails, webhooks). See `examples/README.md` for the index.

The starter pack is meant to get a new user from `composer require` to a
working swarm in under a minute. The reference examples are meant to
answer "how do I do X?" once the user has the basic mental model.

## Install the starter pack

If you are setting up Laravel Swarm for the first time, start with the
broader [`swarm:install`](./getting-started.md) entry point — it offers
the example pack as one step in the full install. To install the starter
pack on its own (or to add it to an application that already has Laravel
Swarm installed), use the dedicated sub-installer:

```bash
# Pick one, several, or all in an interactive multiselect.
php artisan swarm:install:examples

# Headless / CI: pick by name or take everything.
php artisan swarm:install:examples --example=sequential-blog-pipeline
php artisan swarm:install:examples --all --no-interaction

# Overwrite previously-installed copies.
php artisan swarm:install:examples --all --force
```

The installer copies the full example tree as it ships under
`stubs/examples/<name>/`, preserving the `app/Ai/Swarms/<Name>/`,
`app/Ai/Agents/<Name>/`, and `app/Console/Commands/SwarmExample<Name>Command.php`
layout. The `{{ rootNamespace }}` placeholder in every PHP file is rewritten
to the host app's PSR-4 root (read from `composer.json`; defaults to `App\`).

Laravel 11+ Artisan auto-discovery picks the new runner commands up on the
next boot — there is no `routes/console.php` mutation. The installer prints
the exact `php artisan swarm:example:<name>` command for each installed
example.

Behavior contract:

- **Idempotent by default.** A second `swarm:install:examples` run is a
  byte-level no-op on every file in the host app.
- **Refuses to clobber.** If a destination file already exists, the
  installer skips that example with an actionable warning and continues with
  the rest of the selection. Re-run with `--force` to overwrite.
- **`--all` and `--example` are mutually exclusive.** Pass one or the other.
- **Non-interactive mode requires explicit selection.** `--no-interaction`
  on its own errors loudly with the list of available examples — the
  installer never installs everything by accident.

`swarm:install:examples` is also dispatchable from the higher-level
`swarm:install` orchestrator when the operator opts in to examples during a
fresh install.

## What the starter pack ships

### 1. `sequential-blog-pipeline`

Three agents in order: outline → draft → polish. The hello world.
Demonstrates the `Swarm` contract, the sequential topology, the `Runnable`
trait, and reading `$response->output` plus `$response->steps`. Plain
in-memory `prompt()` execution — no queue worker, no database persistence.
The lightest possible swarm.

Runner: `php artisan swarm:example:blog-pipeline "your topic"`

### 2. `parallel-research-fanout`

Three research scouts (market, competitor, customer) run concurrently on
the same prompt; results merge into a single `SwarmResponse`. Demonstrates
the parallel topology, the container-resolvable agent contract, and how
fan-out / join surfaces in `$response->steps`. Still in-memory `prompt()`
execution.

Runner: `php artisan swarm:example:research-fanout "your topic"`

### 3. `durable-approval-workflow`

The showcase. A two-step sequential swarm in **durable** mode with a
`policy_decision` checkpoint between the steps: a draft agent runs, the
swarm parks at the wait, an approver sends a signal, the finalize agent
runs and the run completes. Demonstrates `dispatchDurable()`, the
`RoutesDurableWaits` contract, `DurableSwarmManager::signal()` and the
shipped `swarm:signal` operator command, plus `#[DurableLabels]` and
`#[DurableDetails]` for the audit and dashboard surfaces.

Runner:

```bash
php artisan swarm:example:approval-workflow start "Policy change description"
php artisan swarm:example:approval-workflow signal <run-id> approve
php artisan swarm:example:approval-workflow status <run-id>
```

Requires the durable runtime (database persistence, queue worker,
`swarm:recover` scheduled). `swarm:install` provisions all of this.

## ScriptedAgent — how the starters avoid API keys

Every starter agent extends `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent`
— a runnable, provider-free agent that returns canned text. The swarm
runner treats it identically to a real Laravel AI agent: same `prompt()`
signature, same `AgentResponse` return shape. That is what lets the
starters execute end-to-end on a fresh install with zero environment
configuration.

Each agent file has a `TODO` comment pointing to the one-line edit that
swaps `ScriptedAgent` for a `Promptable` Laravel AI agent. The swarm class,
the runner command, the wait declaration, and the test all keep working
unchanged.

`ScriptedAgent` is intentionally minimal: `prompt()` only. `stream()`,
`queue()`, and the broadcast helpers throw with a clear message that
points users at the right next step (use a `Promptable` agent and
`Agent::fake()`).

## Customising the starters

The starters are copies in the user's app. Edit them freely — there is no
upgrade path that overwrites them. The only convention to keep is the
namespace shape (`App\Ai\Swarms\<Name>` for swarms,
`App\Ai\Agents\<Name>` for agents) so the rest of the package's tooling
(`swarm:status`, `swarm:trace`, Pulse cards) keeps locating runs by their
swarm class.
