# Phase 01: Single-Maintainer Bus Factor

## Finding

Critical process risk: the package is currently solo-maintained, which increases
adoption risk for a durable execution dependency.

## Current Evidence

- `composer.json` lists one author.
- No `CONTRIBUTING.md` exists.
- README and CHANGELOG are strong, but they do not define maintainer workflow,
  succession expectations, or contribution handling.

## Decision

Address this as release-readiness documentation and governance. Do not simulate
community adoption in code.

## Implementation Notes

- Add `CONTRIBUTING.md` with local setup, test commands, PR expectations, issue
  quality, review expectations, and release discipline.
- Add a maintainer/ownership section covering response expectations, how a fork
  can be evaluated, and how new maintainers may be added.
- Link `CONTRIBUTING.md` from README.
- Add a concise CHANGELOG note under Documentation.

## Tests

- Docs-only: verify links from README resolve.
- No Pest or PHPStan run is required unless code changes are added.

## Docs/Release Notes

- README: add contributor link near support/project metadata.
- CHANGELOG: document contribution and maintainer policy addition.

## Acceptance Criteria

- Contributors can understand how to propose changes and what checks to run.
- Adopters can see an explicit project ownership and continuity posture.
- No package runtime behavior changes.

## Follow-up Risk

This reduces uncertainty but does not remove actual solo-maintainer risk.
