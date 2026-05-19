# Changelog

## Unreleased

### Changed

- **BREAKING (audit envelope):** `EvidenceEnvelope::SCHEMA_VERSION` bumps from `"1"` to `"2"` (#30). The bump signals a shape change on `command.*` evidence: the legacy top-level `actor` literal (`'actor' => 'artisan'`) is removed from `command.pause`, `command.resume`, `command.cancel`, `command.recover`, and `command.relay` payloads. Actor identity now flows through the standard `metadata.actor` slot as an `Actor` value object array, matching how every other category (`run.*`, `step.*`, `durable.*`) already exposes it. `swarm:prune` evidence, which previously carried no actor at all, now also emits `metadata.actor`. See `UPGRADING.md` v0.5.0 block for the migration walk-through.
- **REFACTOR (internal):** `SwarmRunner` (930 lines) decomposed into three focused collaborators (#21): `RunAuditEmitter` (centralizes run-level audit payload composition), `DispatchValidator` (dispatch-time validation), and `LeaseManager` (queue lease-seconds policy + durable coordination lease helpers). The class is `@internal` and the public API (`run`, `runQueued`, `stream`, `broadcast`, `queue`, `broadcastOnQueue`, `dispatchDurable`, `resumeQueuedHierarchicalAfterJoin`) is unchanged.

## v0.4.3 - 2026-05-19

### Added

- `SwarmFake` gains three new actor assertions (#34): `assertDispatchedWithActor(Actor|string|callable)`, `assertDispatchedWithAnyActor()`, and `assertNeverDispatchedWithActor()`. Helpers inspect every dispatch bucket (run, queue, durable, stream) for a `RunContext` whose `metadata.actor` matches. Bare-string and structured-array tasks never carry an actor — pass an explicit `RunContext::fromTask($task)->withActor(...)` when you want the binding to be visible to `SwarmFake`.
- New `## Testing Audit Extension Points` section in `docs/testing.md` documents the three patterns for testing the four v0.4 audit-extension contracts (`CapturePolicy`, `SinkFailureHandler`, `SwarmAuditSigner`, `ActorResolver`): unit-test the contract directly, bind a recording `SwarmAuditSink` for end-to-end audit checks, or use `'halt'` failure policy to assert run-level halt behavior. Worked examples reference the package's own `tests/Unit/Audit/` suite.
- New `## Asserting Actor Binding` section in `docs/testing.md` walks through the three new SwarmFake assertions with code samples, including the `Context::add('swarm:actor', ...)` + explicit `withActor()` pattern for tests that bridge the Laravel Context facade and SwarmFake.

### Changed

- Reframed the original #34 issue scope. The issue asked for assertions on all four v0.4 audit extension contracts, but only `Actor` has a dispatch-time signal SwarmFake can observe — the other three are runtime concerns inside the audit dispatcher, a path SwarmFake intentionally skips. The new docs section captures the three contracts' actual test patterns instead of pretending SwarmFake covers them.

## v0.4.2 - 2026-05-19

### Fixed

- Durable retry handler and branch advancer now log caught exceptions instead of swallowing them silently (#1). Previously, any exception thrown inside an agent's `prompt()` call (or any tool side-effect, broadcast dispatch, etc.) was caught and either rescheduled for retry or marked as a terminally failed branch with no entry in the application log, `failed_jobs`, or anywhere else — producing symptoms identical to transient LLM API failures and making the root cause invisible without reading the package source.
  - `DurableRetryHandler::scheduleRunRetryIfAllowed` and `scheduleBranchRetryIfAllowed` now emit `Log::warning('Durable swarm {step,branch} failed — scheduling retry.', [...])` before scheduling, with `run_id`, `retry_attempt`, `max_attempts`, `next_retry_at`, `exception` class, and `message` (plus `branch_id` / `agent_class` on the branch path).
  - `DurableBranchAdvancer` now emits `Log::error('Durable swarm branch failed — retries exhausted or non-retryable.', [...])` before calling `markBranchFailed`, with the same fields. The run-level terminal failure path already rethrows via `DurableStepAdvancer`, so failed-jobs / Laravel exception handler observability is already present there; the branch path was the silent one because its catch block returns normally after the failure.
- Both `DurableRetryHandler` and `DurableBranchAdvancer` now accept a constructor-injected `Psr\Log\LoggerInterface`. Container auto-resolution handles existing wiring; no service-provider changes required for application code.

## v0.4.1 - 2026-05-19

### Fixed

- `durable.*` audit categories now source `swarm_class` and `topology` from the durable run row instead of optional `RunContext::metadata` mirrors (#28). Previously these fields were typed `string|null` and would emit `null` whenever metadata was absent; they are now guaranteed non-null on all six `durable.*` categories (`durable.completed`, `durable.failed`, `durable.cancelled`, `durable.paused`, `durable.checkpointed`, `durable.checkpointed_hierarchical`). The change is implemented via two new helpers on `DurableRunContext` (`swarmClassFor()`, `topologyFor()`) that delegate to the existing `requireRun()` row lookup. Sinks that previously branched on null no longer need that branch.
- `command.relay` audit events with `status: "error"` now include `exception_class` (#32). The frozen schema in `docs/audit-evidence-contract.md` has documented this field since v0.4.0 but the emit in `SwarmRelayCommand` was missing it — the doc and the code now match.
- `durable.cancelled` audit events now include `duration_ms` (#33), bringing the cancelled category into parity with `durable.completed` and `durable.failed`. Computed via the existing `DurableRunContext::durationMillisecondsFor()` helper.
- `webhook.signal_received` audit events now include `swarm_class` (#29), bringing the category into parity with the sibling `webhook.start_*` categories. The field is plumbed through the new optional `swarmClass` property on `DurableSignalResult` — the property is nullable for backward compatibility with the public `FakeDurableSwarmManager` test fake, but is always populated in the production webhook path.

### Changed

- `docs/audit-evidence-contract.md` frozen schema updated: dropped `string|null` annotations on `durable.*` `swarm_class` and `topology`, added `duration_ms` to `durable.cancelled`, added `swarm_class` to the `webhook.signal_received` envelope. All marked with `(since v0.4.1)` so sinks know when the new shape became reliable.

## v0.4.0 - 2026-05-18

### Added

- **Actor / identity binding for audit evidence (#14).** New `BuiltByBerry\LaravelSwarm\Audit\Actor` value object (immutable id/type/name/metadata with `Actor::system()`, `Actor::user($authenticatable)`, and `Actor::fromAny()` named constructors). New `ActorResolver` contract bound by default to `DefaultActorResolver`, which reads `Context::get('swarm:actor')` first (survives queue serialization), then falls back to `auth()->user()`, then null. Resolution happens once at every dispatch entry point (`run`, `queue`, `broadcastOnQueue`, `dispatchDurable`, `stream`) so attribution captures dispatch-time identity rather than worker-time. The resolved actor is stored under the reserved `metadata.actor` key, which `EvidenceEnvelope` now emits on every audit record regardless of the configured allowlist. New `RunContext::withActor(Actor|Authenticatable|string|null)` fluent setter overrides the bound resolver. New `swarm.audit.actor.required` config flag (env `SWARM_AUDIT_ACTOR_REQUIRED`, default `false`) — when `true`, runs without a resolvable actor throw `MissingActorException` at entry. Regulated callers (21 CFR Part 11, SOC 2) enable the flag and bind actor via `Context::add('swarm:actor', $actor)` before dispatch.
- **`CapturePolicy` contract for declarative capture decisions (#15).** New `BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy` (multi-method: `inputs`, `outputs`, `artifacts`, `activeContext` — each taking `?RunContext` and `?Actor`). New `CaptureDecision` enum (`Full | Redact | Skip`). New `BooleanCapturePolicy` bound by default; reads the existing `swarm.capture.*` booleans and returns `Full` when true, `Redact` when false — preserves v0.3 capture behavior exactly. Custom policies bind via `$this->app->bind(CapturePolicy::class, MyPolicy::class)` and make per-run decisions with context and actor visibility. `SwarmCapture` is refactored to delegate every capture decision to the bound policy; the public v0.3 surface (`input()`, `output()`, `context()`, `step()`, `capturesInputs()`, etc.) is unchanged so all 17+ existing injection sites continue to work.
- **`SinkFailureHandler` contract with halt support (#16).** New `BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler` returns a `SinkFailureDecision` enum (`Swallow | RetryInline | Halt`). New `ConfiguredSinkFailureHandler` bound by default maps the existing `swarm.audit.failure_policy` config values plus a new `'halt'` value (alongside existing `'swallow'` and `'log'`). When the handler returns `Halt`, the dispatcher throws `AuditSinkHaltedException`, which carries the new `HaltsSwarmExecution` marker interface — `SwarmRunner` detects the marker and surfaces the halt as a deliberate run-level failure rather than swallowing it as an audit concern. The dispatcher retry loop is capped at `SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS = 5` to prevent runaway loops from buggy custom handlers; exceeding the cap throws a `SwarmException` with the original sink failure as `$previous`. `Queue` and `DeadLetter` decision cases are reserved for v0.5 alongside the audit outbox.
- **`SwarmAuditSigner` contract for tamper-evident evidence (#13).** New `BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner` is invoked in the dispatcher after envelope enrichment and before sink emit. No default binding — when no signer is bound, the dispatcher emits payloads unchanged (v0.3 behavior preserved). Custom signers attach cryptographic signatures (HMAC, ECDSA, chain-signed hashes) to every audit record; implementations must not mutate or remove existing keys, only add signature fields. Signing failures route through the same `SinkFailureHandler` as sink failures, so callers who want strict halt-on-signing-failure set `swarm.audit.failure_policy=halt`. Signing scope (entire payload vs canonical subset), algorithm, and chain-signing semantics are implementation concerns.
- **Audit evidence envelope schema freeze.** `docs/audit-evidence-contract.md` now formally commits to the v0.x envelope shape: `schema_version`, `category`, `occurred_at`, plus the enumerated category-specific correlation fields. Additive changes (new fields, new categories) ship within the v0.x line; breaking shape changes increment `schema_version` and land in a future minor with a per-version UPGRADING block. Sinks that parse strictly should switch on `schema_version`.
- **`@internal` PHPDoc applied across the codebase.** 93 internal classes marked: runners, persistence stores, jobs, dispatchers, telemetry helpers, support utilities, Pulse Livewire components, and routing internals. Public surface (Facades, contracts, value objects, response DTOs, events, exceptions, Artisan commands, Pulse Recorders, `SwarmServiceProvider`, `RunContext`) deliberately left unmarked and committed to per the new `## Stability and the public API` section in `UPGRADING.md`. Consumers can now grep `@internal` to distinguish package internals from the stability surface.

### Changed

- `SwarmAuditDispatcher` constructor signature changed: now takes `(SwarmAuditSink, ConfigRepository, SinkFailureHandler, ?SwarmAuditSigner)` — `LoggerInterface` is no longer injected directly (it moves into `ConfiguredSinkFailureHandler`). The dispatcher is marked `@internal`; application code that resolves it via the container is unaffected.
- `SwarmCapture` constructor signature changed: now takes `(ConfigRepository, CapturePolicy)`. The class is marked `@internal`; container-resolved usage is unaffected.
- `SwarmRunner` constructor adds an `ActorResolver` dependency. The runner is marked `@internal`; container-resolved usage is unaffected.
- `composer.json` `extra.branch-alias.dev-main` bumped from `0.3.x-dev` to `0.4.x-dev`.

### Deprecated

- The four `swarm.capture.*` boolean config keys (`capture.inputs`, `capture.outputs`, `capture.artifacts`, `capture.active_context`) are deprecated in favor of binding a custom `CapturePolicy`. The booleans remain functional through `BooleanCapturePolicy` and are scheduled for removal in v0.5 with a per-version UPGRADING block.

## v0.3.5 - 2026-05-18

### Added

- `swarm:health --durable` now proactively checks `SWARM_CAPTURE_ACTIVE_CONTEXT` and reports `failed` when the env var is not enabled. Queued and durable dispatch require active-context capture; the runner has always thrown `SwarmException` at dispatch when it is missing, but the misconfiguration is now caught at preflight rather than at the first live run. CI/CD pipelines that gate on `swarm:health --durable` exit code may newly exit `1` in environments where the env was implicitly relied on but unset — see [UPGRADING.md](UPGRADING.md#swarmhealth---durable-now-fails-on-missing-swarm_capture_active_context).
- New `docs/app-key-rotation.md`: runbook covering the encryption asymmetry between sealed operational rows and unaffected audit evidence, the drain-then-rotate and in-place re-encryption strategies, and how retention windows interact with key rotation. Cross-linked from `docs/configuration.md`, `docs/persistence-and-history.md`, and the docs index.
- New `docs/metadata-allowlist-governance.md`: governance doc covering metadata vs capture payloads, named anti-patterns (raw user identifiers, regulated product names, authentication material, high-cardinality free text, mutable PII buckets), and the allowlist review checklist. Cross-linked from `docs/audit-evidence-contract.md`, `docs/observability-correlation-contract.md`, and the docs index.
- `.github/workflows/nightly.yml`: informational nightly job runs the full suite against `laravel/framework:dev-main` with as-aliased `illuminate/*` packages so Composer accepts them against the package's `^13.0` constraints. Marked `continue-on-error: true`; surfaces upstream churn without gating PR CI. README gets a dedicated nightly status badge.
- `.github/workflows/mutation.yml`: daily Pest mutation testing job via `pestphp/pest-plugin-mutate` (already a Pest 4 transitive). Runs at 07:17 UTC plus `workflow_dispatch` for on-demand. 120-minute timeout cap; `continue-on-error: true`. A gating threshold via `--min` will land in a follow-up once baseline scores stabilise.

### Changed

- README production checklist surfaces `swarm:relay` alongside `swarm:recover` and `swarm:prune` with frequency, purpose, and a cross-link to `docs/durable-execution.md`. Operators wiring durable swarms from the README no longer have to discover separately that the outbox drain command must be scheduled.
- README and UPGRADING document the `minimum-stability: dev` / `prefer-stable: true` propagation requirement explicitly, the rationale (`laravel/ai` is pre-1.0 and ships dev-tagged releases), and the plan to drop the requirement when `laravel/ai` reaches 1.0.
- `examples/README.md` clarifies that example files are reference-only and must be copied into the consuming application's namespace before use. Previously the autoload behavior was implied but never stated.
- CI enforces an 80% line-coverage floor via the new `composer test:coverage:ci` script (Pest `--coverage --min=80`). Local `composer test:coverage` is left unchanged so iteration is not slowed by an enforced threshold. The 80% floor is set deliberately below currently measured coverage so the gate enforces rather than aspires; raise it deliberately as the suite grows.
- `composer test:mutation` swapped from `infection/infection` to Pest's native mutation plugin (`pestphp/pest-plugin-mutate`). Infection 0.33's auto-generated PHPUnit config emits the legacy `<filter>` element, which Pest 4 rejects — producing a false negative even when every test passes. The Pest-native path integrates with the existing test runner and removes the XML mismatch.

### Fixed

- `tests/Feature/SwarmRecoverCommandTest.php`: applied missed Pint formatting.

## v0.3.4 - 2026-05-15

### Added

- `make:swarm` now scaffolds all four topologies (sequential, parallel, hierarchical, static-hierarchical). Previously only sequential and static-hierarchical were supported.
- `make:swarm` now prompts interactively for topology when run from a TTY without `--topology`. Pass `--topology=sequential` to preserve the previous non-interactive behavior in scripts. Non-interactive callers (`Artisan::call()`, piped stdin) continue to default to sequential.
- New published stubs: `swarm.hierarchical.stub` and `swarm.parallel.stub` — available via `php artisan vendor:publish --tag=swarm-stubs`.
- `swarm:recover` now warns when outbox rows are aging past the staleness threshold without being relayed. Surfaces the message "N outbox row(s) aging past Xs without being relayed — is swarm:relay scheduled?" to help operators detect an unscheduled relay.
- Full documentation audit: 9 new reference docs (`introduction.md`, `sequential.md`, `parallel.md`, `execution-modes.md`, `events.md`, `run-context.md`, `artifacts.md`, `error-handling.md`, `configuration.md`), 4 expanded docs (`pulse.md`, `guardrails.md`, `durable-execution.md`, `observability-logging-tracing.md`), new `examples/static-hierarchical-content-swarm/` example, and a restructured `docs/README.md` with audience-based navigation.

### Changed

- Package homepage and Packagist documentation link now point to the new documentation site at https://swarm.builtbyberry.com. `composer.json` `homepage` was updated and a new `support.docs` entry was added. README adds a documentation-site badge, a top-of-file callout, and promotes the docs site to the first bullet in the metadata list; the in-repo `docs/README.md` is now labeled "In-repo docs" and recommended for offline use.

## v0.3.3 - 2026-05-15

### Added

- **StaticHierarchical topology** (`Topology::StaticHierarchical`): eliminates the coordinator LLM
  call when routing is static. Swarms carry `#[Topology(TopologyEnum::StaticHierarchical)]`,
  implement the new `HasRoutePlan` contract, and return a fixed route-plan array from `plan()`.
  Supports `prompt()`, `run()`, `queue()` (in_process), and `stream()`. `dispatchDurable()` is
  unsupported in v1 and throws before any infrastructure check fires.
  - Sequential worker nodes always stream live text deltas.
  - Parallel groups in `stream()` use `concurrent` mode by default (branches run via
    `ConcurrencyManager`; the sequential tail streams live) or `sequential` mode (each branch
    streams in declaration order). Controlled by `#[StreamParallelBranches('concurrent'|'sequential')]`
    on the swarm class, or the `swarm.static_hierarchical.stream_parallel_branches` config key
    (`SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES`).
  - Same parallel groups, `with_outputs` named-output synthesis, DAG validation, and `MaxAgentSteps`
    enforcement as hierarchical. `MaxAgentSteps` counts worker nodes only — there is no coordinator
    step.
  - Swarm response and `SwarmCompleted` metadata include `topology`, `route_plan_start`,
    `executed_node_ids`, `executed_agent_classes`, `parallel_groups`, `executed_steps`, and
    `execution_mode`. `coordinator_agent_class` is intentionally absent.
  - Documentation: `docs/static-hierarchical-topology.md`.

## v0.3.2 - 2026-05-13

### Changed

- Test suite verified against `laravel/ai` v0.6.8. Two test fixtures were relying on an implicit per-class agent fake fallback removed in v0.6.8; they now explicitly fake all agents in the swarm.

### Added

- `swarm:relay --max-attempts=N`: limits the number of drain iterations when used with `--drain-until-empty`. Without this flag the loop continues only while there is real progress; with it, the loop also retries through batches of pure transient failures up to N times total, making it suitable for clearing backlogs during a recovering queue outage. Iterations run consecutively with no sleep — size N accordingly.
- `swarm:health --durable` outbox staleness check now reports three states: "no pending rows" (ok), "N pending rows, relay appears active" (ok), and "N rows aging past {threshold}s — is swarm:relay scheduled?" (warning). Previously the check was binary ok/warning with a single threshold.
- `swarm.durable.relay.stale_warning_threshold_seconds` config key (`SWARM_DURABLE_RELAY_STALE_WARNING_THRESHOLD_SECONDS`). `0` falls back to `2 × reservation_timeout_seconds` (backwards-compatible default).
- Static "Relay scheduling" note row added to `swarm:health --durable` output. Reminds operators that `swarm:relay` must be scheduled. Status is `note` (informational only; does not affect exit code).
- `swarm:health --durable` now includes an **Outbox queue routing** check: warns when outbox rows reference a `queue_connection` not present in `config/queue.php`. Rows with an unknown connection are permanently skipped at drain time; this surfaces them before they accumulate silently.
- `DrainResult` gains two new fields: `claimed` (rows reserved in phase 1) and `reclaimed` (subset whose `reserved_at` was already set, indicating a prior relay worker did not complete dispatch). Both default to `0` (backwards-compatible). Custom `DurableOutbox` implementations may populate these fields when returning `DrainResult`; applications that only consume the result need no changes.
- `swarm:relay` audit payload now includes `claimed_count` and `reclaimed_count` alongside the existing `dispatched_count`, `skipped_count`, and `failed_count`.
- `swarm.limits.max_metadata_bytes` config key (`SWARM_MAX_METADATA_BYTES`; `null` = uncapped). Enforced via `SwarmPayloadLimits::checkMetadata()` at run start in `SwarmRunner` and `DurableSwarmStarter`. The `truncate` overflow policy does not apply to metadata; only `fail` fires when the limit is exceeded.
- Documented recommended queue topology patterns in `docs/maintenance.md`: minimal (single queue), durable sequential (separate worker pool), and durable with parallel branches (third pool to prevent saturation deadlock on saturated step queues).
- Added `## Metadata Governance` section to `docs/audit-evidence-contract.md` covering the allowlist approach, custom sink redaction example, and scope clarification (sink-layer only; does not affect `RunContext` or database capture).

### Fixed

- `swarm:relay` / `DatabaseDurableOutbox::drain()` reported a false green when all entries in a batch failed transiently (queue driver down): `total()` returned 0, `--drain-until-empty` exited, and the command printed "No pending outbox entries were found." The new `DrainResult::$failed` counter tracks transient failures separately; the command now exits with status 1 and a descriptive warning when unresolved transient failures remain at exit, and the "no pending entries" message is only printed when the outbox is genuinely empty.
- `DatabaseDurableOutbox::drain()` silently rerouted entries with an unknown `queue_connection` to the application default queue instead of treating them as permanently invalid. An unknown stored connection name is now an `UnexpectedValueException` (row deleted, reported, counted as `skipped`) — the same contract as an unknown `dispatch_type`. The previous behaviour undermined queue-isolation guarantees.
- `DatabaseDurableOutbox::dispatchEntry()` blindly cast missing or non-integer `step_index` payload fields to `(int)` (giving step 0) and missing or empty `branch_id` fields to `(string)` (giving `''`). Both cases are now validated and throw `UnexpectedValueException` when the required field is absent or the wrong type, correctly treating the row as permanently invalid rather than dispatching the wrong job.

## v0.3.1 - 2026-05-12

### Fixed

- `swarm:relay` / `DatabaseDurableOutbox::reserve()` threw `BadMethodCallException: Call to undefined method Builder::skipLocked()` on Laravel 13 with Postgres or MySQL. `FOR UPDATE SKIP LOCKED` must be expressed as a string passed to `->lock()` — there is no chainable `skipLocked()` method on `Illuminate\Database\Query\Builder`. ([#3](https://github.com/builtbyberry/laravel-swarm/issues/3))

## v0.3.0 - 2026-05-12

### Added

- **Transactional outbox for durable dispatch:** `DurableOutbox` contract and `DatabaseDurableOutbox`
  implementation write outbox rows atomically inside checkpoint, branch-wait, and retry transactions.
  `swarm:relay` (new Artisan command) drains and dispatches them; it must be scheduled
  (`Schedule::command(‘swarm:relay’)->everyMinute()`). Two-phase drain (claim with `SKIP LOCKED`,
  dispatch, batched delete) prevents duplicate jobs under concurrent relay workers. Per-entry error
  isolation means a single bad row cannot poison the batch. Fixes the parallel-topology join-boundary
  stall (GitHub issue #2).
- `swarm:relay` Artisan command: drains the durable outbox and dispatches queued jobs. Options:
  `--type=step|branch` (filter by dispatch type), `--limit=N` (default 100, max 10 000),
  `--drain-until-empty` (loop until the outbox is clear). Run `swarm:relay --help` for details.
- `swarm:health --durable` now includes an **Outbox relay** check: warns when unclaimed or
  stale-reserved outbox rows are older than 2× `swarm.durable.relay.reservation_timeout_seconds`,
  helping detect a stalled relay or a relay worker that crashed mid-dispatch.
- `swarm:prune` now prunes orphaned `swarm_durable_outbox` rows (rows whose parent run has expired
  and been pruned but whose outbox entry was not cascade-deleted, e.g. reserved rows that expired).
- Migration `2026_05_11_000002_optimize_swarm_durable_outbox_indexes.php`: replaces the composite
  `swarm_outbox_drain_idx` with two targeted indexes—`(available_at, id)` for unfiltered drains and
  `(dispatch_type, available_at, id)` for type-filtered drains—plus a PostgreSQL partial index on
  `(available_at, id) WHERE reserved_at IS NULL`.
- **Guardrails v1:** `SwarmInputGuardrail`, `SwarmStepGuardrail`, `SwarmOutputGuardrail`, optional
  `DefinesGuardrails::guardrails()`, centralized `SwarmGuardrailRunner`, `GuardrailViolation`
  (`policyCode`, `reason`, `metadata`, `scope`, `::block()`),
  `config/swarm.php` `guardrails.*` (including child inheritance and parallel sync policy), wiring through
  `SwarmRunner`, durable starters, sequential/parallel/hierarchical/stream/durable paths, and
  `MissingQueueLeaseSchemaException` for missing queued-lease columns (distinct from runtime
  `LostSwarmLeaseException`). Documentation: `docs/guardrails.md`. Feature and unit tests under
  `tests/Feature/Guardrails*.php`, `tests/Unit/Runners/SwarmGuardrailRunnerTest.php`,
  `tests/Unit/Exceptions/GuardrailViolationTest.php`.

### Changed

- **Breaking:** `DurableOutbox::drain()` now returns `DrainResult` (`Responses\DrainResult`) instead
  of `int`. `DrainResult` exposes `dispatched` (entries queued successfully), `skipped` (permanently
  invalid entries deleted without dispatch), and `total()`. Custom `DurableOutbox` implementations
  must update their return type and return a `new DrainResult(dispatched: $n, skipped: $m)` instance;
  applications that only inject the contract need no changes.
- `DrainResult` lives in the `Responses\` namespace, not `Contracts\`.
- `DatabaseDurableOutbox::drain()` now uses a two-catch dispatch loop: `UnexpectedValueException`
  signals a permanently invalid row (unknown `dispatch_type`, non-array or malformed JSON payload) —
  the entry is reported via `report()`, deleted immediately, and counted in `skipped`. Any other
  `Throwable` is treated as transient — the entry retains `reserved_at` and is re-claimable after the
  reservation timeout.
- `DatabaseDurableOutbox::drain()` collects dispatched IDs and issues a single batched `WHERE IN`
  DELETE at the end of each loop iteration instead of one DELETE per dispatched entry.
- `command.relay` audit event field renamed from `dispatched` to `dispatched_count`; the failure-path
  event now also includes `dispatched_count` and `skipped_count` reflecting entries processed before
  the error.
- `DurableRunRecorder::checkpointSequential` and `checkpointHierarchical` accept an optional
  `?callable $withTransaction` that is invoked inside the DB transaction before commit, enabling
  atomic checkpoint + outbox writes. This is an `@internal` API used by collaborators only.
- `DurableHierarchicalCoordinator::checkpointBranchWait` similarly accepts `?callable $withTransaction`
  for atomic branch-wait + outbox enqueue.
- `DurableRetryHandler` now enqueues zero-delay retries inside the retry transaction rather than after
  it, closing the crash window between retry state commit and dispatch.
- `DurableLifecycleController::resume` now uses the freshly loaded post-resume run row for queue
  routing instead of the stale pre-resume snapshot.
- `DurableRecoveryCoordinator::recover` `$dispatchStep` and `$dispatchBranch` parameters are now
  required (no default closures). This is an `@internal` change.
- `QueuedHierarchicalDurableCoordinator` delegates `validateStepTimeoutSeconds` to `DurableRunContext`
  instead of maintaining a duplicate copy.
- `GuardrailViolation` uses a `policyCode` property instead of `code`, avoiding collision with PHP’s
  inherited `Exception::$code`.

### Fixed

- `swarm:health --durable` outbox relay check now detects stale-reserved rows — entries claimed by a
  relay worker that crashed mid-dispatch — in addition to unclaimed rows. Previously a crashed relay
  appeared healthy for up to the full reservation timeout window.
- `DurableRetryHandler::scheduleBranchRetryIfAllowed` now wraps its state change in a transaction,
  consistent with the run retry path. Fixes a narrow window where a branch retry state change could
  be written without the corresponding outbox enqueue completing atomically.
- Output-phase guardrail violations are handled inside `SwarmRunner::runWithExecutionMode()`’s primary
  `try` so failures call `historyStore->fail`, emit `SwarmFailed`, and merge safe guardrail metadata—same
  as other orchestration failures (previously `finalizeSuccessfulSwarmExecution()` sat outside that
  `try`, so `GuardrailViolation` could escape without lifecycle handling). The queued hierarchical
  resume-after-join success path wraps finalization the same way.
- `queue()` and `broadcastOnQueue()` now persist a preflight failure row and dispatch `SwarmFailed`
  when an input guardrail blocks at dispatch time (previously the violation was thrown without any
  history or event being written).
- `dispatchDurable()` now persists a preflight failure row and dispatches `SwarmFailed` when an input
  guardrail blocks before the durable transaction runs (previously the violation escaped without any
  history row).
- Stream input guardrails now fire eagerly at `stream()` call time, before `StreamableSwarmResponse`
  is constructed. Previously they ran lazily inside the generator and only fired when the caller began
  iterating, leaving a window where a blocked stream was returned without any history or event written.
- `own_global_and_parent` child-inheritance mode now logs a `warning` (via injected `LoggerInterface`)
  when the parent swarm class cannot be found or resolved from the container, instead of silently
  dropping parent guardrails.

## v0.2.0 - 2026-05-05

### Added

- Durable workflow controls: native wait/signal, pause/resume/cancel/recover,
  progress, labels/details, child swarms, authenticated webhooks, webhook
  idempotency retention, and operator commands including `swarm:inspect`,
  `swarm:progress`, `swarm:signal`, and `swarm:health`.
- Durable runtime hardening: multi-wait timeout recovery, retry dispatch
  deduplication, configurable durable job tries/timeouts/backoff, coordinated
  hierarchical parallel execution for `queue()` via `multi_worker`, durable
  state side tables, and database-level run-id foreign keys.
- Enterprise evidence and telemetry hooks: `SwarmAuditSink`,
  `SwarmTelemetrySink`, schema-versioned evidence envelopes, lifecycle and
  operator evidence categories, queue timing telemetry, stream/broadcast event
  telemetry, and metadata allowlists.
- Persistence and retention controls: `swarm:prune --dry-run`,
  `swarm.retention.prevent_prune`, database encrypt-at-rest sealing with
  `sw0:` prefixes, decrypt failure policy configuration, and cache/database
  readiness probes.
- Release-quality guardrails: `LaravelSwarm::ignoreMigrations()`, PHPStan level
  7, coverage and process-concurrency CI lanes, serializing concurrency test
  coverage, and `composer test:process-concurrency`.
- Webhook callback auth is now a release-ready driver. `callback` supports
  native callables, invokable classes resolved through the container, and
  `Class@method` strings resolved through the container; only strict `true`
  authorizes a request.

### Changed

- **Breaking:** `swarm.capture.inputs`, `outputs`, `artifacts`, and
  `active_context` now default to **false**. Enable the needed
  `SWARM_CAPTURE_*` values for persisted prompts/outputs; queued and durable
  execution require `active_context=true`.
- **Breaking (extend-only):** `DatabaseContextStore`, `DatabaseRunHistoryStore`,
  and `DatabaseDurableRunStore` constructors now accept
  `SwarmPersistenceCipher`; custom subclasses or manual construction must pass
  the cipher from the container.
- `SwarmPersistenceCipher` now injects `Psr\Log\LoggerInterface`; decrypt
  failures follow `swarm.persistence.decrypt_failure_policy`
  (`null_with_log`, `legacy`, or `throw`) instead of always returning opaque
  ciphertext.
- Durable step advancement internals were decomposed into focused collaborators
  while preserving public manager, job, event, response, and persistence
  contracts.
- Completed database run-history context now seals `context.input`, and
  `SequentialStreamRunner` now writes history before context to satisfy FK
  ordering.
- GitHub Actions now covers stable-latest and lowest dependency lanes for the
  PHP 8.5 / Laravel 13 support range, with coverage, Pint, PHPStan, and
  process-concurrency checks.

### Documentation

- Reworked the README and examples around a Laravel-style learning path:
  install, create a swarm, run it, choose an execution mode, then add
  production operations.
- Added a documentation index and public surface coverage matrix mapping swarm
  methods, responses, attributes, testing helpers, durable manager operations,
  Artisan commands, and extension points to their canonical guides.
- Expanded durable waits/signals, retries/progress, child swarms, and webhooks
  into full user guides with prerequisites, copy-paste examples, edge cases,
  testing notes, and related documentation.
- Added focused examples for stream broadcasting, durable waits/signals, durable
  retries/progress/child swarms, and durable webhook ingress.
- Added a flagship human-in-the-loop support review example showing durable
  waits, app-owned broadcast notifications, review endpoints, signal handling,
  and frontend pseudocode.
- Added or expanded guides for upgrading, durable runtime architecture,
  workflow operations, durable webhooks, observability logging/tracing,
  observability correlation, audit evidence, operational query contracts,
  persistence/privacy, migration/FK safety, and production maintenance.
- Clarified Composer stability expectations for `laravel/ai`, release
  discipline, README badges, Packagist guidance, and human contributor entry
  points.
- Removed internal package-review notes from distributed documentation; release
  docs now include only user-facing and contributor-facing package guidance.

### Security

- Hardened durable webhook token auth so blank configured tokens cannot match
  blank bearer tokens.
- `auth.driver=none` fails during route registration outside `local` and
  `testing`, unsupported webhook auth drivers fail during route registration,
  and callback auth now fails closed for blank, malformed, missing, or
  non-callable callback configuration.

## v0.1.10 - 2026-05-01

### Documentation

- Documented dependency and upgrade expectations for PHP, Laravel, and
  `laravel/ai` in `README.md` and `AGENTS.md` (integration testing after Composer
  bumps; changelog covers Swarm-owned changes only).
- Added `CONTRIBUTING.md` with contributor workflow, maintainer ownership,
  review expectations, and release discipline guidance.

### Added

- **Coordinated hierarchical parallel for `queue()`:** optional
  `swarm.queue.hierarchical_parallel.coordination` (`in_process` default,
  `multi_worker` opt-in) and `#[QueuedHierarchicalParallelCoordination]` for
  per-swarm overrides. Multi-worker mode reuses durable branch storage, leases,
  join, `AdvanceDurableBranch`, `ResumeQueuedHierarchicalSwarm`, cancel, and
  `swarm:recover`; public lifecycle metadata stays `execution_mode: queue`.
- Migration adding `coordination_profile` to `swarm_durable_runs` (indexed;
  default `step_durable`) plus `CoordinationProfile` enum.
- `ClaimsQueuedRunExecution::acquireQueuedRunContinuationLease()` for resuming
  the primary history lease after a parallel join.

### Changed

- `DatabaseDurableRunStore::recoverable()` excludes
  `queue_hierarchical_parallel` coordination rows so recovery does not dispatch
  `AdvanceDurableSwarm` for queue-only coordination parents.

## v0.1.9 - 2026-04-29

### Added

- Added Laravel AI-style swarm stream broadcast helpers:
  `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()`. These helpers are
  sequential-only and broadcast typed swarm stream events rather than lifecycle
  events for every topology.
- Documented and tested broadcast transport failures, including pre-terminal
  failures that fail run history and terminal delivery failures that leave
  completed swarm history intact while failing the helper or queued job.

## v0.1.8 - 2026-04-29

### Breaking / Contract Changes

- Added `StreamEventStore::forget(string $runId)` so replay stores can
  invalidate already-written events when replay persistence is disabled after a
  partial write failure. Custom `StreamEventStore` implementations must add this
  method.

### Added

- Added `docs/streaming.md` as the canonical `stream()` guide and cross-linked it
  from the README, persistence, testing, structured input, examples, and agent
  context.
- Added `swarm.streaming.replay.failure_policy` /
  `SWARM_STREAM_REPLAY_FAILURE_POLICY` with `fail` as the default and
  `continue` as an opt-in mode for continuing live streams when replay
  persistence fails.

### Fixed / Hardened

- Hardened persisted stream replay failure handling so `fail` marks the live run
  failed coherently, while `continue` discards partial replay events before
  continuing without persisted replay for that response.

## v0.1.7 - 2026-04-28

### Added

- Added a composite replay lookup index on `swarm_stream_events(run_id, id)`
  to keep replay scans ordered and efficient as event volumes grow
- Added typed streamed event coverage for final-agent non-text upstream events:
  `swarm_text_end`, `swarm_reasoning_delta`, `swarm_reasoning_end`,
  `swarm_tool_call`, and `swarm_tool_result`
- Added a dedicated `SequentialStreamRunner` orchestration path to separate
  sequential streaming flow from non-stream execution paths

### Changed

- Updated persistence/history documentation to explicitly state that
  `swarm.limits.max_output_bytes` applies to persisted replay event payloads in
  addition to step/history and lifecycle event output surfaces
- Documented streaming overflow `fail` behavior so operators know earlier
  deltas can be emitted before terminal events are omitted after overflow
- Updated streaming docs and examples with the expanded event schema and
  provenance-first replay behavior for upstream final-agent streamed events

### Fixed / Hardened

- Removed duplicate streamed step-end output limit application by deriving
  `SwarmStepEnd` output from the existing recorded step output path
- Hardened streaming tests with resilient agent-based assertions and added
  coverage for replay payload limits and overflow fail replay behavior
- Preserved upstream event IDs and timestamps for typed final-agent streamed
  events in replay payloads
- Hardened streamed reasoning/tool payload redaction by preserving keys while
  replacing values with `[redacted]` when output capture is disabled

## v0.1.6 - 2026-04-26

### Added

- Added database-backed durable operational state for application-owned
  inspectors, dashboards, operators, and future connectors
- Added durable runtime columns for execution mode, route start/current node,
  completed node IDs, node states, failure metadata, attempts, lease
  timestamps, recovery counters, operator control timestamps, timeout state,
  queue routing, and terminal timing
- Added persisted hierarchical route plan and route cursor visibility for
  active durable runs so inspectors can report route progress while recovery
  still has the raw data it needs
- Added durable runtime node-state tracking for coordinator, sequential step,
  worker, completed, failed, paused, cancelled, and leased states
- Added durable runtime inspection coverage for active and terminal durable
  runs through the existing durable store surface

### Changed

- Documented durable runtime inspection as neutral durable operational state for
  application-owned dashboards, operators, and future connectors
- Added the `DurableRunStore::find()` documentation path for durable runtime
  inspection while keeping `SwarmHistory` as the stable history surface
- Changed terminal hierarchical durable runs to retain an inspection-safe route
  projection instead of the raw active route plan
- Clarified that cache-backed persistence does not provide the durable runtime
  inspection surface
- Updated durable execution, persistence/history, and hierarchical routing docs
  to describe active route-plan sensitivity and terminal route projection
  behavior

### Fixed / Hardened

- Redacted durable runtime failure metadata through the existing capture policy
  before persisting run failure and node failure state
- Removed the one-off `RecordsDurableRunFailureMetadata` capability contract and
  folded redacted failure metadata into the durable store contract
- Hardened terminal durable completion, failure, and cancellation so route-plan
  projection replacement and durable node-output deletion happen atomically
- Hardened terminal hierarchical durable records so worker prompts, finish
  literal output, and node metadata are not retained after completion, failure,
  or cancellation
- Deleted intermediate durable node-output rows at terminal states while
  retaining sanitized route/cursor/node inspection state
- Made durable recovery scans pure queries and moved recovery bookkeeping to an
  explicit `markRecoveryDispatched()` call after redispatch succeeds
- Guarded recovery bookkeeping so stale recovery results cannot mutate terminal
  durable runs
- Preserved existing history inspection APIs while adding the durable runtime
  inspection surface additively

## v0.1.5 - 2026-04-26

### Added

- Added durable hierarchical execution through `dispatchDurable()` using a
  persisted route plan and route cursor
- Added durable hierarchical node-output persistence with one row per node
  output instead of a growing runtime JSON blob
- Added targeted durable hierarchical node-output reads for `with_outputs` and
  finish-node `output_from` dependencies

### Changed

- Extended durable execution support from sequential swarms to sequential and
  hierarchical swarms
- Hierarchical durable parallel groups execute branch workers sequentially in
  declaration order for v1 while keeping the same parallel-safe validation
  rules as synchronous hierarchical execution
- Split durable checkpoint persistence into an internal recorder so the durable
  manager owns orchestration flow while checkpoint, terminal, pause, resume, and
  artifact persistence stay transactionally grouped
- Added an upgrade note for the `swarm_contexts.input` `longText` migration:
  large production tables should run package migrations during a maintenance
  window, and rolling this column back to `text` can fail once long prompts have
  been stored

### Fixed / Hardened

- Hardened durable hierarchical checkpoints so route cursor advancement,
  context persistence, node-output persistence, artifact persistence, history
  sync, and durable `next_step_index` advancement commit atomically
- Hardened terminal durable completion, failure, and cancellation so runtime
  route plans, route cursors, and durable node-output rows are cleared together
  with terminal history/context persistence
- Hardened durable pause and resume so runtime state and history cannot drift if
  history sync fails
- Preserved accumulated usage across durable hierarchical jobs before
  checkpointing the next step
- Redacted durable hierarchical cursor data from captured terminal history and
  context when output capture is disabled
- Hydrated persisted hierarchical route plans defensively with package-level
  `SwarmException` messages when runtime state is malformed, including invalid
  control references and output dependencies

### Documentation

- Updated durable execution, hierarchical routing, structured input, maintenance,
  README, and example documentation for durable hierarchical support
- Documented that durable fan-out/fan-in remains out of scope for this release

## v0.1.4

### Breaking / Contract Changes

- Laravel 13 is now enforced through explicit `illuminate/*:^13.0` component constraints
- Structured task arrays, explicit context data, context metadata, and persisted artifact payloads must now be plain data only: strings, integers, floats, booleans, null, and arrays containing only those values
- Objects, enums, closures, resources, `JsonSerializable`, and `Stringable` values are rejected at queue and persistence boundaries instead of being serialized or cast
- Invalid global or per-store persistence drivers now fail clearly instead of silently falling back to cache
- Sequential, parallel, hierarchical, and streamed swarms with no agents now throw a `SwarmException` instead of returning successful empty or unchanged responses
- `#[Timeout]`, `#[MaxAgentSteps]`, and `SWARM_DURABLE_STEP_TIMEOUT` values must be positive integers
- Parallel swarm agents must be container-resolvable by class; parallel execution resolves agents inside Laravel Concurrency workers instead of capturing configured agent instances
- Hierarchical swarms now require unique worker classes after the coordinator
- `queue()` now validates topology, timeout, max steps, empty agents, parallel resolvability, and hierarchical worker uniqueness before dispatching

### Added

- Added durable sequential execution with `dispatchDurable()`, `DurableSwarmResponse`, durable runtime storage, one-step-per-job advancement, and recovery-safe checkpointing
- Added durable pause, resume, cancel, and recover commands, plus `DurableSwarmManager` controls for application UIs
- Added coordinator-driven hierarchical DAG routing with validated worker, parallel, and finish nodes
- Added capture privacy controls for inputs and outputs using `SWARM_CAPTURE_INPUTS`, `SWARM_CAPTURE_OUTPUTS`, and `[redacted]` event/persistence values
- Added durable runtime table migration and configuration for durable queue routing, step timeout, and recovery grace
- Added a migration changing `swarm_contexts.input` to `longText`
- Added Larastan/PHPStan configuration and `larastan/larastan` as a required development quality gate
- Added GitHub Actions CI for Pest, Larastan/PHPStan, and Pint
- Added release-ready examples for sequential, queued, streamed, tested, parallel, hierarchical, durable, privacy-sensitive, run-inspector, and operations-dashboard swarm patterns

### Changed

- Replaced the full `laravel/framework` runtime Composer constraint with explicit Laravel 13 Illuminate component constraints
- Reworked hierarchical execution from placeholder routing into a validated route-plan runtime with explicit coordinator schema expectations
- Updated package migration publishing to use Laravel 13's migration publishing path while continuing to auto-load package migrations
- Updated repository packaging metadata with `.gitattributes`, a stronger `.gitignore`, Composer branch aliasing, and package-style lock-file hygiene
- Changed database context writes to use the same normalized `RunContext::toArray()` shape as cache-backed context persistence
- Changed database context persistence to use `updateOrInsert()` instead of an exists-then-insert flow
- Updated parallel execution to capture scalar task and class data only before resolving each agent in the concurrency worker
- Redacted terminal persisted context snapshots, failure messages, events, and automatic artifacts according to capture settings while keeping live agent handoff and returned responses unchanged
- Improved Pulse run and step metrics aggregation and documented how Pulse complements application-owned lifecycle dashboards

### Fixed / Hardened

- Hardened artifact persistence with strict payload normalization for both cache and database repositories, including clear failures for non-array metadata and invalid nested metadata values
- Hardened prune behavior for missing package tables, terminal `cancelled` rows, durable runtime rows, and active-run preservation
- Hardened durable lease ownership, recovery, duplicate step handling, startup rollback, and invalid persisted step timeout handling
- Hardened queued execution so invalid swarm definitions fail before dispatch and duplicate database-backed deliveries do not corrupt terminal state
- Hardened capture behavior so disabled capture settings apply consistently to persisted inspection surfaces and failure events
- Hardened structured input reconstruction so queued payloads remain plain data when workers rebuild `RunContext`
- Hardened parallel and hierarchical parallel execution so missing concurrency results throw instead of fabricating successful empty outputs
- Expanded test coverage across durable execution, hierarchical routing, privacy capture, persistence boundaries, pruning, queue fail-fast behavior, Pulse metrics, and artifact normalization

### Documentation

- Added durable execution, hierarchical routing, maintenance, persistence/history, structured input, testing, and Pulse documentation updates for the new runtime contracts
- Added explicit privacy and data-capture documentation covering raw prompt/output storage, `[redacted]`, automatic artifacts, failure messages, terminal context snapshots, and metadata caveats
- Added queue and durable worker guidance for Laravel queue timeouts, `retry_after`, and provider-call duration
- Added application run-inspector and operations-dashboard examples based on real Laravel usage patterns
- Updated README guidance around Laravel Swarm's positioning, queue semantics, durable execution, streaming, persistence, events, examples, and release contracts

## v0.1.3

- Hardened lightweight queued swarm execution with lease-based retry recovery so duplicate deliveries do not strand or replay active database-backed runs
- Removed pre-v1 queued `then()` / `catch()` callbacks and tightened queued lifecycle behavior around lease-safe failure handling and event integrity
- Added prune-based retention hardening across database-backed history, context, and artifact stores, including active-run protection and safe handling of custom configured table names
- Improved database-backed queued install safety with clearer lease-column validation errors for partially migrated history tables
- Expanded queueing and persistence coverage around retries, pruning, lease loss, custom table names, and schema validation failure modes
- Updated the README and maintenance/persistence docs to clarify the lightweight queue contract, event-listener guidance, and database retention behavior

## v0.1.2

- Added durable database-backed persistence for swarm context, artifacts, and run history
- Added auto-loaded package migrations, optional migration publishing, and configurable persistence driver resolution with per-store overrides
- Replaced the hierarchical placeholder with coordinator-driven `route()` execution and explicit routed-agent validation errors
- Hardened queued swarm behavior around container resolution, callback fluency, queue-safe workflow definitions, and pending-dispatch chaining
- Clarified and strengthened sequential streaming behavior, including failure handling, known usage preservation, and completion-state fidelity
- Improved lifecycle observability with populated `SwarmStarted` execution modes and normalized completion metadata across run paths
- Expanded feature and unit coverage for queueing, streaming, persistence, lifecycle events, hierarchical routing, and fake interception
- Rewrote and expanded the README around workflow positioning, configuration, queue semantics, testing, and lifecycle behavior

## v0.1.1

- Rewrote the package documentation around the Laravel-native public API with explicit `run()`, `queue()`, and `stream()` usage
- Added the initial `CHANGELOG.md` and tightened extension-point contract comments and stub comments
- Removed the hardcoded package version from `composer.json` so Git tags define releases cleanly
- Fixed sequential swarm streaming after the execution-policy cleanup by removing a stale execution-mode reference
- Preserved run context handling for queued swarm jobs after the public API simplification

## v0.1.0

- Added `make:swarm` scaffolding for swarm classes in `App\Ai\Swarms`
- Added sequential, parallel, and hierarchical swarm runners
- Added explicit public execution verbs with `run()`, `queue()`, and `stream()`
- Added queue support for background swarm execution
- Added swarm-level streaming for sequential topologies
- Added testing fakes and assertion helpers for swarm runs and queued dispatches
- Added structured swarm responses, lifecycle events, and persistence hooks for context, artifacts, and run history
