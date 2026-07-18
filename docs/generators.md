# Generators

Laravel Swarm ships Artisan generator commands that scaffold the classes
you write to build a swarm: the **swarm** (the orchestration shell), the
**agents** that compose it, and custom **memory tools** an agent can call
mid-prompt. They produce code that matches the shape of the runnable
starter examples shipped under `stubs/examples/` and the shipped `Recall` /
`Remember` tools — so what you generate looks like what the framework
ships.

If you already have a Laravel AI app, this is the same generator
ergonomics as `php artisan make:agent` — same Laravel conventions, same
`app/Ai/` namespace, same publishable stubs.

## At a glance

| Command | Output path | Stub | When to use |
|---|---|---|---|
| `php artisan make:swarm:swarm <Name>` | `app/Ai/Swarms/<Name>.php` | `swarm.stub` (plus topology variants) | You're building a new swarm from an empty shell. |
| `php artisan make:swarm:blueprint <Name>` | `app/Ai/Swarms/<Name>/`, `app/Ai/Agents/<Name>/`, `app/Console/Commands/` | a curated tree from `stubs/examples/` | You want a **complete, runnable** swarm for a use-case (swarm + agents + command), renamed as your own. |
| `php artisan make:swarm:agent <Name>` | `app/Ai/Agents/<Name>.php` | `swarm.agent.stub` | You're adding a new agent to a swarm. |
| `php artisan make:memory-tool <Name>` | `app/Ai/Tools/<Name>.php` | `swarm.memory-tool.stub` (plus a `--vector` variant) | You're building a custom `Recall`/`Remember` memory tool. |
| `php artisan make:swarm <Name>` | `app/Ai/Agents/<Name>.php` **or** `app/Ai/Swarms/<Name>.php` | `swarm.single-agent.stub` or (delegates to `make:swarm:swarm`) | **Deprecated but guided** — the friendly front door. Interactively asks whether to scaffold a single agent or a swarm; still prints a migration hint. Will be removed in a future major release. |

## `make:swarm:swarm`

Scaffolds a swarm class under `app/Ai/Swarms/`. The output implements
`BuiltByBerry\LaravelSwarm\Contracts\Swarm`, pulls in the `Runnable` trait,
and carries the `#[Topology(...)]` attribute for the topology you chose.

```bash
php artisan make:swarm:swarm BlogPipeline
php artisan make:swarm:swarm ResearchFanout --topology=parallel
php artisan make:swarm:swarm RoutingSwarm --topology=hierarchical
php artisan make:swarm:swarm StaticRoutingSwarm --topology=static-hierarchical
```

If you omit `--topology` and run the command **interactively**, you are
prompted to choose one. In **non-interactive** contexts (CI scripts, the
test suite, `Artisan::call(...)`) the command silently defaults to
`sequential` so it stays scriptable.

Pass `--topology=mesh` (or any unknown value) and the command exits 1 with
a clear error listing the valid options.

### Topology variants

| `--topology` | Implements | Stub | Notes |
|---|---|---|---|
| `sequential` (default) | `Swarm` | `swarm.stub` | Each agent's reply becomes the next agent's prompt. Default execution mode is in-memory `prompt()`. |
| `parallel` | `Swarm` | `swarm.parallel.stub` | Every agent receives the original task input; Laravel Concurrency runs them in worker callbacks. Agents must be container-resolvable. |
| `hierarchical` | `Swarm` | `swarm.hierarchical.stub` | The first agent is the coordinator; remaining agents are workers routed at runtime. |
| `static-hierarchical` | `Swarm`, `HasRoutePlan` | `swarm.static-hierarchical.stub` | The route plan is declared up front in `plan()` instead of being decided by a coordinator. |

See `docs/sequential.md`, `docs/parallel.md`, `docs/hierarchical-routing.md`,
and `docs/static-hierarchical-topology.md` for the topology details.

## `make:swarm:blueprint`

Where `make:swarm:swarm` gives you an empty swarm shell for a topology,
`make:swarm:blueprint` scaffolds a whole **working use-case** — the swarm
class, its agents, and a runnable console command — wired together and ready
to run, then **renamed as your own**:

```bash
php artisan make:swarm:blueprint SupportTriage --template=triage
```

