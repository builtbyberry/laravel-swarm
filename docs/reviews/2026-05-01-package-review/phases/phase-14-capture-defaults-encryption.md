# Phase 14: Capture Defaults and Encryption at Rest

## Finding

Medium regulatory finding: input/output capture defaults are enabled and the
package does not encrypt stored prompt/output content itself.

## Current Evidence

- `config/swarm.php` defaults capture flags to enabled.
- Docs already explain capture redaction and sensitive data caveats.
- The package relies on application/database infrastructure for encryption at
  rest.

## Decision

Document the PII posture clearly and provide conservative production settings.
Do not silently change defaults without a breaking-release decision.

## Implementation Notes

- Add README and persistence docs warning that captured prompts/outputs may
  contain sensitive data.
- Provide a conservative env example disabling inputs, outputs, artifacts, and
  active context capture.
- Recommend database encryption at rest and application-owned audit sinks for
  regulated data.
- Defer encrypted column support unless a clear Laravel-native design is chosen.

## Tests

- Docs-only unless defaults or encryption behavior changes.
- Existing redaction tests should remain the behavioral safety net.

## Docs/Release Notes

- README production checklist.
- `docs/persistence-and-history.md`: encryption and PII section.
- CHANGELOG: documentation hardening.

## Acceptance Criteria

- Operators understand what the package stores by default.
- Regulated deployments have a clear conservative configuration.
- No misleading claim of package-managed encryption is made.

## Follow-up Risk

Changing defaults may be appropriate for a future major/stable release.
