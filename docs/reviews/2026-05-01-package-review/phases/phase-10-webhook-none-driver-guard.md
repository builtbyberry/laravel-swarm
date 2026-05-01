# Phase 10: Webhook `none` Driver Guard

## Finding

Medium security finding: unauthenticated webhook mode is blocked at runtime, but
misconfiguration should fail as early and visibly as possible.

## Current Evidence

- `SwarmWebhooks::assertAuthConfiguration()` rejects `none` outside local and
  testing during route registration.
- `authenticate()` also rejects `none` outside local/testing.
- There is no broader config validation command.

## Decision

Keep the runtime guard and add explicit tests/documentation. Consider surfacing
the same validation in a future health command.

## Implementation Notes

- Add tests proving `none` fails route registration outside local/testing.
- Add tests proving local/testing can use `none`.
- Update durable webhook docs with a production warning.
- Reuse the same validation in any future `swarm:health` command.

## Tests

- Durable webhook feature tests for local, testing, and production-like
  environment behavior.
- Run `composer test` and `composer lint`.

## Docs/Release Notes

- `docs/durable-webhooks.md`: `none` is local/testing only.
- CHANGELOG: validation/test hardening.

## Acceptance Criteria

- Misconfigured unauthenticated production webhooks fail before routes are
  usable.
- Tests cover the intended environments.
- Docs make the risk visible.

## Follow-up Risk

Laravel does not have a universal package config schema validation mechanism;
health checks are the practical next step.
