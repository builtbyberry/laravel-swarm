# Phase 21: Consuming App `minimum-stability: dev`

## Finding

Low package-maintainer finding: consumers may need `minimum-stability: dev`
because of the pre-stable Laravel AI dependency.

## Current Evidence

- This package has `"minimum-stability": "dev"` and `"prefer-stable": true`.
- `laravel/ai` is required as `^0.6`.
- README already discusses upstream dependency risk.

## Decision

Document why the setting may be needed and how to limit its blast radius.
Remove the need only when upstream dependencies allow it.

## Implementation Notes

- Add README install guidance showing `prefer-stable: true`.
- Recommend explicit version constraints instead of broad dev dependencies.
- Add `UPGRADING.md` note to revisit this when Laravel AI reaches stable.
- Do not remove `minimum-stability` from this package until dependency
  resolution supports it.

## Tests

- Docs-only unless Composer metadata changes.
- If metadata changes, run `composer validate` and dependency install tests.

## Docs/Release Notes

- README install section.
- `UPGRADING.md`: dependency stability note.
- CHANGELOG: docs clarification.

## Acceptance Criteria

- Consumers understand why Composer may ask for dev stability.
- Guidance reduces accidental adoption of unrelated dev packages.
- Composer metadata remains accurate.

## Follow-up Risk

The setting remains an adoption friction point until upstream stability changes.
