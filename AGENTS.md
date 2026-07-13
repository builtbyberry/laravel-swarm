# Laravel Swarm — Agent Context

<!-- swarm-channel: laravel-swarm -->

This file is operational context for AI coding agents. Human contributors should
start with [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md),
[CHANGELOG.md](CHANGELOG.md), and the user-facing docs in [docs/](docs/).

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
- Laravel AI agents use `prompt()`, `queue()`, `stream()`, `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()`; swarms use those same public verbs plus `dispatchDurable()`.
- `run()` remains available as a compatibility alias for synchronous `prompt()`.
- Laravel AI fakes with `Agent::fake()`; swarms fake with `Swarm::fake()` / `YourSwarm::fake()`.
- Config lives in `config/swarm.php`, not `config/ai.php`.
- Generated swarm classes live in `app/Ai/Swarms/`, extending the `app/Ai/` namespace Laravel AI establishes.

## Product Completeness Standard

- Build the full product behavior, not an MVP approximation.
- Do not choose the path of least resistance if it leaves operational, security, developer experience, documentation, or testing gaps.
- Solve the underlying problem completely, including failure modes, operator workflows, rollout and maintenance implications, and reviewer expectations.
- If a shortcut would trade away correctness, resilience, or product completeness, do not take it silently. Surface the tradeoff explicitly and treat it as a decision, not an implementation detail.
- Default to closing gaps during implementation rather than leaving follow-up work unless the deferral is explicitly approved.

## Definition of Done

A feature or fix is complete only when all of the following are true. Reviewers should treat missing items as blockers, not follow-up work.

- **Artisan command** — if the feature introduces an operational concern (relay, prune, recover, status), it ships with a corresponding `swarm:*` command with a `--help` description.
- **Config key** — every new tuneable has a key in `config/swarm.php` with an inline comment explaining intent and a safe production default.
- **Prune / retention hook** — any new persistent data has a `swarm:prune` integration or an explicit documented reason it is exempt.
- **Test coverage** — new behavior has deterministic Pest tests; concurrency-sensitive paths have a `test:process-concurrency` lane entry.
- **CHANGELOG.md** — every PR includes a changelog entry. Breaking changes also require an `UPGRADING.md` section.
- **Docs parity** — public surface changes update the relevant file in `docs/` or `README.md` in the same PR, not a follow-up.

If a gap is deferred, it must be recorded as a named follow-up with an owner, not silently dropped.

## Tech Stack

