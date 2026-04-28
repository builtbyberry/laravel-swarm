# Laravel Swarm — Agent Context

## What This Is

`builtbyberry/laravel-swarm` is a Laravel package that adds reusable multi-agent orchestration on top of the official `laravel/ai` package. Laravel AI handles single-agent LLM interactions and low-level workflow primitives. Laravel Swarm turns repeated multi-agent workflows into first-class application objects with sync, queued, streamed, and durable execution modes.

## Package Identity

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **GitHub:** `https://github.com/builtbyberry/laravel-swarm`
- **Author:** Daniel Berry (J Street Digital)
- **Location:** `~/Code/laravel-swarm`

## Core Design Principle — Laravel Native Feel

Every implementation decision must follow existing Laravel and Laravel AI conventions. A developer who knows Laravel AI should look at a swarm class and understand it immediately. Do not invent new framework patterns when Laravel already has a recognizable convention.

- Laravel AI uses `make:agent`; this package uses `make:swarm`.
- Laravel AI agents use `Promptable`; swarms use `Runnable`.
- Laravel AI uses PHP attributes like `#[Provider]` and `#[Model]`; swarms use `#[Topology]`, `#[MaxAgentSteps]`, and `#[Timeout]`.
- Laravel AI agents use `prompt()`, `queue()`, and `stream()`; swarms use `prompt()`, `queue()`, `stream()`, and `dispatchDurable()`.
- `run()` remains available as a compatibility alias for synchronous `prompt()`.
- Laravel AI fakes with `Agent::fake()`; swarms fake with `Swarm::fake()` / `YourSwarm::fake()`.
- Config lives in `config/swarm.php`, not `config/ai.php`.
- Generated swarm classes live in `app/Ai/Swarms/`, extending the `app/Ai/` namespace Laravel AI establishes.

## Tech Stack

- PHP ^8.5
- Laravel ^13.0
- `laravel/ai` ^0.6
- `orchestra/testbench` ^11
- `pestphp/pest` ^4.4 + `pest-plugin-laravel` ^4.1
- `larastan/larastan` ^3.0
- `laravel/pint` ^1.0
- Optional `laravel/pulse` integration

## Package Shape

Keep the mental model high-level rather than mirroring every file:

- `src/Attributes` — swarm attributes for topology, timeout, and max agent steps.
- `src/Commands` — `make:swarm` plus history, status, prune, pause, resume, cancel, and recover commands.
- `src/Concerns` / `src/Contracts` — public swarm trait and storage/runtime contracts.
- `src/Events` — lifecycle events for started, step started/completed, completed, failed, paused, resumed, and cancelled.
- `src/Jobs` — queued and durable execution jobs.
- `src/Persistence` — cache and database context, artifact, durable run, run history, and stream replay stores.
- `src/Pulse` — optional Pulse recorders, cards, and key helpers.
- `src/Responses` — sync, queued, durable, streamable, artifact, response, and step DTOs.
- `src/Streaming` — typed swarm stream events aligned with Laravel AI stream events.
- `src/Routing` — hierarchical route plan objects and validation.
- `src/Runners` — topology runners, durable manager, main runner, and step recorder.
- `src/Support` — `RunContext`, history query helpers, capture helpers, monotonic time, and runtime support objects.
- `src/Testing` — fakes and assertions.
- `database/migrations` — package-managed persistence and durable runtime tables.
- `docs/` and `examples/` — detailed user-facing behavior and integration examples.

## Execution Modes

- `prompt()` executes synchronously and returns a `SwarmResponse`.
- `run()` is a compatibility alias for `prompt()`.
- `queue()` is lightweight background execution: one Laravel queue job owns one swarm run. Queued swarms are re-resolved from the container, so they must not rely on runtime instance state. Pass per-run data in the task payload or `RunContext`.
- `stream()` is currently sequential-only. It returns a lazy `StreamableSwarmResponse`, emits typed stream events for step progress and streamed final-agent output, and supports in-memory replay after completion. Persisted exact stream replay is opt-in through `storeForReplay()` or `swarm.streaming.replay.enabled`; replay stored events with `SwarmHistory::replay($runId)`.
- `dispatchDurable()` is database-backed, checkpointed execution for sequential, parallel, and hierarchical swarms. It persists a durable cursor and advances the swarm through durable jobs. Use events such as `SwarmCompleted` and `SwarmFailed`; durable responses do not support `then()` or `catch()`.

