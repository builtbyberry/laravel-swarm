# Phase 12: Durable Job Retry Configuration

## Finding

Medium queueing finding: durable advance jobs inherit queue worker defaults for
tries, timeout, and backoff.

## Current Evidence

- `AdvanceDurableSwarm` and `AdvanceDurableBranch` only carry IDs and delegate
  to `DurableSwarmManager`.
- Config already includes `swarm.durable.step_timeout`.
- Maintenance docs tell operators to align worker timeout and retry settings.

## Decision

Add explicit job methods for tries, timeout, and backoff derived from durable
config while preserving queue-specific routing behavior.

## Implementation Notes

- Add config keys for durable job tries/backoff if existing config is
  insufficient.
- Implement Laravel job methods `tries()`, `timeout()`, and `backoff()` on both
  durable advance jobs.
- Make timeout exceed `swarm.durable.step_timeout` by a documented margin.
- Keep values configurable because provider call duration varies.

## Tests

- Unit or feature tests asserting job retry/timeout/backoff methods return
  configured values.
- Durable dispatch tests to ensure jobs still route correctly.
- Run `composer test` and `composer lint`.

## Docs/Release Notes

- `docs/maintenance.md`: explain worker timeout, retry_after, step timeout, and
  durable job timeout relationship.
- Config docs/README: new env keys if added.
- CHANGELOG: queue reliability hardening.

## Acceptance Criteria

- Durable advance jobs no longer silently inherit framework defaults.
- Operators can tune tries, timeout, and backoff through config.
- Existing durable execution behavior remains compatible.

## Follow-up Risk

No timeout can hard-cancel an in-flight provider call without provider support.
