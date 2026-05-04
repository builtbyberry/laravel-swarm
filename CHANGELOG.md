# Changelog

## Unreleased

### Added

- GitHub Actions test workflow enables PCOV and runs `composer test:coverage`
  so CI fails when line coverage for `src/` drops below the configured minimum
  (see `composer.json` script `test:coverage`).
- Migration `2026_05_02_000001_split_swarm_durable_runs_state_into_side_tables`
  adds `swarm_durable_node_states` and `swarm_durable_run_state`, moves
  `route_plan`, `node_states`, `failure`, and `retry_policy` off `swarm_durable_runs`,
  and copies existing data before dropping the old columns. Config keys
  `swarm.tables.durable_node_states` and `swarm.tables.durable_run_state` (with
  `SWARM_DURABLE_NODE_STATES_TABLE` / `SWARM_DURABLE_RUN_STATE_TABLE`) name these
  tables.
- `swarm:prune --dry-run` reports how many rows would be deleted in each table
  category without performing deletes.
- `swarm.retention.prevent_prune` (`SWARM_PREVENT_PRUNE`) to disable destructive
  pruning while still allowing `--dry-run`.

### Documentation

- Added [Durable Runtime Architecture](docs/durable-runtime-architecture.md)
  describing the `DurableSwarmManager` facade, `Runners/Durable` collaborators,
  factory-built graph, queue jobs, container lifetime rules, testing patterns, and
  cross-links from durable topic guides, persistence, testing, and README.
- Documented durable operational query surfaces at scale: typed columns and
  satellite tables (including `swarm_durable_run_state` and
  `swarm_durable_node_states`) vs JSON checkpoint storage (`docs/durable-execution.md`),
  plus durable storage growth, archival, and partitioning posture in
  `docs/maintenance.md`.
- Clarified that swarm persistence is operational (mutable / pruneable), not an
  immutable compliance archive; documented dry-run, prevent-prune, and audit-sink
  guidance in `docs/maintenance.md`, `docs/persistence-and-history.md`, and the
  README production checklist.

### Security

- Hardened durable webhook `token` auth when `swarm.durable.webhooks.auth.token`
  is blank during a request: configuration is validated before comparison so an
  empty bearer token cannot match a blank secret. Missing or incorrect bearer
  tokens still return HTTP 401.
- Added feature tests proving `auth.driver=none` throws during route registration
  in non-local/non-testing environments and that unauthenticated requests succeed
  only in `local`/`testing`. Expanded `docs/durable-webhooks.md` with an
  explicit warning that `none` must never be used in production or staging.

## v0.1.11 - 2026-05-01

### Documentation

- Added [UPGRADING.md](UPGRADING.md) with Laravel AI upgrade flow, application-level
  pinning, and Composer stability notes.
- Expanded [README.md](README.md) installation guidance on pre-stable `laravel/ai`,
  `minimum-stability` / `prefer-stable`, and links to `UPGRADING.md`.
- Clarified [CONTRIBUTING.md](CONTRIBUTING.md) release discipline: smoke-test
  `laravel/ai` updates before widening its semver range in package `composer.json`.

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
