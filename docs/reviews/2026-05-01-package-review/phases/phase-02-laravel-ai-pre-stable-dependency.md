# Phase 02: `laravel/ai` Pre-Stable Dependency

## Finding

Critical adoption risk: the package depends on `laravel/ai ^0.6`, which is
pre-stable and can shift contracts before a 1.0 release.

## Current Evidence

- `composer.json` requires `"laravel/ai": "^0.6"`.
- README and AGENTS.md already warn that Laravel and Laravel AI bumps are
  integration-test events.
- `minimum-stability` is `dev` with `prefer-stable`.

## Decision

Keep the Composer range unless upstream has a stable compatible release. Improve
compatibility and upgrade documentation so consumers know how to pin and test.

## Implementation Notes

- Add `UPGRADING.md` guidance for Laravel AI bumps.
- Add README installation notes explaining `minimum-stability`, pinning, and
  smoke-test expectations.
- Add a release checklist item requiring `composer update laravel/ai` smoke
  tests before widening dependency ranges.
- Do not vendor, wrap, or fork Laravel AI contracts in this phase.

## Tests

- Docs-only unless Composer constraints change.
- If Composer changes are made, run `composer update --lock`, `composer test`,
  and `composer analyse`.

## Docs/Release Notes

- README: dependency stability and pinning guidance.
- `UPGRADING.md`: Laravel AI upgrade procedure.
- CHANGELOG: documentation update.

## Acceptance Criteria

- Consumers can see the upstream risk before installation.
- The package remains honest about the integration burden.
- No unsupported stable constraint is claimed.

## Follow-up Risk

The risk remains until Laravel AI reaches stable contracts or this package pins a
more conservative compatibility window.
