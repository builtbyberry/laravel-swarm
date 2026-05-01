# Phase 19: Agent-Facing Root Files

## Finding

Low documentation finding: `AGENTS.md` and `CLAUDE.md` are unconventional root
files and may confuse human contributors.

## Current Evidence

- Both files exist in the repository root.
- They are useful for AI coding agents and should not be removed casually.
- There is no `CONTRIBUTING.md` that explains repo conventions to humans.

## Decision

Keep the files, but make their audience explicit and add human contributor
orientation elsewhere.

## Implementation Notes

- Add a short note at the top of `AGENTS.md` and `CLAUDE.md` that they are
  agent-facing operational context.
- Add `CONTRIBUTING.md` that points humans to README, docs, CHANGELOG, and
  tests.
- Do not link agent files as primary user documentation.

## Tests

- Docs-only.

## Docs/Release Notes

- CHANGELOG: contributor docs clarification.

## Acceptance Criteria

- Human contributors are not left guessing what agent files are.
- Agent workflows keep their root-level context files.
- User-facing docs remain README and `docs/`.

## Follow-up Risk

Some repositories may prefer moving agent files into tool-specific directories,
but root files are increasingly common for AI-assisted workflows.
