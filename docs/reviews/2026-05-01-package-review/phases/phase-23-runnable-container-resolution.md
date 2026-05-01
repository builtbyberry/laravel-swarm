# Phase 23: `Runnable` Container Resolution

## Finding

Low code-review finding: `Runnable` uses `Container::getInstance()`, coupling
static helpers to the global container.

## Current Evidence

- `src/Concerns/Runnable.php` mirrors Laravel-style static construction and
  fake/assertion helpers.
- Static container access supports `Swarm::fake()`-style ergonomics.
- Changing this carelessly could break public API expectations.

## Decision

Preserve Laravel-native ergonomics. Either document the rationale or introduce a
small overridable resolver hook with no public behavior change.

## Implementation Notes

- Review Laravel AI `Promptable` conventions before changing the trait.
- If adding a hook, keep `Container::getInstance()` as the default resolver.
- Ensure fakes and assertion helpers still bind/resolve through the container.
- Avoid injecting services into swarm classes only to satisfy purity concerns.

## Tests

- Existing fake/assertion tests.
- Runnable construction tests for positional/named arguments.
- Run `composer test` and `composer analyse`.

## Docs/Release Notes

- CHANGELOG only if code changes.
- No user-facing docs needed unless a new extension hook is public.

## Acceptance Criteria

- Public `make()`, `fake()`, `prompt()`, `queue()`, `stream()`, and assertion
  helpers behave exactly as before.
- Tests prove static helper ergonomics remain intact.
- Any new resolver hook is optional and documented if public.

## Follow-up Risk

Global container access is a Laravel convention in some static APIs; removing it
may reduce usability more than it improves isolation.
