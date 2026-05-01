# Phase 03: Token Auth Blank Config

## Finding

High security finding: `authenticateToken()` casts a potentially null token
config value to string before timing-safe comparison.

## Current Evidence

- `SwarmWebhooks::assertAuthConfiguration()` rejects blank token config during
  route registration.
- `SwarmWebhooks::authenticateToken()` still does:
  `$token = (string) $config->get(...)` before `hash_equals()`.
- Defense should live at the comparison boundary too, not only route setup.

## Decision

Harden `authenticateToken()` with an explicit blank-token guard before reading
or comparing the bearer token.

## Implementation Notes

- In `src/Support/SwarmWebhooks.php`, reject blank
  `swarm.durable.webhooks.auth.token` with `SwarmException`.
- Preserve existing behavior for missing or wrong bearer tokens: `abort(401)`.
- Add feature tests for blank token config, missing bearer token, wrong bearer
  token, and valid token.
- Keep `hash_equals()` for the final comparison.

## Tests

- Run the targeted durable webhook tests.
- Run `composer test`.
- Run `composer lint`.

## Docs/Release Notes

- CHANGELOG: hardened token webhook auth configuration validation.
- Durable webhook docs only need updating if user-facing behavior changes.

## Acceptance Criteria

- Blank token config cannot reach `hash_equals()`.
- Valid token auth still succeeds.
- Missing/wrong bearer tokens still return unauthorized responses.

## Follow-up Risk

None expected after code and tests land.
