# Contributing

Thanks for taking the time to improve Laravel Swarm. This package is still
early, and changes should preserve the Laravel-native feel described in the
README: familiar public verbs, small surface area, explicit configuration, and
clear operational behavior.

## Local Setup

Install dependencies from the package root:

```bash
composer install
```

Laravel Swarm is a package, not a full Laravel application. Run package commands
from the repository root unless a test or reproduction explicitly uses a
Testbench application. If you need Artisan behavior, prefer a focused package
test over ad hoc manual setup.

## Required Checks

Before opening a pull request, run the checks that match your change:

```bash
composer test
composer lint
composer analyse
```

Continuous integration runs `composer test:coverage`, which requires a code
coverage driver (PCOV or Xdebug). Install PCOV for PHP locally when you want to
match CI or debug coverage failures; otherwise `composer test` remains the
default fast path without coverage.

If you run PHPStan directly, use the same command as CI:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

`composer lint` is the non-mutating Pint check. Use `composer format` only when
you intentionally want Pint to rewrite files.

## Issues

Good bug reports make the failing path reproducible. Include:

- PHP, Laravel, and `laravel/ai` versions.
- Laravel Swarm version or commit.
- Persistence driver and relevant store configuration.
- Execution mode: `prompt()`, `queue()`, `stream()`, broadcast helper, or
  `dispatchDurable()`.
- Topology: sequential, parallel, or hierarchical.
- Queue connection/driver and worker settings for queued or durable failures.
- The smallest task, swarm, agent, route plan, or test that reproduces the
  issue.
- The failing output, exception, log line, or assertion.

For security-sensitive reports, do not include secrets, prompts, customer data,
or full persisted payloads in a public issue.

## Pull Requests

Keep pull requests narrow. A good PR solves one behavior, documentation, test,
or maintenance concern without unrelated refactors.

Expected PR shape:

- Match existing Laravel and Laravel AI conventions before adding new patterns.
- Add or update tests for behavior changes.
- Update docs when public behavior, configuration, migrations, commands, or
  operational expectations change.
- Update `CHANGELOG.md` for user-visible changes.
- Add `UPGRADING.md` guidance when a release requires user action.
- Keep public APIs backward-compatible unless the PR is explicitly a breaking
  change.

Migration changes need extra care. Explain production impact, rollback behavior,
large-table locking risk, and whether custom `swarm.tables.*` names affect the
change.

## Review Expectations

Reviews prioritize correctness, operational safety, and framework fit over
style preference. Expect close review on:

- streaming event contracts and replay behavior;
- capture, redaction, and sensitive persistence surfaces;
- durable leases, recovery, retries, waits, signals, and child swarms;
- queue serialization and container-resolution boundaries;
- migrations, indexes, pruning, and retention behavior;
- public API drift from Laravel AI conventions.

Avoid broad rewrites unless they are already scoped in an approved plan. If a
refactor is needed, keep it incremental and preserve existing behavior first.

## Release Discipline

Before a release tag, the maintainer should verify:

- `composer test`, `composer lint`, and `composer analyse` pass.
- Before **widening** the `laravel/ai` version range in this package’s
  `composer.json`, the proposed constraint has been exercised (for example
  `composer update laravel/ai` or a temporary constraint) and representative
  swarm paths have been smoke-tested; note outcomes in the PR or release notes.
- Dependency updates to PHP, Laravel, or `laravel/ai` have been smoke-tested
  against representative swarm paths.
- `CHANGELOG.md` includes added, changed, fixed, and breaking notes as needed.
- `UPGRADING.md` includes action-oriented steps for any breaking or
  migration-sensitive change.
- README installation, configuration, and compatibility notes are accurate.
- Package migrations and rollbacks have been reviewed for existing installs.

Laravel and `laravel/ai` bumps are integration-test events. This package's
changelog documents Swarm-owned changes, not every upstream framework behavior
shift.

## Maintainer and Ownership

Laravel Swarm is currently maintained by Daniel Berry. That solo-maintainer
status is an adoption consideration for teams that plan to rely on durable
execution in production.

Additional maintainers may be added after sustained, high-quality contributions
that show judgment across package API design, Laravel conventions, persistence,
queue behavior, documentation, and release discipline. Maintainer access should
be granted deliberately and documented in release or project notes.

If the package becomes unavailable or unmaintained, adopters should evaluate a
fork by checking test health, Laravel and `laravel/ai` compatibility, migration
history, and whether the fork preserves public API compatibility. The MIT
license permits forking, but production forks should own their upgrade and
security process explicitly.
