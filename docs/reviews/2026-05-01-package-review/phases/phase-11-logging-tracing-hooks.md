# Phase 11: Logging and Tracing Hooks

## Finding

Medium infrastructure finding: the package emits events but has no native
logging or OpenTelemetry span integration.

## Current Evidence

- Lifecycle events exist for started, step started/completed, completed, failed,
  waiting, paused, resumed, cancelled, progress, and child run behavior.
- Pulse integration provides aggregate observability.
- There is no hard dependency on OpenTelemetry.

## Decision

Document event-to-log integration first. Add an optional callback/span hook only
if it can remain dependency-free and Laravel-native.

## Implementation Notes

- Add docs showing how to listen to Swarm events and attach `run_id`,
  `execution_mode`, and queue/job context to logs.
- Consider config-driven callbacks for trace start/end without adding an OTEL
  package dependency.
- Keep default behavior silent to avoid surprising application logs.

## Tests

- Docs-only for listener examples.
- If callbacks are added, unit test invocation and failure isolation.
- Run `composer test` if code changes are included.

## Docs/Release Notes

- Add or expand observability docs.
- README production checklist: correlate events by `run_id`.
- CHANGELOG for docs or callback support.

## Acceptance Criteria

- Operators have a documented path to log and trace swarms across jobs.
- No required tracing dependency is introduced.
- Callback failures cannot break swarm execution unless explicitly configured.

## Follow-up Risk

First-class OpenTelemetry support may belong in an optional integration package.
