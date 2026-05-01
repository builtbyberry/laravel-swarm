# Phase 20: Community Indicators

## Finding

Low developer-advocacy finding: README lacks community signal indicators such
as Packagist version/download badges.

## Current Evidence

- README currently focuses on product explanation and usage.
- Composer package name is `builtbyberry/laravel-swarm`.
- Stable release metadata may not yet be available.

## Decision

Add badges only when the backing public metadata exists and is accurate.

## Implementation Notes

- After Packagist publication/tagging, add Packagist version, downloads, tests,
  license, and PHP/Laravel compatibility badges.
- Avoid adding empty or misleading social proof.
- Keep badges compact so README remains documentation-first.

## Tests

- Docs-only: verify badge URLs render and link correctly.

## Docs/Release Notes

- README badge block.
- CHANGELOG: README metadata update.

## Acceptance Criteria

- Badges reflect real package status.
- README remains readable.
- No badge points to unavailable package metadata.

## Follow-up Risk

Badges can become stale if workflows or package names change.