- PHP ^8.5
- Laravel ^13.0
- `laravel/ai` ^0.8
- `orchestra/testbench` ^11
- `pestphp/pest` ^4.4 + `pest-plugin-laravel` ^4.1
- `larastan/larastan` ^3.0
- `laravel/pint` ^1.0
- Optional Pulse observability via the [`builtbyberry/laravel-swarm-pulse`](https://github.com/builtbyberry/laravel-swarm-pulse) companion package (not a core dependency)

## Dependency And Upgrade Path

Swarm sits directly on **PHP**, **Laravel**, and **`laravel/ai`** with semver ranges. The package **cannot fully insulate** applications from upstream API, streaming, or provider contract shifts. Treat **Laravel** and **`laravel/ai` bumps** (minor or patch) as **integration-test events**: run your automated suite and any swarm-heavy smoke paths after resolving Composer updates. **This package’s changelog** is the contract for Swarm-owned breaking or behavior changes; it does not replace verifying your app against new Laravel or Laravel AI releases.

## Package Shape

Keep the mental model high-level rather than mirroring every file:

- `src/Attributes` — swarm attributes for topology, timeout, and max agent steps.
- `src/Commands` — `make:swarm` plus history, status, prune, pause, resume, cancel, and recover commands.
- `src/Concerns` / `src/Contracts` — public swarm trait and storage/runtime contracts.
- `src/Events` — lifecycle events for started, step started/completed, completed, failed, paused, resumed, and cancelled.
- `src/Jobs` — queued and durable execution jobs.
- `src/Persistence` — cache and database context, artifact, durable run, run history, and stream replay stores; `SwarmPersistenceCipher` seals designated string columns when database persistence uses encrypter-backed at-rest sealing.
- `src/Responses` — sync, queued, durable, streamable, artifact, response, and step DTOs.
- `src/Streaming` — typed swarm stream events aligned with Laravel AI stream events.
- `src/Routing` — hierarchical route plan objects and validation.
- `src/Runners` — topology runners, sequential stream runner, `DurableSwarmManager` (facade over `src/Runners/Durable/*` collaborators), SwarmRunner, step recorder; see `docs/durable-runtime-architecture.md`.
- `src/Support` — `RunContext`, history query helpers, capture helpers, monotonic time, and runtime support objects.
- `src/Testing` — fakes and assertions.
- `database/migrations` — package-managed persistence and durable runtime tables.
- `docs/` and `examples/` — detailed user-facing behavior and integration examples (`docs/streaming.md` for `stream()`).

## Execution Modes

- `prompt()` executes synchronously and returns a `SwarmResponse`.
- `run()` is a compatibility alias for `prompt()`.
- `queue()` is lightweight background execution: one Laravel queue job owns one swarm run. Queued swarms are re-resolved from the container, so they must not rely on runtime instance state. Pass per-run data in the task payload or `RunContext`.
- `stream()` is sequential-only. It returns a lazy `StreamableSwarmResponse`, emits typed progress and final streamed output, and supports in-memory replay after completion. Persisted replay is opt-in via `storeForReplay()` or `swarm.streaming.replay.enabled`; replay with `SwarmHistory::replay($runId)`. Replay write failures default to failing the stream, unless `swarm.streaming.replay.failure_policy` is set to `continue`; in continue mode, already-written replay events are discarded so partial playback is unavailable. For upstream final-agent streamed events, persisted replay preserves upstream IDs and timestamps; invocation IDs are passed through when present. `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()` are stream-event helpers for sequential swarms; they broadcast typed swarm stream events, not lifecycle events for every topology.
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
- `ContextStore`, `ArtifactRepository`, `RunHistoryStore`, `StreamEventStore`, and `DurableRunStore` are the persistence contracts.
- `SwarmHistory` provides application and console inspection over persisted runs.
- Cache and database drivers are supported; database persistence uses package migrations loaded by the service provider.
- **Capture defaults** — Shipped `config/swarm.php` defaults `swarm.capture.inputs`, `outputs`, `artifacts`, and `active_context` to **false** so prompts and agent payloads are not persisted unless the application opts in (`SWARM_CAPTURE_*` or config). Treat prompts, outputs, lifecycle events, streamed reasoning/tool payloads, and automatic step artifacts as sensitive whenever capture is enabled. With output capture off, streamed tool/reasoning payloads redact values to `[redacted]` while preserving keys where applicable.
- **Encryption at rest (package scope)** — When `swarm.persistence.driver` is `database`, `swarm.persistence.encrypt_at_rest` defaults to **true**. Designated sensitive string columns (for example context `input`, history final `output` and per-step I/O, durable branch I/O, hierarchical node outputs, child run outputs and nested `context_payload` input) are sealed with Laravel’s encrypter (`APP_KEY`), same primitive family as encrypted casts. Stored values use a `sw0:` prefix before the ciphertext so legacy plaintext rows still read correctly. Set `SWARM_ENCRYPT_AT_REST=false` only when you intentionally rely on database- or volume-level encryption instead. Rotating `APP_KEY` without re-encrypting leaves existing rows undecipherable; plan key rotation with your operational model. This is application-level sealing, not a claim of transparent database (TDE) encryption.
- When encrypt-at-rest is enabled, `RunHistoryStore::findMatching` skips SQL JSON-path prefiltering on persisted context (randomized ciphertext cannot satisfy equality predicates in SQL); PHP-side `PersistedRunContextMatcher` still applies after rows are loaded.
- Database retention is prune-based. Expired database rows remain queryable until `swarm:prune` runs, and active runs are protected from partial pruning.
- `run_id` referential integrity is enforced at the database level: all child tables carry `ON DELETE CASCADE` FKs to their parent (`swarm_run_histories` for the history family, `swarm_durable_runs` for the durable family). `parent_run_id` (self-reference), `signal_id`, and `webhook_idempotency.run_id` use `ON DELETE SET NULL`; `child_run_id` has no FK.

## Commands And Operations

Public Artisan commands:

- `php artisan make:swarm`
- `php artisan swarm:status` (includes a **Phase** column: `parallel_join` when a hierarchical coordinated queue run is waiting on parallel branches)
- `php artisan swarm:history` (same **Phase** column)
- `php artisan swarm:prune`
- `php artisan swarm:pause <run-id>`
- `php artisan swarm:resume <run-id>`
- `php artisan swarm:cancel <run-id>`
- `php artisan swarm:recover`

Schedule `swarm:prune` for database-backed persistence. Schedule `swarm:recover` frequently when using durable execution.

## Pulse

Pulse observability lives in the separate companion package [`builtbyberry/laravel-swarm-pulse`](https://github.com/builtbyberry/laravel-swarm-pulse) (namespace `BuiltByBerry\LaravelSwarmPulse\*`), extracted from core in v0.17.1. Install it to get the `SwarmRuns`, `SwarmStepDurations`, and `SwarmMemoryMetrics` recorders and the `swarm.runs` / `swarm.steps` / `swarm.audit-outbox` / `swarm.memory` dashboard cards, registered by its `swarm:install:pulse` sub-installer. Core is no longer aware of Pulse and no longer depends on `laravel/pulse`; it retains only the shared `swarm.pulse.memory.sample_rate` config knob that the companion's memory-metrics recorder reads. See [docs/pulse.md](docs/pulse.md).

Pulse is aggregate observability. For live per-run operations feeds, listen to Laravel Swarm lifecycle events and broadcast application-owned events.

## Key Architecture Decisions

- **Transaction boundaries** — any code that coordinates two systems (DB + queue, DB + cache, two tables, outbox + checkpoint) must either execute inside a single transaction or explicitly document in the code why eventual consistency is acceptable. Flag this at design time. Do not discover it in review.
- Do not use facades inside orchestration internals; inject services and contracts through the container.
- `ParallelRunner` and hierarchical parallel execution use `ConcurrencyManager` from the container, not the facade directly.
- `SwarmRunner` resolves attributes via reflection and falls back to `config('swarm.*')`.
- `Runnable::make()` returns `mixed` so a bound `SwarmFake` can be returned.
- `SwarmFake` intercepts `prompt()`, `run()`, `queue()`, `stream()`, `broadcast()`, `broadcastNow()`, `broadcastOnQueue()`, and `dispatchDurable()` and records assertions. Stream fakes stay lazy and record only when iterated or consumed by broadcast helpers. Broadcast helpers reuse existing buckets: `broadcast()` / `broadcastNow()` satisfy `assertStreamed()`, and `broadcastOnQueue()` satisfies `assertQueued()`.
- **Testing limits:** `SwarmFake::queue()` records dispatch intent only; it does not execute `SwarmRunner`, simulate coordinated hierarchical `multi_worker` branch jobs, durable coordination rows, or `ResumeQueuedHierarchicalSwarm`. Validate that path with database-backed feature tests (see `tests/Feature/QueuedHierarchicalParallelCoordinationTest.php`).
- Queued and parallel safety checks should fail before dispatch when a swarm or worker cannot be container-resolved safely.
- Timeouts are best-effort orchestration deadlines checked before and between steps. They do not hard-cancel an in-flight provider call.
- `BuiltByBerry\LaravelSwarm\LaravelSwarm` is the plain helper class for package-level static toggles and overrides (Cashier/Sanctum/Passport/Horizon/Telescope idiom). Add to it for things like service-provider opt-outs (`ignoreMigrations()`), Eloquent model overrides if the package adopts them, or first-party dashboard auth callbacks if one ships later. Do **not** mirror configuration that already lives in `config/swarm.php` — pick one home for each setting.

## Review Method

Use multi-expert review for meaningful changes, especially streaming contracts, persistence/replay, migrations, security surfaces, and public API drift. Invoke the `anthropic-skills:multi-expert-change-review` skill rather than doing lens reviews inline — it runs all eight lenses in parallel and produces the required output format.

Default review lenses:

- Laravel maintainer: convention fit, DI patterns, contract shape, upgrade safety.
- CTO: enterprise readiness, operational clarity, and long-term maintainability.
- Engineering manager: blast radius, cohesion, rollback safety, and delivery slicing.
- Security specialist: redaction/capture behavior, sensitive surfaces, and failure paths.
- QA specialist: deterministic regression coverage, edge cases, and assertion resilience.
- Docs engineer: behavior/docs/changelog parity and configuration clarity.
- Systems integrator: migration/config compatibility and deployment/runtime wiring risk.
- Regulatory specialist: auditability, provenance requirements, and compliance evidence quality.

Required review output:

- What passes.
- Findings ordered by severity (`high`, `medium`, `low`).
- Open gaps or follow-up items.
- Release impact (`blocker` or `non-blocker`).
- Consolidated verdict: `approve`, `approve-with-followups`, or `changes-required`.

Severity gate:

- `high`: fix before release.
- `medium`: fix now unless explicitly deferred with an owner.
- `low`: may defer if documented.

When making an intentional tradeoff (for example replay provenance or redaction
shape), record the chosen option, rejected option, rationale, and test/doc
updates in the PR notes or changelog entry.

## Release Workflow

Releases are session-shaped: a thematic group of work ships as one tagged release, not one issue per patch. The branching and PR layout below reflects what the v0.5.x and v0.6.x lines actually used; follow it for every release unless the maintainer says otherwise.

**Long-lived release branch**

- Cut `release/v<X.Y.Z>` from `main` when a release session opens.
- Topic branches target the release branch, not `main`. The release branch is the only branch that ever merges back into `main`.
- Open the release with a `chore(release): scaffold v<X.Y.Z> changelog entry` commit so the changelog has a stub from day one.

**Topic branches**

- `feat/<version>-<topic>` — features.
- `fix/<version>-<topic>` — bug fixes that ride a release session.
- `docs/<version>-<topic>` — docs-only work.
- `test/<version>-<topic>` — test-only work.
- `chore/<version>-<topic>` — scoped chores.
- One topic per PR. PR titles end with the originating issue `(#NN)` when there is one.

**Commits — Conventional Commits with a scope**

- Use `feat(scope):`, `fix(scope):`, `docs(scope):`, `test(scope):`, `refactor(scope):`, `chore(scope):`, `build(scope):`, `ci:`.
- Scope tracks the area, not the version: `audit`, `runner`, `pulse`, `release`, etc.

**Three-phase wrap (in order)**

1. `chore/<v>-review-followups` — addresses the multi-expert review findings. Commits are suffixed with the stable finding ID, e.g. `(review F1)`, `(review F2, F3)`. Finding numbers are assigned by the review and do not get renumbered.
2. `chore/<v>-release-wrap` — fills in `CHANGELOG.md` and `UPGRADING.md` for the version. No behavior changes here.
3. `chore/<v>-readiness-followups` — final readiness-gate fixes only. Commits suffixed `(readiness F1)`, `(readiness F2)`. If readiness surfaces anything larger than a small fix, send it back through `review-followups` instead.

**Cutting the release**

- After the wrap phases land on the release branch, open the `release/v<X.Y.Z> → main` PR. Its title is `release: v<X.Y.Z> — <one-line theme>`.
- Tag `v<X.Y.Z>` on `main` after that PR merges. Do not tag on the release branch.

**Deferrals**

- A deferred finding must be recorded as a GitHub issue with an owner and a target version. Do not drop it into a TODO comment or a plan doc.

## Current State

The package supports sequential, parallel, and hierarchical topologies; synchronous, queued, streamed, and durable execution; cache and database persistence; run history and artifacts; lifecycle events; optional Pulse observability via a companion package; config, migration, and stub publishing; and a full fake/assertion system.

Streaming covers typed non-text final-agent events (`swarm_text_end`, `swarm_reasoning_delta`, `swarm_reasoning_end`, `swarm_tool_call`, `swarm_tool_result`) with persisted replay support and Laravel AI-style stream-event broadcast helpers.

The test suite includes Feature and Unit coverage across sequential, parallel, hierarchical routing, streaming, queued execution, durable execution, persistence, commands, fakes, and support objects. (Pulse recorder/card tests moved to the companion package with the v0.17.1 extraction.)

## Known Gaps / Next Work

- Commercial dashboard layer is a separate future repo/product. The package intentionally exposes history, events, and artifacts (with Pulse cards available via the [`builtbyberry/laravel-swarm-pulse`](https://github.com/builtbyberry/laravel-swarm-pulse) companion package) instead of owning a full dashboard API.

## Testing And Quality

From the package root:

```bash
composer test
composer lint
composer analyse
composer format
```

For parallel or hierarchical **concurrency** changes, also run
`composer test:process-concurrency` (optional locally; skips cleanly when the
real `process` driver cannot run). Continuous integration uses
`composer test:process-concurrency:ci`, which fails the build if any test in
that lane is skipped.

The package `TestCase` sets `swarm.capture.*` to **true** and `swarm.persistence.encrypt_at_rest` to **false** so the suite exercises full persisted payloads without coupling every test to the conservative production defaults.

If running phpstan directly, use:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

`composer format` rewrites files with Pint. Use `composer lint` when you need a non-mutating formatting check.