Queued `then()` and `catch()` callbacks remain available for compatibility, but do not recommend them for real queued execution because serialized closures can capture excess state, fail serialization, or store sensitive data in queue payloads.

## Topologies

- **Sequential:** agents run in order; each output becomes the next input.
- **Parallel:** agents run concurrently and each receives the original task. Parallel agents must be stateless, container-resolvable Laravel AI agents because Laravel concurrency serializes worker callbacks.
- **Hierarchical:** the first agent is the coordinator. It must use Laravel AI structured output and return the full route plan. Laravel Swarm validates the plan as a DAG and executes `worker`, `parallel`, and `finish` nodes. In `prompt()`, parallel groups execute concurrently. In `queue()`, hierarchical parallel groups execute sequentially in declaration order in v1 while retaining parallel-safe validation rules.

For hierarchical routing, there is no separate `route()` callback. The coordinator is the single source of truth for what should run next. `#[MaxAgentSteps]` counts the coordinator plus reachable worker executions and fails before worker execution if the validated plan exceeds the limit.

## Persistence, History, And Capture

Laravel Swarm persists run context, artifacts, and run history through configurable stores.

- `RunContext` carries input, structured data, metadata, artifacts, and run ID control.
- `ContextStore`, `ArtifactRepository`, `RunHistoryStore`, and `DurableRunStore` are the persistence contracts.
- `SwarmHistory` provides application and console inspection over persisted runs.
- Cache and database drivers are supported; database persistence uses package migrations loaded by the service provider.
- Capture settings live under `swarm.capture.inputs` and `swarm.capture.outputs`. Treat persisted prompts, outputs, lifecycle events, and automatic step artifacts as sensitive surfaces.
- Database retention is prune-based. Expired database rows remain queryable until `swarm:prune` runs, and active runs are protected from partial pruning.

## Commands And Operations

Public Artisan commands:

- `php artisan make:swarm`
- `php artisan swarm:status`
- `php artisan swarm:history`
- `php artisan swarm:prune`
- `php artisan swarm:pause <run-id>`
- `php artisan swarm:resume <run-id>`
- `php artisan swarm:cancel <run-id>`
- `php artisan swarm:recover`

Schedule `swarm:prune` for database-backed persistence. Schedule `swarm:recover` frequently when using durable execution.

## Pulse

Laravel Swarm has optional Laravel Pulse support. When Pulse is installed, the package can register `swarm.runs` and `swarm.steps` Livewire cards. Applications enable the `SwarmRuns` and `SwarmStepDurations` recorders in `config/pulse.php`.

Pulse is aggregate observability. For live per-run operations feeds, listen to Laravel Swarm lifecycle events and broadcast application-owned events.

## Key Architecture Decisions

- Do not use facades inside orchestration internals; inject services and contracts through the container.
- `ParallelRunner` and hierarchical parallel execution use `ConcurrencyManager` from the container, not the facade directly.
- `SwarmRunner` resolves attributes via reflection and falls back to `config('swarm.*')`.
- `Runnable::make()` returns `mixed` so a bound `SwarmFake` can be returned.
- `SwarmFake` intercepts `prompt()`, `run()`, `queue()`, `stream()`, and `dispatchDurable()` and records assertions. Stream fakes stay lazy and record only when iterated.
- Queued and parallel safety checks should fail before dispatch when a swarm or worker cannot be container-resolved safely.
- Timeouts are best-effort orchestration deadlines checked before and between steps. They do not hard-cancel an in-flight provider call.

## Current State

The package supports sequential, parallel, and hierarchical topologies; synchronous, queued, streamed, and durable execution; cache and database persistence; run history and artifacts; lifecycle events; optional Pulse observability; config, migration, and stub publishing; and a full fake/assertion system.

The test suite includes Feature and Unit coverage across sequential, parallel, hierarchical routing, streaming, queued execution, durable execution, persistence, Pulse integration, commands, fakes, and support objects.

## Known Gaps / Next Work

- Commercial dashboard layer is a separate future repo/product. The package intentionally exposes history, events, artifacts, and Pulse hooks instead of owning a full dashboard API.

## Testing And Quality

From the package root:

```bash
composer test
composer lint
composer analyse
composer format
```

If running phpstan directly, use:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

`composer format` rewrites files with Pint. Use `composer lint` when you need a non-mutating formatting check.
