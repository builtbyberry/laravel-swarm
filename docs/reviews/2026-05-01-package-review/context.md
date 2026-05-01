# 2026-05-01 Package Review Remediation Context

This review plan tracks remediation work for the 2026-05-01 expert panel review
of `builtbyberry/laravel-swarm`.

The goal is not to accept every finding at face value. Each phase should verify
the claim against the repository, classify the practical risk, and then make the
smallest Laravel-native change that improves adoption readiness without
destabilizing the package.

## Review Summary

- Overall score: 8.0/10.
- Main strengths: Laravel-native API shape, strong documentation, broad feature
  and unit coverage, clean persistence abstractions, queue-safe durable design.
- Main risks: pre-stable upstream dependency, solo-maintainer process risk,
  durable runtime complexity, compliance retention expectations, production
  observability, and a few hardening gaps.

## Severity Normalization

- Critical findings are adoption or release-readiness risks unless they point to
  exploitable code behavior. Treat them as release gates for public stability,
  not necessarily patch-level defects.
- High findings should be resolved or explicitly deferred with rationale before
  a stable release.
- Medium findings should be handled when they improve operator confidence,
  reduce maintenance risk, or unlock later fixes.
- Low findings are polish or ecosystem confidence work. Complete them after
  security, compliance, queue reliability, and upgrade documentation.

## Verified Repository Facts

- `src/Support/SwarmWebhooks.php` validates blank webhook token config during
  route registration, but `authenticateToken()` still casts config directly to
  string before `hash_equals()`.
- `src/SwarmServiceProvider.php` currently calls `loadMigrationsFrom()` without
  checking the configured persistence driver.
- `src/Jobs/AdvanceDurableSwarm.php` and
  `src/Jobs/AdvanceDurableBranch.php` do not define explicit job retry,
  backoff, or timeout behavior.
- `phpstan.neon` currently runs at level 5.
- `.github/workflows/tests.yml` runs one CI job for PHP 8.5 and Laravel 13 with
  coverage disabled.
- `config/swarm.php` defaults `SWARM_CAPTURE_INPUTS`,
  `SWARM_CAPTURE_OUTPUTS`, `SWARM_CAPTURE_ARTIFACTS`, and
  `SWARM_CAPTURE_ACTIVE_CONTEXT` to enabled.
- Tests set `concurrency.default` to `sync` in `tests/TestCase.php`.
- `src/Runners/DurableSwarmManager.php` is large and owns multiple durable
  runtime responsibilities. Refactoring should be incremental and covered by
  existing durable tests.

## Sequencing

1. Security and queue reliability: phases 3, 10, and 12.
2. Compliance and operational retention: phases 4, 11, 14, and 24.
3. Upgrade and release readiness: phases 1, 2, 9, 17, 20, and 21.
4. Quality gates: phases 7, 15, 18, and 22.
5. Persistence scalability and migration behavior: phases 5, 6, 13, and 16.
6. Architecture cleanup and contributor clarity: phases 8, 19, and 23.

## Shared Testing Commands

From the package root:

```bash
composer test
composer lint
composer analyse
```

When running PHPStan directly, use:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

For code phases, run targeted Pest tests first, then the full relevant command.
For docs-only phases, verify all linked files exist and README, CHANGELOG, and
UPGRADING references remain consistent.

## Release Note Rule

Every phase that changes behavior, defaults, configuration, migrations,
commands, public docs, or package process must include a CHANGELOG entry. If the
phase introduces upgrade guidance, update `UPGRADING.md` in the same change.
