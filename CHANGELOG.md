# Changelog

## v0.1.4

### Breaking / Contract Changes

- Laravel 13 is now enforced through `laravel/framework:^13.0`
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
- Prevented duplicate queued deliveries from replaying deprecated `then()` callbacks and tightened queued lifecycle behavior around lease-safe failure handling and event integrity
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
