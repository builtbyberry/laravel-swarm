# Phase 17: Formal Upgrade Documentation

## Finding

Medium engineering-management finding: there is no formal `UPGRADING.md` for
breaking changes and operator migration guidance.

## Current Evidence

- CHANGELOG is detailed and versioned.
- No `UPGRADING.md` exists.
- The package has moved quickly through early versions.

## Decision

Add `UPGRADING.md` as the canonical place for action-oriented upgrade steps,
while keeping CHANGELOG as the complete release history.

## Implementation Notes

- Add `UPGRADING.md` with current early-version guidance.
- Include dependency upgrade policy, migrations, config changes, breaking
  contracts, and recommended smoke tests.
- Link `UPGRADING.md` from README and CHANGELOG.
- For future releases, add upgrade entries only when user action is required.

## Tests

- Docs-only: verify links.

## Docs/Release Notes

- README: upgrade guide link.
- CHANGELOG: mention new guide.
- `UPGRADING.md`: initial content.

## Acceptance Criteria

- Operators can find upgrade steps without reading every changelog entry.
- Dependency bump testing expectations are repeated clearly.
- Future breaking changes have an obvious home.

## Follow-up Risk

The guide must stay maintained; stale upgrade docs are worse than none.
