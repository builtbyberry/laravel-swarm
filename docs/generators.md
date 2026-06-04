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
| `php artisan make:swarm:swarm <Name>` | `app/Ai/Swarms/<Name>.php` | `swarm.stub` (plus topology variants) | You're building a new swarm. |
| `php artisan make:swarm:agent <Name>` | `app/Ai/Agents/<Name>.php` | `swarm.agent.stub` | You're adding a new agent to a swarm. |
| `php artisan make:memory-tool <Name>` | `app/Ai/Tools/<Name>.php` | `swarm.memory-tool.stub` (plus a `--vector` variant) | You're building a custom `Recall`/`Remember` memory tool. |
| `php artisan make:swarm <Name>` | `app/Ai/Swarms/<Name>.php` | (delegates to `make:swarm:swarm`) | **Deprecated** — prints a migration hint and delegates to `make:swarm:swarm`. Will be removed in a future major release. |

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
`swarm.agent.stub`, `swarm.memory-tool.stub`,
`swarm.memory-tool.vector.stub`) into your project's `stubs/` directory.
Every generator checks for a published copy first and falls back to the
shipped stub if none is present.

## Migration from `make:swarm`

Before v0.8.0, the package shipped a single generator: `make:swarm`. It
scaffolded a swarm class. v0.8.0 splits that surface in two — one
generator per kind of class — to match the install-flow rework (#85).

`make:swarm` continues to work. Running it prints a deprecation notice
and delegates to `make:swarm:swarm` with the same arguments. No existing
script or doc needs to change today, but new code should call
`make:swarm:swarm` directly. The alias is slated for removal in a future
major release.

There is no migration to do for code generated by the old command — the
class shape is unchanged. The change is purely in the generator's command
name and the addition of `make:swarm:agent`.
