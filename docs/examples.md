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

### 4. `hierarchical-support-triage`

A `RequestClassifier` coordinator reads the incoming request, classifies it,
and returns a structured route plan (`start_at` + `nodes`) that dispatches to
exactly one of three handlers — `BillingResponder`, `TechnicalResponder`, or
`GeneralResponder`. Demonstrates the hierarchical topology, a coordinator-owned
route plan (no `route()` callback), and structured output from the coordinator.
In-memory `prompt()` execution.

Runner: `php artisan swarm:example:support-triage "I was double charged"`

### 5. `sequential-contact-extraction`

Two sequential agents turn unstructured text into a validated record:
`FieldExtractor` pulls the raw fields as JSON, then `RecordNormalizer`
validates and canonicalises them into a trusted contact. Both implement
Laravel AI's `HasStructuredOutput` and declare a `schema(JsonSchema)`, so it
demonstrates producing a validated **structured** result rather than prose.
In-memory `prompt()` execution.

Runner: `php artisan swarm:example:contact-extraction "Call Jane at jane@acme.io or 555-0100"`

### 6. `sequential-conversation-memory`

Swarm memory across steps: `RequestListener` extracts a subject from a customer
message and writes it to Run-scoped memory with the real `Remember` tool, then
`ReplyWriter` reads it back with `Recall` and composes a reply that names it.
Step one deliberately omits the subject from its own output, so memory is the
only channel that can carry it to step two. Demonstrates the `remember` /
`recall` memory-as-tool surface and the default propagation policy. In-memory
`prompt()` execution.

Runner: `php artisan swarm:example:conversation-memory "My order HD-2291 hasn't shipped"`

### 7. `durable-streaming-digest`

A two-node sequential swarm marked `#[DurableStreaming]` whose workers
token-stream into the causal log; the runner dispatches durably, drains the
steps, then replays the persisted per-node deltas back via `CausalLogView`.
Demonstrates `#[DurableStreaming]`, `dispatchDurable()`, and per-node stream
reconstruction. Unlike the other starters, its worker (`ScriptedStreamingEditor`)
implements `Agent` directly — `ScriptedAgent` can't stream (see the note below).
Requires database persistence and migrations; the tree's own `README.md` spells
out the setup.

Runner: `php artisan swarm:example:streaming run "Weekly engineering digest"`

## ScriptedAgent — how the starters avoid API keys

Most starter agents extend `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent`
— a runnable, provider-free agent that returns canned text. The swarm
runner treats it identically to a real Laravel AI agent: same `prompt()`
signature, same `AgentResponse` return shape. That is what lets the
starters execute end-to-end on a fresh install with zero environment
configuration. (The one exception is `durable-streaming-digest`, whose worker
must *stream* — `ScriptedAgent` throws on `stream()`, so it ships a small
provider-free streaming agent that implements `Agent` directly.)

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
