# Phase 24: Cache Store Readiness

## Finding

Low infrastructure finding: database stores expose readiness checks, but
cache-backed stores surface misconfiguration at runtime.

## Current Evidence

- `DurableRunStore`, database context/artifact/history stores, and database
  durable store have readiness checks.
- Cache stores are used for default persistence paths.
- There is no `swarm:health` command today.

## Decision

Add lightweight cache readiness checks and consider a `swarm:health` command
that validates the configured persistence stack.

## Implementation Notes

- Add `assertReady()` equivalents to cache-backed stores where contracts already
  require readiness.
- For stores without readiness contracts, decide whether to extend contracts or
  centralize checks in a command.
- A health command should verify configured context, artifact, history, stream
  replay, and durable stores without mutating durable runtime state.
- Use temporary probe keys with short TTLs if cache writes must be tested.

## Tests

- Unit/feature tests for cache readiness success and failure.
- Command tests if `swarm:health` is added.
- Run `composer test` and `composer lint`.

## Docs/Release Notes

- README/maintenance docs: readiness check command if added.
- CHANGELOG: infrastructure readiness support.

## Acceptance Criteria

- Cache misconfiguration can be detected before a production swarm run.
- Readiness checks do not leave long-lived cache keys.
- Database readiness behavior remains unchanged.

## Follow-up Risk

Some cache failures are network/runtime intermittent and cannot be fully proven
at deploy time.
