# Contributing

Thanks for taking the time to improve Laravel Swarm. Tagged releases are
published on [Packagist](https://packagist.org/packages/builtbyberry/laravel-swarm).
Changes should preserve the Laravel-native feel described in the README:
familiar public verbs, small surface area, explicit configuration, and clear
operational behavior.

This file is the contributor entry point. [AGENTS.md](AGENTS.md) is the
canonical package context (architecture, conventions, release workflow) and
is required reading before any non-trivial change.

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

Continuous integration runs the same checks on **stable-latest** and **lowest**
Composer resolutions: `composer test:coverage`, `composer test:process-concurrency:ci`,
and `composer analyse`; plus `composer lint` and `composer test:compliance` (the
scope-isolation/propagation + replay-determinism `compliance` Pest group, a discrete
re-runnable evidence lane) on **stable-latest** only. Install PCOV for PHP locally when you want to
match CI or debug coverage failures; otherwise `composer test` remains the default
fast path without coverage. If workflow runtime becomes prohibitive, maintainers may
split **lowest**-resolution lint, coverage, or process-concurrency into a nightly job;
until then, pull requests validate both matrices equally.

**Process concurrency validation** — CI runs `composer test:process-concurrency:ci`
on every matrix row. That script is like `composer test:process-concurrency` but adds
Pest’s `--fail-on-skipped`, so the workflow **fails** if any test in that folder is
skipped (a broken driver cannot look green while providing no coverage). For day-to-day
local work, use `composer test:process-concurrency`, which still **skips** with an
explicit reason when `proc_open` or subprocess bootstrap is unavailable instead of
flaking. Use `composer test:process-concurrency:ci` locally to match GitHub Actions.

The lane exercises parallel and hierarchical parallel swarms against Laravel’s
real `process` concurrency driver (subprocess workers), not the `sync` driver
used by the default suite. Run it when changing `ParallelRunner`,
`HierarchicalRunner`, or anything that affects closure serialization or
container resolution for concurrent workers. If the CI job fails with skips,
check `proc_open`, PHP build flags, and Testbench/Artisan subprocess bootstrap
(see [Laravel concurrency](https://laravel.com/docs/concurrency)).

If you run PHPStan directly, use the same command as CI:

```bash
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
```

Static analysis is configured at **level 7** in `phpstan.neon` (Larastan
extension). A narrow `property.notFound` ignore applies only to database query
row objects in the large durable/history persistence classes and the Pulse
`SwarmSteps` Livewire card, where enumerating every dynamic column as a typed
shape would not be practical.

`composer lint` is the non-mutating Pint check. Use `composer format` only when
you intentionally want Pint to rewrite files.

## Test Tier Expectations

The suite is organized by tier; new tests must live in the right folder.

- **`tests/Unit/`** — fast, deterministic, no Laravel application boot beyond
  Testbench. Use for pure logic, value objects, enums, contract-shape checks,
  and small collaborators that can be exercised without persistence.
- **`tests/Feature/`** — Testbench-backed feature tests that exercise real
  service-provider wiring, persistence stores, events, dispatcher routing, and
  durable runtime collaborators against in-memory or SQLite drivers. Most
  behavior changes need a Feature test.
- **`tests/ProcessConcurrency/`** — the dedicated lane that uses Laravel’s real
  `process` concurrency driver (subprocess workers) instead of `sync`. Add a
  test here whenever you change `ParallelRunner`, `HierarchicalRunner`, or any
  path that affects closure serialization or container resolution for concurrent
  workers. CI runs this lane with `--fail-on-skipped`; a test that skips on CI
  is treated as a failure.

The package `TestCase` sets `swarm.capture.*` to **true** and
`swarm.persistence.encrypt_at_rest` to **false** so the suite exercises full
persisted payloads without coupling every test to the conservative production
defaults. If your change depends on either default, set it explicitly inside
the test rather than relying on the `TestCase` value.

### Writing `tests/ProcessConcurrency/*` worker closures

Tests in this lane that pass anonymous closures to
`$concurrency->driver('process')->run([...])` ship those closures to a child
PHP process via `php artisan invoke-serialized-closure`. The child runs
testbench's bare Laravel — **it does not boot Pest, and it does not know
about the package being tested**. Two traps follow:

1. **Closure scope class.** A closure defined inline inside a Pest
   `test('...', function () { ... })` body inherits the Pest auto-generated
   `P\Tests\...` class as its scope. The child can't resolve that class and
   unserialize fails with `Class "P\Tests\..." not found`. **`static function`
   alone does NOT fix this** — it strips `$this` but keeps the scope class.
   The fix is to define the worker closure inside a **free function** in the
   test file (or any non-Pest class) so its scope class is `null`. See
   `auditOutboxConcurrencyWorker()` in
   `tests/ProcessConcurrency/AuditOutboxConcurrencyTest.php` for the canonical
   pattern.
2. **Package container is not bootstrapped in the child.** testbench discovers
   packages only from its own `vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php`,
   which does not list the package under test. So
   `app(SomeContract::class)` in the worker closure throws
   `BindingResolutionException` for any interface bound by
   `SwarmServiceProvider`. If your worker needs the swarm container, register
   the provider explicitly **inside the closure** and set the minimum config
   the binding chain requires:

   ```php
   config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
   config()->set('swarm.persistence.driver', 'database');
   config()->set('swarm.persistence.encrypt_at_rest', false);
   if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
       app()->register(SwarmServiceProvider::class);
   }
   ```

   Resolving **concrete** classes (e.g. an agent class) does not need this —
   the child container can reflection-instantiate concrete classes without
   any provider. This is why `RealProcessConcurrencyTest.php` works without
   the bootstrap dance: its worker closures (defined inside `ParallelRunner`,
   an autoloadable internal class) only resolve concrete agent classes.

DB connections do flow naturally — the spawned child inherits parent
environment variables, and testbench reads `DB_*` at boot.

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
- Add or update tests for behavior changes (see test tiers above).
- Update docs when public behavior, configuration, migrations, commands, or
  operational expectations change.
- Update `CHANGELOG.md` for user-visible changes.
- Add `UPGRADING.md` guidance when a release requires user action.
- Keep public APIs backward-compatible unless the PR is explicitly a breaking
  change (see [Stability Surface](#stability-surface)).

Migration changes need extra care. Explain production impact, rollback behavior,
large-table locking risk, and whether custom `swarm.tables.*` names affect the
change.

PRs are organized into topic branches against a long-lived `release/v<X.Y.Z>`
branch, with Conventional Commits scoped by area (`feat(audit):`,
`fix(runner):`, `docs(contributing):`, etc.). The full branching and
three-phase wrap mechanics live in
[AGENTS.md](AGENTS.md#release-workflow); read that section before opening a
release-session topic branch.

## Stability Surface

Laravel Swarm distinguishes a small public surface that is covered by semver
from a larger set of internals that may change at any time. The canonical
definition lives in [`UPGRADING.md`](UPGRADING.md#stability-and-the-public-api);
the matrix of public surfaces is in
[`docs/public-surface.md`](docs/public-surface.md). The notes below are the
day-to-day contributor view.

### `@internal` convention

Starting with v0.4, every class in `BuiltByBerry\LaravelSwarm` that is not part
of the public surface carries an `@internal` PHPDoc tag. Anything not marked
`@internal` and reachable through the public surfaces (Facades, `Runnable`
contract, documented response and context types, attributes, `swarm:*` Artisan
commands, documented events, the published `config/swarm.php` keys, and the
audit evidence envelope) is treated as public.

For contributors:

- **Adding a new class:** default to marking it `@internal` unless you are
  explicitly extending the documented public surface. Internals can be
  refactored freely between minor releases.
- **Touching an existing `@internal` class:** you may change its shape — but
  if an application can reasonably reach it through a public surface today,
  treat the change as if it were public until a maintainer confirms otherwise.
- **Touching an existing public class:** the deprecation policy in
  [`UPGRADING.md`](UPGRADING.md#deprecation-policy) applies. Removals require
  `@deprecated` first, then removal one minor release later, with an
  `UPGRADING.md` entry on both sides.

PHPStan respects `@internal` and will flag any application code that reaches
into a marked class. Treat those flags as the first signal that a public verb
or contract is missing.

### When to ask before extending public surface

Open an issue (or flag in the PR description) before adding any of the
following — they widen the contract that semver covers:

- A new public method on a Facade, response object (`SwarmResponse`,
  `QueuedSwarmResponse`, `StreamableSwarmResponse`, `DurableSwarmResponse`),
  `RunContext`, or `SwarmHistory`.
- A new public attribute (`#[Topology]`, `#[Timeout]`, etc.).
- A new `swarm:*` Artisan command, or a new argument/option/exit code on an
  existing one.
- A new lifecycle or stream event class — and the contract for emission.
- A new key in `config/swarm.php`. Keys must ship with a safe production
  default and an inline comment explaining intent.
- A new contract under `src/Contracts/` that applications are expected to
  implement (see [Audit pipeline contributions](#audit-pipeline-contributions)
  for the audit-extension shape).

These are not blockers — they are scope conversations. The goal is to land
the right contract once rather than deprecate an early one.

### Pulse component pattern

Pulse cards and Livewire components live in `src/Pulse/`:

- `src/Pulse/Recorders/` — public surface. Applications enable recorders in
  `config/pulse.php`, so renaming a recorder class or removing a documented
  configuration key is a breaking change.
- `src/Pulse/Livewire/` — `@internal`. The rendered cards are stable user
  experience, but the Livewire component class names, public properties, and
  view paths are implementation details and can move between releases.
- `src/Pulse/Support/` — `@internal` helpers (Pulse keys, formatting).

When adding a new Pulse card, mark the Livewire component `@internal`, document
the recorder and the user-visible card name in `docs/pulse.md`, and use
`config/swarm.php` (not `config/pulse.php`) for any swarm-side tuneables.

## Audit Pipeline Contributions

Audit pipeline work covers the contracts that emit and route audit evidence,
the `EvidenceEnvelope` shape, and the outbox/dispatcher routing. Read
[`docs/audit-evidence-contract.md`](docs/audit-evidence-contract.md) before
opening a PR — the frozen envelope fields and category list are the reference,
and the guidance below should not contradict it.

### Audit-extension contract pattern

Audit extensibility is bind-a-contract-in-the-container, not extend-a-class.
The public contracts live in `src/Contracts/`:

| Contract              | Default binding                         | Bind to                                                              |
|-----------------------|-----------------------------------------|----------------------------------------------------------------------|
| `ActorResolver`       | `DefaultActorResolver`                  | Source actor identity from request state, API tokens, signed payloads. |
| `CapturePolicy`       | `BooleanCapturePolicy`                  | Make per-run capture decisions instead of static `swarm.capture.*`.   |
| `SwarmAuditSigner`    | absent                                  | Sign envelopes for tamper-evident chains. Absence = unsigned, like v0.3. |
| `SinkFailureHandler`  | `ConfiguredSinkFailureHandler`          | Route sink failures (retry, queue, dead-letter, halt) per application. |
| `SwarmAuditSink`      | `NoOpSwarmAuditSink`                    | Forward evidence to your append-only store, SIEM, or queue listener.  |
| `AuditOutbox`         | DB-backed when database persistence is on; `NoOpAuditOutbox` otherwise | Custom retry persistence (rare).                          |

Guidance when contributing here:

- **Prefer adding a new method on an existing contract over creating a new
  contract.** Contracts in this list are public surface and applications
  implement them; a new contract widens that surface.
- **Adding a new method to a public contract is a breaking change** for any
  application that has its own implementation. Default-implement in PHP 8 only
  if the default makes sense for every binding; otherwise, treat it like any
  other public-surface change and follow the deprecation policy.
- **The dispatcher is `@internal`.** `SwarmAuditDispatcher` enriches the
  envelope, routes sink failures, and caps retries via
  `MAX_HANDLER_ITERATIONS = 5`. Behavior changes inside the dispatcher are
  fine; renaming or changing the public failure-decision shape is not.
- **`SinkFailureDecision` is public.** Adding a new case is additive (sinks
  must tolerate cases they do not handle, by convention), but changing or
  removing a case is a `schema_version` / breaking-change event. Bias toward
  extending `ConfiguredSinkFailureHandler` policy mapping (e.g. a new
  `failure_policy` config string) instead of adding a case unless the existing
  cases genuinely cannot express the behavior.

### Evidence envelope extension pattern

Every emitted payload is enriched by `EvidenceEnvelope` with
`schema_version`, `category`, and `occurred_at`. The shape and the list of
frozen categories are documented in
[`docs/audit-evidence-contract.md`](docs/audit-evidence-contract.md). When
contributing here:

- **Additive change → no `schema_version` bump.** A new optional field on an
  existing category, or a brand-new category name, does not change
  `schema_version`. Sinks are required to tolerate unknown keys and unknown
  category names.
- **Breaking change → `schema_version` bump.** Removing, renaming, or
  retyping a frozen field, or removing/renaming a frozen category, requires
  incrementing `schema_version` (currently `"2"`) and an `UPGRADING.md` entry.
  The v0.4-to-v0.5 `command.*` envelope unification (`actor` moved into
  `metadata.actor`, `schema_version` bumped from `"1"` to `"2"`) is the
  reference example.
- **Update the frozen-fields tables.** Any change to a category’s correlation
  fields needs the matching row updated in the
  "Frozen Categories" section of `docs/audit-evidence-contract.md`. The doc
  is the contract; if the code drifts from the table, the table wins until a
  PR moves it.
- **Reserved metadata keys are a separate concern.** The
  `EvidenceEnvelope::RESERVED_METADATA_KEYS` constant is the authoritative
  list of metadata keys the envelope always includes. Adding a reserved key
  is additive; removing or renaming one is a breaking change.

### Dispatcher routing pattern

`SwarmAuditDispatcher` is the only thing that talks to the bound sink. It:

1. Enriches the payload via `EvidenceEnvelope::enrich()`.
2. Calls the bound `SwarmAuditSigner` if one is registered. Signing failures
   route through the bound `SinkFailureHandler` exactly like sink failures.
3. Emits to the bound `SwarmAuditSink`. Sink failures route through
   `SinkFailureHandler`, which returns a `SinkFailureDecision` (`Swallow`,
   `RetryInline`, `Halt`, `Queue`, or `DeadLetter`).
4. For `Queue` / `DeadLetter`, persists the failed record to the audit outbox.
   On the cache persistence driver, the outbox is unavailable and the
   dispatcher degrades to log-and-swallow with a warning log.

When contributing here:

- **Do not call sinks directly from runtime collaborators.** Always route
  through the dispatcher. Direct calls bypass enrichment, signing, capture
  policy, and the failure handler.
- **Do not change the runaway-guard threshold (`MAX_HANDLER_ITERATIONS = 5`)
  without a maintainer conversation.** It exists to bound buggy custom
  handlers; changes affect operational guarantees.
- **New emission sites need an `ActorResolver` and `CapturePolicy` story.**
  If a new audit category emits run-bound evidence, it must route metadata
  through the same dispatcher path so allowlist filtering, actor binding,
  and capture decisions are honored automatically.

## Review Expectations

Reviews prioritize correctness, operational safety, and framework fit over
style preference. Expect close review on:

- streaming event contracts and replay behavior;
- capture, redaction, and sensitive persistence surfaces;
- the audit pipeline — envelope shape, signer behavior, sink failure routing,
  outbox retention;
- durable leases, recovery, retries, waits, signals, and child swarms;
- queue serialization and container-resolution boundaries;
- migrations, indexes, pruning, and retention behavior;
- public API drift from Laravel AI conventions.

Maintainers use the eight-lens multi-expert review for meaningful changes; see
[AGENTS.md](AGENTS.md#review-method) for the lens list and the severity gate.

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

The branching, commit, and three-phase wrap mechanics that releases follow
(`release/v<X.Y.Z>` long-lived branch, topic branches, `review-followups` →
`release-wrap` → `readiness-followups`) are recorded in
[AGENTS.md](AGENTS.md#release-workflow). Maintainers driving a release should
read that section before opening the release branch.

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
