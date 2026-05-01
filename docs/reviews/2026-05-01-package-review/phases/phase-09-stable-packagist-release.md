# Phase 09: Stable Packagist Release

## Finding

Medium adoption finding: no stable public release is visible yet, which limits
external confidence.

## Current Evidence

- `composer.json` has branch alias `dev-main` as `0.1.x-dev`.
- Package metadata is present for Packagist.
- `laravel/ai` pre-stable dependency affects the path to a stable release.

## Decision

Create a release checklist and ship a stable tag only when compatibility,
upgrade docs, and release notes are ready.

## Implementation Notes

- Add a release checklist to `CONTRIBUTING.md` or `docs/maintenance.md`.
- Include required checks: tests, lint, PHPStan, docs links, changelog, upgrade
  notes, Packagist metadata, and Laravel AI compatibility smoke tests.
- Update README install instructions after a stable tag exists.
- Do not claim stable adoption before the tag is actually published.

## Tests

- Docs-only before tagging.
- Before release: run `composer test`, `composer lint`, and `composer analyse`.

## Docs/Release Notes

- README: stable install instructions after tag.
- CHANGELOG: release preparation.
- `UPGRADING.md`: any breaking guidance for the release.

## Acceptance Criteria

- Maintainers have a repeatable release checklist.
- Consumers can distinguish dev install instructions from stable instructions.
- Packagist metadata is complete before badges are added.

## Follow-up Risk

Stable release confidence still depends on upstream Laravel AI maturity.
