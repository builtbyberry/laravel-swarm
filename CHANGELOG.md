# Changelog

## Unreleased

- Added built-in database persistence for swarm context, artifacts, and run history
- Added automatically loaded package migrations, with optional migration publishing for customization
- Added a global `swarm.persistence.driver` config default with per-store persistence driver overrides
- Documented and tested sequential streaming failure behavior, including failed run history persistence and re-thrown underlying exceptions
- Replaced the hierarchical sequential placeholder with coordinator-driven routing via `route()`
- Added explicit hierarchical errors for routed agent classes that are not returned from `agents()`

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
