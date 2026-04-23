# Changelog

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