That lands `app/Ai/Swarms/SupportTriage/SupportTriage.php`, its agents under
`app/Ai/Agents/SupportTriage/`, and a `SupportTriageCommand` you can run with
`php artisan swarm:run:support-triage` — all namespaced to your app and named
after `SupportTriage`. The agents keep their descriptive class names (a single
swarm name can't sensibly rename three distinct agents); only the swarm class,
its namespace segment, and the console command are renamed.

### Blueprints vs. `swarm:install:examples`

Both draw from the **same** curated corpus under `stubs/examples/`. The
difference is intent:

- [`swarm:install:examples`](examples.md) lands a tree **verbatim** — the
  fixed-name reference copies (`ResearchFanout`, `BlogPipeline`, …) you install
  to *read and learn from*.
- `make:swarm:blueprint` lands the same tree **renamed** — a starting point you
  make *your own* and edit.

### The catalog

| `--template` | Teaches | Topology | When to reach for it |
|---|---|---|---|
| `pipeline` | Sequential refinement | Sequential | Chain agents so each refines the previous step's output. |
| `research` | Fan-out / join | Parallel | Dispatch one prompt to many agents at once, then merge the results. |
| `triage` | Coordinator-owned routing | Hierarchical | Classify an incoming request and route it to the matching handler. |
| `extraction` | Structured output | Sequential | Pull typed, schema-validated data out of unstructured text (not prose). |
| `approval` | Durable human-in-the-loop | Sequential (durable) | A run that pauses for human approval, then resumes from a checkpoint. |
| `memory` | Scoped `SwarmMemory` | Sequential | One agent remembers a fact; a later agent recalls it, so an earlier step shapes a later one. |
| `streaming` | Durable per-node streaming | Sequential (durable) | Worker nodes token-stream into the causal log, replayable per node after the fact. |

Run `make:swarm:blueprint <Name>` with **no** `--template` in an interactive
terminal and you're prompted to pick from this list; omit `<Name>` and you're
prompted for it too.

### Options

| Option | Effect |
|---|---|
| `--template=<slug>` | Which blueprint to scaffold. Omit in an interactive terminal to pick from a list; **required** in non-interactive contexts (fails loud with the available slugs otherwise). |
| `--without-command` | Skip the runnable console command; scaffold only the swarm and its agents. |
| `--force` | Overwrite files that already exist in the host app. |

### Fail-loud behavior

The generator refuses rather than produce a mess: it will not overwrite
existing app files without `--force` (it checks the whole plan *before* writing
anything, so a collision never leaves a half-written scaffold), it rejects a
name that would generate uncompilable code (a reserved word, or one that
collides with a core type like `Swarm`), and it requires `--template` when it
can't prompt.

> **Note:** the `streaming` and `approval` blueprints run **durably**, which
> requires the database persistence driver and migrations — their READMEs spell
> out the setup. The other five run end-to-end on the in-memory driver with no
> configuration.

## `make:swarm:agent`

Scaffolds a swarm agent under `app/Ai/Agents/`. The output extends
`BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` so it runs end-to-end
with no provider configured — exactly the shape used by the starter
examples in `stubs/examples/`.

```bash
php artisan make:swarm:agent OutlineWriter
php artisan make:swarm:agent BlogPipeline/Drafter
```

Slash-separated names produce nested namespaces (`App\Ai\Agents\BlogPipeline\Drafter`).
This is the same convention as `make:job`, `make:event`, and the rest of
the Laravel generator family.

### Swapping in a real LLM

The generated class is intentionally runnable without provider config so
you can wire up the swarm and prove the topology works before you spend
API credit. When you want a real model:

1. Replace `extends ScriptedAgent` with `implements Agent` and `use Promptable;`.
2. Add `#[Provider(...)]` and `#[Model(...)]` PHP attributes.
3. Delete the `reply()` method — Laravel AI's `Promptable` trait owns the
   provider round-trip from here.
4. (Optional) Keep `instructions()` as-is — that contract carries over.

The rest of the swarm wiring stays identical. Drop-in.

## `make:memory-tool`

Scaffolds a custom memory tool under `app/Ai/Tools/`. The generated class
extends one of the shipped memory tools — `Recall` or `Remember` (see
[Swarm Memory](memory.md#recall-and-remember-tools)) — so a custom tool has
the exact same shape and safety guarantees as the framework's own: scope
ids resolve from the active run (never from the model), reads honour the
propagation policy, and writes flow through the capture policy.

```bash
php artisan make:memory-tool TenantRecall
php artisan make:memory-tool DomainRemember --base=remember
php artisan make:memory-tool SwarmNotes --scope=swarm
php artisan make:memory-tool SemanticRecall --vector
```

Drop the generated tool into any `laravel/ai` agent's `tools()` array, or
expose it through the `HasSwarmMemoryTools` trait by binding your subclass
in the container.

### Options

| Option | Values | Default | Effect |
|---|---|---|---|
| `--scope` | `run`, `conversation`, `agent`, `swarm` | `run` | Seeds the tool's default `MemoryScope`. |
| `--base` | `recall`, `remember` | `recall` | Chooses whether the tool extends the read tool (`Recall`) or the write tool (`Remember`). |
| `--vector` | flag | off | Scaffolds a semantic, vector-aware variant. **Requires** the `builtbyberry/laravel-swarm-memory-vector` companion. |
| `--force` | flag | off | Overwrites an existing tool file. |

Pass an unknown `--scope` or `--base` value and the command exits 1 with a
clear error listing the valid options.

Slash-separated names produce nested namespaces
(`App\Ai\Tools\Memory\ScopedRecall`), the same convention as the other
generators.

### The `--vector` flag

`--vector` scaffolds a tool that answers a free-text `query` by semantic
similarity (falling back to exact key/prefix reads) instead of the base
tool's exact lookups. It is only available when the
[`laravel-swarm-memory-vector`](https://github.com/builtbyberry/laravel-swarm-memory-vector)
companion package is installed — the command detects it via Composer and
exits 1 with an install hint when it is absent:

```
The --vector flag requires the builtbyberry/laravel-swarm-memory-vector
companion package, which is not installed. Install it with:
composer require builtbyberry/laravel-swarm-memory-vector
```

The generated vector tool ships a clearly-marked `TODO` in `semanticRecall()`
where you wire the companion's vector reader — consult that package's README
for its exact public surface.

## Customizing the stubs

Publish the shipped stubs into your application to customize them:

```bash
php artisan vendor:publish --tag=swarm-stubs
```

This drops the stub files (`swarm.stub`, `swarm.parallel.stub`,
`swarm.hierarchical.stub`, `swarm.static-hierarchical.stub`,
`swarm.agent.stub`, `swarm.single-agent.stub`, `swarm.memory-tool.stub`,
`swarm.memory-tool.vector.stub`) into your project's `stubs/` directory.
Every generator checks for a published copy first and falls back to the
shipped stub if none is present.

## `make:swarm` — the guided front door

Before v0.8.0, the package shipped a single generator: `make:swarm`. It
scaffolded a swarm class. v0.8.0 split that surface in two — one generator
per kind of class — to match the install-flow rework (#85), and `make:swarm`
became a deprecated alias for `make:swarm:swarm`.

`make:swarm` still works and still prints a deprecation notice, but v0.22.0
turns it into a **guided** entry point. Run it in a real terminal with no
flags and it first asks what you want to build:

```bash
php artisan make:swarm Summarizer
# ? What would you like to scaffold?
#   › A single agent — run it instantly with Swarm::agent(), no swarm class
#     A multi-agent swarm — choose a topology
```

- **Single agent** scaffolds an agent under `app/Ai/Agents/<Name>.php` from
  `swarm.single-agent.stub`. Its docblock demonstrates the
  [`Swarm::agent()`](execution-modes.md#single-agent-swarmagent) front door —
  the full governed pipeline (audit, guardrails, capture, telemetry) for one
  agent, no swarm class required — and points at the inline
  `Swarm::sequential()` / `parallel()` / `hierarchical()`
  [builders](execution-modes.md#inline-swarms-swarmsequential--parallel--hierarchical)
  for when one agent is no longer enough.
- **Multi-agent swarm** falls through to the same topology prompt as
  `make:swarm:swarm`, scaffolding a swarm class under `app/Ai/Swarms/`.

### Staying script- and CI-safe

The prompts never block automation. `make:swarm` does not prompt when:

- `--single` is passed — force the single-agent path:
  `php artisan make:swarm Summarizer --single`;
- `--topology=<slug>` is passed — force the swarm path with that topology;
- it runs non-interactively (`Artisan::call(...)`, a `--no-interaction`
  run, or any non-TTY context) — it defaults to the historical behaviour and
  scaffolds a **sequential swarm class**, exactly as before.

`--single` takes precedence over `--topology`. New code should still call
`make:swarm:agent` / `make:swarm:swarm` directly; the alias is slated for
removal in a future major release.

There is no migration to do for code generated by the old command — the swarm
class shape is unchanged. The changes are purely the split into
`make:swarm:swarm` / `make:swarm:agent` and the guided single-agent path.
