# Upgrading Laravel Swarm

This guide is the action checklist for upgrading applications that use Laravel
Swarm. Read it when a release asks you to run commands, update published files,
or change application code.

[CHANGELOG.md](CHANGELOG.md) remains the full release history. This file only
records upgrade work an application operator or maintainer may need to perform.

Laravel Swarm follows [semantic versioning](https://semver.org/) for API and
behavior owned by this package. Swarm does not fully isolate your application
from **PHP**, **Laravel**, or **[Laravel AI](https://github.com/laravel/ai)**.
Treat Composer upgrades that touch those dependencies as integration-test events
for your app.

## Stability and the public API

Laravel Swarm distinguishes between a small public surface that is covered by
semver and a larger set of internals that may change at any time. The lists
below are the framing; specific class-level markers and the persisted schema
freeze are documented in the per-version blocks as they land.

### Public surfaces

The following are public and covered by semver:

- The `Swarm` and `SwarmHistory` Facades and their documented methods.
- The `Runnable` contract that user swarms implement.
- The keys published in `config/swarm.php`. Renaming or removing a key is a
  breaking change; adding a new key with a backward-compatible default is not.
- The documented event types dispatched by the runner, including
  `SwarmStarted`, `SwarmCompleted`, `SwarmFailed`, and the other events listed
  in the events documentation.
- The signatures of the `swarm:*` Artisan commands — command name, documented
  arguments, options, and exit codes.
- The audit evidence envelope, including its `schema_version` field. Envelopes
  are versioned and old `schema_version` values continue to be readable across
  minor releases.

Code that only uses these surfaces should upgrade with the steps listed in the
per-version blocks below and nothing more.

### Internal surfaces

Everything that is not in the list above is internal. That includes runners,
recorders, dispatchers, persistence stores, durable runtime components, queue
and stream adapters, and any contract that does not carry a `@stable` marker.
Internals may change in any minor release without notice. Applications that
extend, subclass, or directly instantiate internal classes are responsible for
re-validating those code paths on every upgrade.

### Semver pre-1.0

Laravel Swarm is pre-1.0 and follows the common pre-1.0 reading of semver:

- A minor bump (`0.x.0` → `0.(x+1).0`) may change or remove public surfaces.
  Each minor release ships a dedicated block in this file describing the
  required migration.
- A patch bump (`0.x.y` → `0.x.z`) is strictly additive on public surfaces.
  Patch releases may add new methods, new events, or new config keys with
  safe defaults, but will not remove or rename existing public surfaces.

The per-version blocks below are the authoritative change record for what
moved between releases.

### How stability is marked in code

Starting with v0.4, classes that are not part of the public surface listed
above are annotated with an `@internal` PHPDoc tag. Anything inside the
`BuiltByBerry\LaravelSwarm` namespace that is *not* marked `@internal` and is
reachable through the public surfaces above is treated as public.

Static analysis tools that respect `@internal` (PHPStan, Psalm) will flag
application code that reaches into marked classes. Treat those warnings as a
signal to switch to a public verb or open an issue describing the use case.

### Deprecation policy

When a public surface needs to change, the existing API is first marked with
an `@deprecated` PHPDoc tag and a short note pointing at the replacement. The
deprecated surface continues to work through the next minor release, then is
removed in the minor release after that. Each removal gets its own block in
this file describing the migration.

The deprecation timeline applies only to public surfaces. Internals may be
removed in the same release that introduces a replacement.

## Upgrade Checklist

Use the normal Laravel package upgrade flow first:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
php artisan migrate
```

If your application caches configuration during deploys, rebuild the cache after
publishing or editing config:

```bash
php artisan config:cache
```

Then run the checks that match how your application uses swarms:

- run your application test suite
- run at least one synchronous `prompt()` path
- run a queued swarm if you call `queue()` or `broadcastOnQueue()`
- run a streamed swarm if you call `stream()`, `broadcast()`, or `broadcastNow()`
- run a durable swarm if you call `dispatchDurable()`
- verify `swarm:status`, `swarm:history`, `swarm:recover`, and `swarm:prune`
  in environments where operators use them

## Published Config And Migrations

Laravel Swarm loads its package migrations by default. If you have not published
or edited the migrations, running `php artisan migrate` is usually enough.

If you published package migrations, compare your copies with the new package
migrations before deploying. Keep table names, indexes, and foreign keys aligned
with your `swarm.tables.*` configuration.

If you published `config/swarm.php`, compare it with the current package config
after each upgrade:

```bash
php artisan vendor:publish --tag=swarm-config --force
```

Do not run that command directly against a production app unless you are ready to
merge your local changes back in. A common workflow is to publish into a clean
branch, review the diff, then copy the new keys or default changes into your
application config.

Pay particular attention to config that changes persistence, capture, queues,
stream replay, durable runtime tables, pruning, and encryption at rest.

## Persistence decrypt failures (minor)

When `swarm.persistence.encrypt_at_rest` is enabled with the database driver,
designated string columns are sealed with Laravel’s encrypter (`APP_KEY`). If the
application key no longer matches the key used when rows were written (for example
after rotation without re-encryption), decryption fails.

Configure **`swarm.persistence.decrypt_failure_policy`** (env
**`SWARM_PERSISTENCE_DECRYPT_FAILURE_POLICY`**):

- **`null_with_log`** (default) — affected sealed **string** fields are returned as
  **null**; a **warning** is logged without ciphertext in the log context.
- **`legacy`** — previous package behavior: return the stored value unchanged (opaque
  `sw0:` ciphertext for sealed payloads).
- **`throw`** — rethrow the decryption exception.

Non-empty values that are **not** one of the above (case-insensitive), including typos,
are treated as **`null_with_log`** for effective runtime behavior. When
**`swarm.persistence.warn_on_invalid_decrypt_failure_policy`** is **`true`** (default,
env **`SWARM_WARN_ON_INVALID_DECRYPT_FAILURE_POLICY`**), Swarm logs **once per worker**
when that misconfiguration is first resolved during a decrypt failure path. Set it to
**`false`** if you cannot emit extra log lines for unknown policy strings.

Encrypt-at-rest applies to **designated string columns** (for example context `input`,
history step I/O). **JSON columns** (`data`, `metadata`, `artifacts`, and similar)
remain structured JSON in the database; do not rely on Swarm to encrypt arbitrary
nested secrets inside JSON unless your application handles that separately.

## Composer minimum-stability

The package uses `"minimum-stability": "dev"` with `"prefer-stable": true` in
[`composer.json`](composer.json) because `laravel/ai` is pre-1.0 and ships
dev-tagged releases. Composer will not resolve a pre-stable transitive from a
stable consuming project, so applications **must propagate the same setting**:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

`prefer-stable` keeps Composer biased toward tagged releases — only
dependencies without a stable release (today, `laravel/ai`) resolve to a `dev-`
constraint. This requirement will be dropped when `laravel/ai` reaches 1.0; the
package will then move to `"minimum-stability": "stable"` and consuming
applications will be free to do the same.

## Durable Outbox: Queue Connection Rename Hazard

When a durable run is dispatched, the outbox row stores the `queue_connection`
name that was active at dispatch time. As of the fix for the silent-rerouting
bug, an outbox row whose `queue_connection` no longer matches any key in
`config/queue.connections` is treated as **permanently invalid**: the row is
deleted, the failure is reported to the error tracker, and `swarm:relay` exits
with status 1.

**Impact:** If you rename a queue connection in the same release that deploys
this fix, any outbox rows written under the old connection name will be
permanently deleted when workers running the new code drain them. Before this
fix, those rows were silently dispatched on the application default queue
instead; now they are removed.

**Action required when renaming a queue connection:**

1. Drain the outbox to empty **before** deploying:
   ```bash
   php artisan swarm:relay --drain-until-empty
   ```
2. Confirm the outbox is empty:
   ```bash
   php artisan swarm:health --durable
   ```
3. Deploy the connection rename.

Alternatively, keep the old connection key in `config/queue.connections`
alongside the new one for at least one deploy cycle so that any in-flight rows
can drain normally.

## Durable Outbox: swarm:relay Exit Codes

`swarm:relay` now exits with **status 1** in two cases:

- An unhandled exception escaped the drain loop (was already status 1 in earlier versions via Laravel's command exception handling, but is now emitted before the exception is re-thrown so the audit event is always written).
- One or more entries could not be dispatched due to a **transient error** (queue driver unavailable, network blip). The rows remain in the outbox and will be re-claimed after the reservation timeout; the exit code gives monitoring systems an actionable signal without losing the work.

Exit status 0 now means the outbox is genuinely clean — every claimed entry was
either dispatched or permanently removed. Update any monitoring rules that only
alert on non-zero exits to distinguish the two failure cases using the
`status` field in the `command.relay` audit event (`transient_failure` vs
`error`).

## Contract Changes

Most applications should interact with swarms through Laravel-style public
verbs: `prompt()`, `queue()`, `stream()`, `broadcast()`, `broadcastNow()`,
`broadcastOnQueue()`, and `dispatchDurable()`.

Upgrade notes matter most if your application extends package internals,
implements storage contracts, subclasses database stores, publishes migrations,
or manually resolves runner services. Those extension points can require code
changes even when the application-facing swarm API stays the same.

## Dependency Upgrades

`laravel/ai` is required in the **^0.6** range today and is **pre-1.0**. Public
contracts, streaming behavior, and provider integrations can change between
releases without the stability guarantees of a stable major line.

When upgrading PHP, Laravel, or Laravel AI alongside Swarm:

1. Note the currently resolved versions with `php -v` and `composer show laravel/framework laravel/ai builtbyberry/laravel-swarm`.
2. Update the dependency or constraint in your application.
3. Run `composer update`.
4. Run your automated suite and swarm-heavy smoke paths, especially queued,
   streamed, and durable execution.

You may pin `laravel/ai` to an exact or narrower range in your application’s
`composer.json` when you need reproducible builds or a slower upgrade cadence:

```bash
composer require laravel/ai:0.6.2
```

That pins your application’s dependency resolution. It does not change the semver
range Laravel Swarm declares for Packagist.

This package’s `composer.json` uses `"minimum-stability": "dev"` with
`"prefer-stable": true` so pre-stable dependencies can resolve while Composer
still prefers tagged releases. Your application may need compatible Composer
stability settings while Laravel AI remains pre-stable.

## Upgrading to v0.12.2

v0.12.2 is a hardening release with **no migrations**. The only application-code change applies to operators who bind a custom `SwarmAuditSigner` (the default binding is absent — if you do not sign audit evidence, **no action is required**).

### Audit signing now requires a `signature_algorithm` (#223)

The package signs audit evidence on emit but, by design, never verifies a signature on read — verification is the responsibility of the sink that persists the records. To keep stored signatures verifiable and rotatable, `SwarmAuditDispatcher` now enforces one rule: if your signer adds a non-empty `signature`, it **must** also add a non-empty `signature_algorithm`.

A signer that returns a `signature` without an algorithm name is treated as a signing failure and routed through your bound `SinkFailureHandler`, exactly like any other signing failure. Under `swarm.audit.failure_policy=halt` the run throws `AuditSinkHaltedException`; under `swallow` the record is dropped — either way it never reaches the sink. Under a `queue`/`dead-letter` policy the record follows the configured audit-outbox path and is delivered to the sink on the next drain (the outbox replays the stored payload directly and does not re-run the guard), so **a deployment that must never persist an unverifiable record should use `halt`**. **Action:** if your signer sets `signature`, confirm it also sets `signature_algorithm` (e.g. `'hmac-sha256'`). The per-category opt-out (returning the payload unchanged for categories you do not sign) is unaffected. See `docs/audit-evidence-contract.md` → "Audit Signing".

## Upgrading to v0.12.1

v0.12.1 is a patch release with **no migrations** and **no application-code changes**. It hardens how the durable runtime decrypts its operational resume state (#212).

### Durable resume reads now decrypt strictly and fail loud

On a wrong or rotated `APP_KEY`, the durable runtime previously read operational resume values (the run's top-level resume input, hierarchical node outputs, branch input/output, child-run context payloads) back through the display `swarm.persistence.decrypt_failure_policy` — so under `null_with_log`/`legacy` a durable run could resume with a `null`/ciphertext prompt instead of failing. As of v0.12.1 those operational reads decrypt strictly and throw a `BuiltByBerry\LaravelSwarm\Exceptions\SwarmException` instead. **No action is required**: the failure is the intended behavior — re-point `APP_KEY` to the key that encrypted the stored rows and re-dispatch the run. The display policy still governs the evidence reads (run history, audit, the durable run inspector).

The public `DurableRunStore` read API you may call from application code — `find()`, `runIdsForLabels()`, and the other documented operational reads — is unchanged. (The strict tightening applies to the durable runtime's own resume reads, not to `find()`.) A child swarm recovered on the crash-before-first-dispatch path under `swarm.capture.inputs=false` resumes with the redacted input — you cannot replay an input you chose not to capture; this is pre-existing behavior.

## Upgrading to v0.12.0

v0.12.0 ships breaking changes on the public surface. It includes **one new migration** that makes the run-history step columns nullable so a `CaptureDecision::Skip` can persist `NULL` (see below); run `php artisan migrate`.

### Breaking: `CaptureDecision::Skip` now omits fields instead of redacting them

A `CapturePolicy` returning `CaptureDecision::Skip` for a category used to behave exactly like `Redact` — the value was still persisted and emitted, just as the `[redacted]` string. As of v0.12.0, `Skip` means **true omission on the evidence surfaces** (run history, lifecycle/stream events, audit envelopes):

- **Persisted/emitted arrays** (run-history steps, history `context`, lifecycle and stream events) drop the key entirely instead of carrying `[redacted]`.
- **Nullable database columns** store `NULL`: `swarm_run_steps.input`/`output` and the already-nullable `swarm_run_histories.output`.
- **Failures** under `Skip` omit the `error.message` key while keeping `error.class`.

> **Scope: capture governs evidence, not operational state.** The active-context
> store (`swarm_contexts.input`) is the **only** persisted source of the top-level
> input that a durable run resumes its first step from, so it is operational
> runtime state — it always retains the (encrypted) input for the run's TTL and is
> **never** nulled by a `Skip`. `Skip` omits input from the evidence/history/event/
> audit surfaces above; it does not erase the operational input. (Durable runs
> additionally require a `Full` active-context decision at dispatch.)

A new migration (`*_make_swarm_capture_columns_nullable`) makes `swarm_run_steps.input`/`output` nullable. Run `php artisan migrate`. If you have **never** bound a custom `CapturePolicy` that returns `Skip`, no row shape changes — the migration only widens the columns.

**The boolean path is frozen.** The default `BooleanCapturePolicy` (driven by `swarm.capture.*`) only ever returns `Full` or `Redact`, never `Skip`. Every existing `swarm.capture.*=false` install continues to see `[redacted]` exactly as before — only an explicit `Skip` from a custom policy changes the shape.

If you read persisted history/events programmatically, treat the I/O keys as optional and the columns as nullable when a `Skip` policy is in effect:

```php
// A Skipped output omits the key; a Redacted output is the [redacted] string.
$output = $step['output'] ?? null;            // may be absent under Skip
$message = $history['error']['message'] ?? null; // omitted under Skip, class retained
```

Lifecycle and stream event I/O fields (`SwarmStarted::$input`, `SwarmCompleted::$output`, `SwarmStepStarted`/`SwarmStepCompleted` I/O, and the stream events' `input`/`output`/`delta`/`message`) are now `?string` and emit `null` under `Skip`. Artifacts `Redact`/`Skip` collapse is pre-existing and unchanged; `RedactingMemoryStore` Skip behavior is unchanged.

### Breaking: audit evidence schema version is now `"3"`

Because the `Skip` omission shape changes the audit payload contract, `EvidenceEnvelope::SCHEMA_VERSION` is bumped to `"3"`. Any consumer that pins or asserts the evidence schema version must accept `"3"`. Installs that never return `CaptureDecision::Skip` see no payload-shape change.

### Breaking: `Contracts\HasStructuredOutput` removed

`BuiltByBerry\LaravelSwarm\Contracts\HasStructuredOutput` has been removed. It was a zero-value wrapper — it added no methods beyond what `Laravel\Ai\Contracts\HasStructuredOutput` already declares. Switch any application code that references the Swarm-owned interface to the upstream contract directly:

```php
// v0.12 — use the upstream contract
use Laravel\Ai\Contracts\HasStructuredOutput;

class MyCoordinator implements HasStructuredOutput { /* unchanged */ }
```

> **Note:** This reverses the migration instruction from the
> [v0.5.0 upgrade guide](#upgrading-to-v050), which asked coordinator classes
> to adopt the Swarm marker. The upstream contract was always the runtime
> requirement; the Swarm marker was a redundant indirection.

Agent classes that implement only `HasStructuredOutput` (with no other Swarm contracts) are unaffected at runtime — method signatures are identical. Only the `use` statement and any `instanceof` checks against the Swarm namespace need updating.

### New: `dispatchDurable()` now supported for static-hierarchical swarms

Previously, calling `dispatchDurable()` on a `Topology::StaticHierarchical` swarm threw a `SwarmException`. As of v0.12.0, static-hierarchical swarms execute durably: the plan is built at dispatch time (fail-fast validation), and execution begins directly with the first worker — there is no LLM coordinator step.

If you had a `catch` block guarding against this exception, remove it.

`swarm_durable_*` rows are now written when `dispatchDurable()` is called on these swarms. The stored `route_cursor` metadata includes `coordinator_agent_class: ''` (empty string) for durable static-hierarchical runs. Guard against absent coordinator data with `!empty($metadata['coordinator_agent_class'])` rather than `isset`.

### Behavior change: streamed static-hierarchical runs now emit `swarm_memory_snapshots` rows

Previously, `SwarmRunner::stream()` on a `StaticHierarchical` swarm produced no `swarm_memory_snapshots` rows — a pre-existing gap tracked in [#159](https://github.com/builtbyberry/laravel-swarm/issues/159). As of v0.12.0, a snapshot is frozen before each worker invocation and tool-call pairs are appended, consistent with all other runners. Applications that explicitly assert a zero-row count in `swarm_memory_snapshots` for these runs — for example, in database-level integration tests — must update those assertions.

No migration is required; the `swarm_memory_snapshots` table already exists from v0.9.0.

### Behavior change: non-final streamed steps no longer re-execute on resume

Previously, re-running an abandoned streamed sequential run with the same run id replayed only the **terminal** step byte-identically (from its frozen `swarm_memory_snapshots` row); every **non-final** step re-executed against live memory — its provider was re-invoked and its tool side effects (memory writes, external calls) re-fired. As of v0.12.0 ([#202](https://github.com/builtbyberry/laravel-swarm/issues/202)), a completed non-final step is **skipped** on resume: its provider is not re-invoked and its side effects do not re-fire — its recorded output is rehydrated from the new `swarm_stream_step_checkpoints` table so the downstream prompt stays byte-identical. This is governed by the memory replay mode (`#[MemoryReplay]` / `swarm.memory.replay_mode`, default `frozen_view`; set `fresh_execution` to opt out) and requires the database persistence driver.

Applications that assert a non-final step runs **twice** across a crash + resume (for example, an invocation counter expecting `2`) must update those assertions to `1`. Behavior under the cache driver, in `fresh_execution` mode, and for fresh (non-resumed) runs is unchanged.

A new migration creates `swarm_stream_step_checkpoints`; run `php artisan migrate` (or `swarm:install:memory --migrate`). On the database driver without the migration, multi-step resume silently degrades to re-execution rather than failing.

## Upgrading to v0.11.0

**No required action.** v0.11.0 is purely additive — there are no breaking
changes for applications *or* for code that implements a Swarm contract or
extends a Swarm class. Everything new is opt-in:

- The `Recall` / `Remember` agent memory tools and the `HasSwarmMemoryTools`
  trait are **disabled by default**. They do nothing until you set
  `swarm.memory.tools.enabled` (`SWARM_MEMORY_TOOLS_ENABLED`) to `true` and
  attach the tools to an agent. Granting an LLM read/write access to shared run
  memory is an explicit decision — review your `MemoryPropagationPolicy` and
  `MemoryCapturePolicy` before enabling. See
  [docs/memory-recipes.md](docs/memory-recipes.md) for the safe patterns.
- The `make:memory-tool` generator is a new command; it changes nothing about
  existing tools.
- The Octane worker-reset listener (#171) is wired only when `laravel/octane`
  is installed and is behaviourally invisible otherwise.

If you publish `config/swarm.php`, re-publish (or merge) it with
`php artisan vendor:publish --tag=swarm-config --force` to pick up the new
`memory.tools` block — optional, since the package falls back to the shipped
defaults for any key you don't override.

## Upgrading to v0.10.0

v0.10.0 is **non-breaking for applications** that only consume the documented
public surface, and the **default behavior is identical to v0.9** — see the
memory propagation policy note below for the one semantic change and why it does
not affect unmodified swarms. It is **breaking for code that implements the
`SnapshotsMemory` contract directly** — almost always a custom or companion
persistence driver (for example, the `laravel-swarm-memory-vector` package). If
you have never implemented `SnapshotsMemory` yourself, no action is required.

### Breaking for custom drivers: `SnapshotsMemory::allForRun()`

The `BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory` contract gains one new
required method:

```php
/**
 * Return every persisted snapshot for $runId, ordered by step_index ascending.
 * Returns an empty array when no snapshots were recorded.
 *
 * @return array<int, \BuiltByBerry\LaravelSwarm\Memory\MemorySnapshot>
 */
public function allForRun(string $runId): array;
```

It backs the new `swarm:memory:inspect` operator command, which lists every step
recorded for a run without reaching past the contract into the underlying table.

The package's own drivers — `DatabaseMemorySnapshotRecorder` and
`NullSnapshotsMemory` — already implement it. **If you ship your own
`SnapshotsMemory` implementation, add the method or your container binding will
fatal with `Class … must implement method allForRun`.** A minimal database-style
implementation orders by `step_index` and hydrates each row the same way `find()`
does; a no-op store may simply `return [];`.

### New: `swarm:memory:inspect` command

No action required — additive. `php artisan swarm:memory:inspect <run-id>` renders
the frozen `MemorySnapshot` rows for a run (the database persistence driver only;
under the cache driver it surfaces a configuration hint). See
[docs/memory.md](docs/memory.md) for usage.

### New: memory propagation policy (semantic change, default preserves v0.9)

A new `BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy` now decides
which memory entries a worker agent sees at invocation. **The default
(`DefaultPropagationPolicy`) presents the Run-scoped view only — byte-identical
to what runners froze and agents saw before v0.10.** No package code writes to
the Conversation / Agent / Swarm scopes during a run, so unmodified swarms are
unaffected.

This is a **semantic** seam, not an API change: if you bind a custom policy
(globally via `swarm.memory.propagation_policy`, or per swarm with
`#[PropagationPolicy(MyPolicy::class)]`), downstream agents may see different
memory than they did before — by design. No action is required to keep the
v0.9 behavior; it is the default.

**Breaking for custom `SnapshotsMemory` drivers:** `snapshot()` gains a third
parameter:

```php
public function snapshot(string $runId, int $stepIndex, ?array $entries = null): MemorySnapshot;
```

This is a **required signature change for implementors**, in the same class as
the `allForRun()` addition above. A driver that keeps the two-argument
`snapshot(string $runId, int $stepIndex)` signature does **not** keep working —
PHP rejects it at class declaration with `Declaration of …::snapshot() must be
compatible with …`, so the container binding fatals on the first swarm run. You
must add the third parameter. *Callers* are unaffected: existing two-argument
calls to `snapshot()` still bind to the optional parameter and behave exactly as
before — only classes that **implement** the contract must update.

To honor propagation policy once you've updated the signature: when `$entries`
is non-null, freeze exactly those entries (the runner has already applied the
policy); when it is null, fall back to your existing Run-scope gather — that is
the back-compat path for any caller that hasn't adopted the parameter.

### New: memory capture policy (default preserves v0.9, no action required)

A new `BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy` governs whether a
memory entry is written as-is, redacted, or dropped at the write boundary — the
write-side counterpart to the audit `CapturePolicy`. **The default
(`DefaultMemoryCapturePolicy`) writes every entry as-is, byte-identical to v0.9.**

This is a **sibling contract, not a change to `CapturePolicy`** — nothing is
added to the existing interface, so **no third-party `CapturePolicy`
implementation breaks** and there is no required signature change. Enforcement
lives in a new `RedactingMemoryStore` decorator applied with
`$this->app->extend(MemoryStore::class, …)`; if you resolve `MemoryStore` you now
receive the decorator (call `->inner()` for the wrapped driver). Because it is an
extender, it wraps **whatever** `MemoryStore` is bound — including a custom or
companion driver you register yourself — so redaction can't be bypassed by
rebinding the store. One caveat: bind a custom store with `bind()`/`singleton()`,
**not** `Container::instance()` — a pre-built instance registered via `instance()`
sidesteps container extenders and would not be wrapped.

To opt in to redaction, bind a policy:

```php
// config/swarm.php → 'memory' => ['capture_policy' => App\Memory\RedactSsnPolicy::class]
// or SWARM_MEMORY_CAPTURE_POLICY=App\Memory\RedactSsnPolicy

final class RedactSsnPolicy implements MemoryCapturePolicy
{
    public function memory(MemoryScope $scope, string $key, ?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $key === 'ssn' ? CaptureDecision::Redact : CaptureDecision::Full;
    }
}
```

`Redact` replaces scalar **values** with the `SwarmCapture::REDACTED` sentinel
(preserving array shape and keys, the same convention the audit path uses; entry
`metadata` and keys are not redacted — keep PII out of those); `Skip` drops the
entry entirely (no row, no `MemoryWritten` event) and leaves any pre-existing
entry at the address untouched. Because redaction happens at the store, the
propagation view and frozen `MemorySnapshot` honor it automatically. A `Redact`
dispatches a new `MemoryRedacted` event and a `Skip` a new `MemoryWriteSkipped`
event (address only, no value) for audit listeners; the default `Full` policy
fires neither. See [docs/memory.md](docs/memory.md#capture-policy-write-time-redaction).

### New: retention purge command and `(scope, created_at)` index migration

v0.10.0 adds the `swarm:memory:purge` retention command and a migration that
adds a `(scope, created_at)` index to `swarm_memories`. Two operational notes:

- **Large-table index build (action may be required).** The migration builds the
  index **inline**, which takes a write lock (MySQL/InnoDB) or an exclusive lock
  (Postgres) for the build duration. On a large `swarm_memories` table — exactly
  the population this index serves — running it during `php artisan migrate` can
  stall the deploy and block writes for minutes. If your table is large, build
  the index **out of band** before (or instead of) the inline migration —
  Postgres `CREATE INDEX CONCURRENTLY swarm_memories_scope_created_at_index ON
  swarm_memories (scope, created_at);`, MySQL online DDL or
  `pt-online-schema-change` — then mark the migration as run. On a small table
  the inline build is fine and needs no action.
- **Throttling the purge sweep.** `swarm:memory:purge` deletes in bounded
  batches. On large tables a flat-out scheduled sweep can pressure the database
  or a read replica; pass `--pause=<ms>` to sleep between batches (e.g.
  `--pause=100`) and schedule it off-peak. The default is no pause, preserving
  prior behavior. See [docs/compliance-audit.md](docs/compliance-audit.md#memory-retention).

## Upgrading to v0.9.0

v0.9.0 is **non-breaking** on public-surface contracts but ships two new database
tables and one new env key. No public-method signatures changed; no existing
config keys were renamed or removed.

### Required: run migrations

Two new tables are added for the memory subsystem. If you use the
**database persistence driver**, run `php artisan migrate` before deploying:

```bash
php artisan migrate
```

This creates:

- `swarm_memories` — scoped key-value memory entries (`MemoryScope::Run /
  Conversation / Agent / Swarm`).
- `swarm_memory_snapshots` — frozen point-in-time snapshots of Run-scope memory,
  used by `MemoryReplayCoordinator` to serve a crash-retried agent the same view
  it saw at the original invocation.

If you use the **cache persistence driver**, neither table is needed and no
migration action is required. `CacheMemoryStore` is the automatic fallback on
non-database drivers and needs no configuration.

### Optional: seed `SWARM_MEMORY_REPLAY_MODE`

A new env key controls the default replay policy for the Run-scope memory
snapshot:

```
SWARM_MEMORY_REPLAY_MODE=frozen_view
```

- **`frozen_view`** (default) — a retried agent sees the frozen snapshot from the
  original invocation; mutations made between the crash and the retry are not
  visible. This is the safe choice for idempotent, deterministic reruns.
- **`fresh_execution`** — the retry agent reads live memory; useful when
  intermediate mutations are intentional (operator corrections, observability
  writes you want the retry to act on).

`swarm:install` seeds this key with `frozen_view` automatically if you run it
now. If you manage `.env` manually, append the line above. Omitting the key
defaults to `frozen_view` in the package config — existing deployments are
unaffected at runtime but may want the key explicitly for documentation and
operator clarity.

Override per-swarm with the `#[MemoryReplay]` attribute when the global default
does not fit a particular swarm's retry contract:

```php
use BuiltByBerry\LaravelSwarm\Memory\Attributes\MemoryReplay;
use BuiltByBerry\LaravelSwarm\Memory\Enums\ReplayMode;

#[MemoryReplay(mode: ReplayMode::FreshExecution)]
class MySpecialSwarm implements Swarm { ... }
```

### Optional: verify with `swarm:install:memory`

The new `swarm:install:memory` sub-installer verifies the two tables are present,
prints the effective memory driver, and confirms your `SWARM_MEMORY_REPLAY_MODE`:

```bash
php artisan swarm:install:memory
```

Use this for a quick sanity-check after deploying, or as part of a
post-deploy health script alongside `swarm:health`.

### What did not change

- No public `Swarm`, `SwarmHistory`, or `Runnable` method signatures changed.
- The audit pipeline contracts (`SwarmAuditSink`, `ReadableSwarmAuditSink`,
  `SwarmAuditSigner`, `ActorResolver`, `CapturePolicy`, `SinkFailureHandler`,
  `AuditOutbox`) are unchanged.
- The durable execution contract surface (`dispatchDurable()`, `swarm:relay`,
  `swarm:recover`, `swarm:prune`) is unchanged.
- `RunContext` is non-breaking: `mergeData()` and the existing fluent builder
  API continue to work unchanged. The new `ArrayAccess` implementation is
  additive; internal mutations already went through `mergeData()`, so the
  write-through to `SwarmMemory` is transparent to existing callers.
- Published `config/swarm.php`: new `memory` section with `driver` and
  `replay_mode` keys is appended with safe defaults; no existing key was renamed
  or removed. If you published and pinned the config, diff against the current
  package config to pick up the new keys.
- The Pulse recorder + dashboard card surface is unchanged.
- `swarm:health`, `swarm:trace`, `swarm:audit:status`, `swarm:audit:reconcile`
  command signatures and exit codes are unchanged.

## Upgrading to v0.8.0

v0.8.0 is purely additive on top of v0.7.0 — **no required action**. No
migrations, no env-var changes, no breaking surface, no public-method
signature changes. Existing v0.7 deployments can pull v0.8 in and ship
without code or config edits.

The new surfaces are all opt-in:

### Optional adoption

#### `php artisan swarm:install` (#85)

New orchestrator command — the recommended single entry point for any
fresh `composer require builtbyberry/laravel-swarm`. Walks the operator
through publishing `config/swarm.php`, seeding the canonical Swarm
`.env` keys with safe defaults, choosing the persistence path (runs
migrations on the database driver, or scaffolds
`LaravelSwarm::ignoreMigrations()` into `AppServiceProvider` for
cache-only), warns when `QUEUE_CONNECTION=sync`, and offers to dispatch
each sub-installer in turn. **Nothing to do at upgrade time** — the
manual install flow still works and is preserved in
`docs/advanced-setup.md`. Existing deployments that already have config
published, migrations run, and bindings wired by hand can ignore this
command entirely. New apps and new operators get a faster on-ramp; the
choice is the operator's. Flags for CI use: `--no-interaction`,
`--persistence=database|cache`, `--with-{durable,audit,pulse,examples}`
/ `--without-{...}`, `--force` (re-publish config), `--force-env`
(overwrite an existing `SWARM_PERSISTENCE_DRIVER` value on mismatch
with `--persistence`). See `docs/getting-started.md` for the
walkthrough.

#### Targeted sub-installers (#86, #87, #88, #90)

`swarm:install:durable`, `swarm:install:audit`, `swarm:install:pulse`,
and `swarm:install:examples` can each be invoked on their own at any
time — they are also dispatched from `swarm:install` when the operator
opts in. **Use these to retrofit one Swarm capability into an existing
install** without re-running the base installer. Each uses
sentinel-marker file mutation so re-runs are byte-level no-ops.
Sub-installer-specific flag documentation lives in each
command's Quick Setup section in the per-feature docs
(`docs/durable-execution.md`, `docs/audit-evidence-contract.md`,
`docs/pulse.md`, `docs/examples.md`).

#### `LogChannelSwarmAuditSink` (#102)

New concrete `SwarmAuditSink` implementation that writes every audit
record as a structured log entry (`swarm.audit.<category>`) to the
configured Laravel log channel (defaults to `audit`, falls back to the
default channel when `audit` is not configured). **The zero-config
dev/staging sink** the `swarm:install:audit --sink=readable` installer
binds. Implements only `SwarmAuditSink`, not `ReadableSwarmAuditSink`
(log channels are not queryable; `swarm:trace` degrades gracefully when
this sink is bound). Production deployments should still ship a bounded
backend (database, queue, SIEM export). **Nothing to do at upgrade
time** — existing custom audit sinks remain valid.

#### `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` (#89)

New abstract base for runnable provider-free agents. Subclasses
implement `instructions(): string` and `reply(string $prompt): string`;
the shipped `prompt()` wraps the reply in a standard `AgentResponse`.
The starter examples extend it, and `make:swarm:agent` scaffolds it as
the default agent shape. `stream()`, `queue()`, and the broadcast
helpers throw with a clear "use a `Promptable` agent + `Agent::fake()`"
message — this base is for end-to-end shape demos and smoke tests, not
the test-double surface. **Nothing to do at upgrade time**; adopt as
needed for demos or starter scaffolds.

#### `make:swarm:swarm` and `make:swarm:agent` (#91)

The v0.7-era single `make:swarm` generator was split into two dedicated
commands so the generator surface matches the two kinds of class an app
actually writes. `make:swarm:swarm <Name>` scaffolds a swarm class
(with `--topology=sequential|parallel|hierarchical|static-hierarchical`);
`make:swarm:agent <Name>` scaffolds an agent class extending
`ScriptedAgent`. Both stubs are visually consistent with the starter
examples in `stubs/examples/`. **The legacy `make:swarm` keeps
working** — it is now a deprecated alias that delegates to
`make:swarm:swarm` and prints a deprecation notice to stderr. Existing
scripts, tutorials, and docs continue to function; migrate at your own
pace. The alias is slated for removal in a future major release.

#### Starter example pack (#89, #90)

Three curated runnable starter examples ship under `stubs/examples/` and
are copied into the host app via `swarm:install:examples`:
`sequential-blog-pipeline` (the "hello world"),
`parallel-research-fanout` (concurrent agents + result merge), and
`durable-approval-workflow` (durable mode + `RoutesDurableWaits`
checkpoint resumed by a `policy_decision` signal). Each runs end-to-end
with no API key — they use `ScriptedAgent` for the agent layer with
TODO markers showing where to plug in a real provider. **Nothing to do
at upgrade time**; opt in via `php artisan swarm:install:examples
--all` whenever it's useful for onboarding a new developer or
demonstrating the shape to a stakeholder.

### What did not change

- The persisted schema. No migrations land in this release.
- The `SwarmAuditSink`, `ReadableSwarmAuditSink`, `SwarmAuditSigner`,
  `ActorResolver`, `CapturePolicy`, `SinkFailureHandler`, or
  `AuditOutbox` contracts.
- The runner contract surface (`Swarm`, `Runnable`,
  `dispatchDurable()`, the broadcast helpers, the topology enum).
- The Pulse recorder + dashboard card surface (`SwarmRuns`,
  `SwarmStepDurations`, the three livewire cards).
- `swarm:health`, `swarm:trace`, `swarm:audit:status`,
  `swarm:audit:reconcile`, `swarm:relay`, `swarm:recover`, `swarm:prune`
  command signatures and exit codes.

## Upgrading to v0.7.0

v0.7.0 is purely additive on top of v0.6.0 — **no required action**. No
migrations, no env-var changes, no breaking surface, no public-method
signature changes. Existing v0.6 deployments can pull v0.7 in and ship
without code or config edits.

The new surfaces are all opt-in:

### Optional adoption

#### `swarm:trace <run_id>` (#44)

New read-only forensic CLI that reconstructs a single run's audit chain
across history, outbox, and the bound sink. **Nothing to do at upgrade
time** — the command works against any existing v0.6 deployment, with
graceful degradation when the bound sink doesn't expose a read API or
the outbox is unavailable (cache persistence). See
`docs/audit-evidence-contract.md` "Reading the Audit Chain" for the
operator walkthrough and the **Security and retention** subsection
covering how the command unseals encrypted-at-rest data on output.

#### `ReadableSwarmAuditSink` contract (#44)

Optional extension of `SwarmAuditSink` that adds `forRun(string $runId):
iterable<array>` so the sink can participate in `swarm:trace`. **You
only need to implement this if** you want your custom sink's records to
appear in trace timelines. The shipped `NoOpSwarmAuditSink` does not
implement it and existing custom sinks remain valid — `swarm:trace`
degrades to outbox + history only when the bound sink doesn't
implement the contract. See `src/Contracts/ReadableSwarmAuditSink.php`
for the documented return shape (`category`, `occurred_at`, optional
`run_id`, optional `payload`) and `docs/audit-evidence-contract.md`
"Reading the Audit Chain" for a worked database-sink example.

#### `RunContext::fake()` and `SwarmFake` audit intercepts (#42, #43)

New test-time additions to the testing surface. `RunContext::fake()` is
a named constructor that returns a context with sensible test defaults;
`SwarmFake::interceptCapturePolicy()` / `interceptSinkFailureHandler()`
/ `interceptSwarmAuditSigner()` install recording decorators around the
v0.4 audit-extension contracts so tests can assert against capture
decisions, failure routing, and signing without manually wiring the
dispatcher chain. **No action needed at upgrade time**; see
`docs/testing.md` for the new sections.

### What did not change

- The audit evidence envelope contract (`schema_version` stays at
  `"2"`; the new regression coverage in #76 locks the contract in
  place without modifying any production emit path).
- Every existing public-surface contract, method signature, attribute,
  config key, and Artisan command.
- Persistence migrations and durable-runtime wiring.

## Upgrading to v0.6.0

v0.6.0 is purely additive on top of v0.5.0 — no migrations, no env-var
changes, no breaking surface. Existing v0.5 deployments can adopt v0.6
surfaces incrementally.

### High Impact Changes

#### Custom `SwarmAuditSink` allowlists must add `command.audit_reconcile`

v0.6.0 introduces a new audit category, `command.audit_reconcile`,
emitted by the new `swarm:audit:reconcile` Artisan command on every
operator triage action against the audit outbox (requeue, dismiss, and
show). The category is enumerated in
`docs/audit-evidence-contract.md` and behaves like every other
`command.*` category — it carries `schema_version: "2"` and the
standard `metadata.actor` envelope slot.

**Required if you implement a custom `SwarmAuditSink` that allowlists
categories or schema-validates payloads:** add `command.audit_reconcile`
to your allowlist. Without this, operator triage actions are silently
dropped — exactly the records that exist to survive scrutiny. A sink
that rejects unknown categories will also reject these records, so
either accept the new category or extend the strict-validation switch
to cover it.

The frozen payload fields, in order:

- `action` (string, one of `requeue`, `dismiss`, `show`)
- `target_id` (int) — the `swarm_audit_outbox` row id
- `target_category` (string) — the category of the original failed emit
- `target_run_id` (string&#124;null) — the run id from the original payload, if present
- `prior_attempts` (int) — the outbox row's attempt count at the time of the action
- `reason` (string) — required on `dismiss`, optional on `requeue`, omitted on `show`
- `target_created_at` (ISO 8601 string) — when the outbox row was first written
- `target_age_seconds` (int) — age at the time of the action
- `target_payload_digest` (sha256 hex string) — **present on `dismiss` only**; a sha256 over the stored payload bytes so an auditor can verify the deletion against a forensic backup without unsealing

Sinks that ignore unknown categories (or fall through to a generic
`emit($category, $payload)` path) need no changes.

### Medium Impact Changes

#### Operator adoption — optional v0.6 surfaces

The new dashboard card and the two new Artisan commands are
opt-in operator surfaces. Adopt them where they fit; nothing about
v0.5 stops working if you defer.

- **Pulse card.** Register the new card by adding
  `\BuiltByBerry\LaravelSwarm\Pulse\Livewire\AuditOutbox::class` to
  your `pulse.cards` config, or place the `<livewire:swarm.audit-outbox />`
  Blade tag directly in a custom dashboard view. The card renders a
  neutral state with zero DB queries on cache persistence; no further
  configuration is required.
- **`swarm:audit:status`.** Read-only outbox summary; safe to run from
  the CLI or wire into a monitoring scraper via `--json`.
- **`swarm:audit:reconcile`.** Operator-on-demand forensic CLI. Treat
  it like `swarm:recover` — invoke from the CLI when triaging, do not
  schedule it. **Do not** add either of the new commands to the
  scheduler. `swarm:relay` (already scheduled in v0.5) continues to
  drain the audit lane on its existing cadence.

### Low Impact Changes

#### Compliance posture: dismissals are now digest-bound and reads are counted

v0.6 strengthens the chain of custody on operator dismissals via the
new `target_payload_digest` field — a sha256 over the stored payload
bytes that lets an auditor verify a `--dismiss` action against a
forensic backup of the outbox without unsealing. Payload **reads** are
also now counted: `swarm:audit:reconcile --show` emits a
`command.audit_reconcile` record with `action=show` (no payload
contents in the audit record), so operator inspection of a dead-letter
row is itself a tracked event.

Operators with shell access to `swarm:audit:reconcile --show` should
be treated the same as operators with direct database read access —
the audit emit accounts for individual reads but does not authorize
them. Gate shell access accordingly. The `docs/operator-runbook-audit-outbox.md`
"Audit trail of reads" subsection covers the operational expectations.

## Upgrading to v0.5.0

v0.5.0 settles the audit evidence envelope and lands the durability layer
underneath it. The shared `schema_version` bumps from `"1"` to `"2"`, the
legacy top-level `actor` field on `command.*` evidence moves into the
standard `metadata.actor` slot, sink failures are now **queued** by default
(via a new audit outbox) instead of silently swallowed, and `SwarmRunner`
splits into focused collaborators. The `Swarm\Contracts\Agent` and
`Swarm\Contracts\HasStructuredOutput` marker interfaces give swarm its own
stability story over laravel/ai.

### High Impact Changes

#### Audit sink failures are queued by default

`swarm.audit.failure_policy` defaults to `queue` in v0.5 (was `swallow` in
v0.4). When a bound `SwarmAuditSink` throws, the failed evidence record is
now persisted to the new `swarm_audit_outbox` table for retry via
`swarm:relay --type=audit`, rather than discarded. This is the
regulated-safe-by-default posture: a transient sink outage no longer drops
evidence on the floor.

**Required if you're on database persistence and want the new behavior:**

```bash
php artisan migrate
```

The new migration creates `swarm_audit_outbox`. Schedule the relay if you
have not already (the same relay drains both durable and audit lanes):

```php
Schedule::command('swarm:relay')->everyMinute();
```

You can also drain audit only:

```bash
php artisan swarm:relay --type=audit
```

**Opting out:** set `SWARM_AUDIT_FAILURE_POLICY=swallow` (v0.4 behavior),
`=log` (write the failure to the application log, then swallow), or
`=halt` (rethrow as `AuditSinkHaltedException`, which fails the run). Use
`=dead_letter` to persist failures to the outbox without retry.

**On cache persistence:** the outbox is not available with the cache
driver. The dispatcher detects this and falls back to log-and-swallow
automatically, emitting a warning log. No code change is required.

**Encryption at rest:** when `swarm.persistence.encrypt_at_rest` is enabled
(the default for database persistence), the audit outbox seals the
`payload` and `last_error` columns using the same
`SwarmPersistenceCipher` flow as other persistence stores. No
configuration change is required.

**Dead-letter monitoring:** every time a record transitions to the
`dead_letter` status, the package emits `Log::error` with the category,
run_id, attempt count, and final error. For Part 11 / regulated workloads,
treat any dead-letter transition as a compliance signal — it means an
audit event was supposed to land in the sink but never will without
operator intervention. Wire your log aggregator to alert on this message.

**Signer rotation:** records that fail and land in the outbox carry the
signature produced by `SwarmAuditSigner` at the moment of the original
emit attempt. The outbox re-emits the **original signed payload** on
replay — it does not re-sign under a rotated key. Sinks that verify
signatures across a key-rotation window must accept old keys for at least
the duration of the longest expected outbox backlog. See
`docs/audit-evidence-contract.md` "Signer rotation" for details.

**Health visibility:** `swarm:health` (no flag) now runs two audit-outbox
checks by default — staleness (pending rows whose `reserved_at` aged past
2× the relay reservation timeout) and dead-letter count (any
`status='dead_letter'` row triggers a `warning`). For focused incident
investigation, `swarm:health --audit` runs only the audit checks and
skips persistence and durable. Both flags can be combined with `--json`
for machine-readable output to a monitoring stack.

**Dead-letter retention:** `swarm.audit.outbox.dead_letter_retention_days`
(env `SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS`) governs how long
dead-lettered records persist. The default is `null` — preserve
indefinitely — because deleting unreconciled audit evidence before the
operator reviews it is destruction of compliance signal. Set to a
positive integer N to opt into automatic pruning via `swarm:prune` (deletes
dead-letter rows where `last_attempted_at < now - N days`). Pending and
reserved rows are never pruned by this policy; staleness and retry are the
relay's responsibility. `swarm.retention.prevent_prune=true` overrides as
with all other prune behavior.

#### `SinkFailureDecision` gains `Queue` and `DeadLetter` cases

Custom `SinkFailureHandler` implementations may now return:

- `SinkFailureDecision::Queue` — the dispatcher persists the failed record
  to the audit outbox for retry via `swarm:relay --type=audit`.
- `SinkFailureDecision::DeadLetter` — the dispatcher persists the record
  directly to the dead-letter status (no retry).

If your custom handler uses a `match` or `switch` over
`SinkFailureDecision`, add cases for these two new values. PHP throws a
`UnhandledMatchError` if your match expression is exhaustive and these
fall through.

#### Evidence `schema_version` bumps to `"2"`

Every payload emitted through `SwarmAuditDispatcher` and
`SwarmTelemetryDispatcher` carries `schema_version: "2"`. Sinks that branch on
`schema_version` (for example, to validate a payload against a known shape)
must be taught to recognize `"2"`. Sinks that ignore `schema_version` need no
changes.

The bump signals a **shape break on `command.*` evidence only.** Run-level
(`run.*`), step-level (`step.*`), and durable runtime (`durable.*`, `wait.*`,
`signal.*`) evidence shapes are unchanged from v0.4.

**Rolling deploy window.** During a rolling v0.4 → v0.5 deploy, workers
on the old version continue to emit `schema_version: "1"` while workers
on the new version emit `"2"`. Both will land in your sink simultaneously
until the rollout completes. Sinks that pin strictly to a single
`schema_version` value will reject one cohort or the other for the
duration of the window — particularly painful for callers running
`SWARM_AUDIT_FAILURE_POLICY=halt`, where rejections fail runs. Use
tolerant validation (accept both `"1"` and `"2"`) until every worker is
on v0.5, then optionally tighten back to `"2"` only. The
`command.*`-only shape break is the sole behavioral difference within
the envelope schema; every other category is unchanged.

#### `command.*` actor moves to `metadata.actor`

In v0.4, evidence emitted by `swarm:pause`, `swarm:resume`, `swarm:cancel`,
`swarm:recover`, and `swarm:relay` carried a literal top-level field
`'actor' => 'artisan'`. v0.5 removes that field. The actor identity is now
emitted on `metadata.actor` as an `Actor` value object array, consistent with
how every other evidence category exposes actor identity:

```php
// v0.4 command.pause payload (legacy)
[
    'schema_version' => '1',
    'category'       => 'command.pause',
    'run_id'         => '...',
    'actor'          => 'artisan',     // ← top-level literal
    'status'         => 'requested',
    ...
]

// v0.5 command.pause payload
[
    'schema_version' => '2',
    'category'       => 'command.pause',
    'run_id'         => '...',
    'status'         => 'requested',
    'metadata_keys'  => ['actor'],
    'metadata'       => [
        'actor' => [
            'id'       => 'artisan',
            'type'     => 'system',
            'name'     => null,
            'metadata' => [],
        ],
    ],
    ...
]
```

`swarm:prune` evidence, which previously carried no actor identity at all,
now also emits `metadata.actor`. Sinks that explicitly read
`$payload['actor']` on `command.*` evidence must switch to
`$payload['metadata']['actor']['id']` (or `['type']`, `['name']`) and
should treat the legacy top-level field as absent on v0.5.

There is no compatibility shim — the field is removed, not aliased — because
keeping it would mean carrying duplicate-emit code through a minor we already
need to delete before 0.6.

#### `Swarm\Contracts\Agent` and `Swarm\Contracts\HasStructuredOutput` marker contracts

v0.5.0 introduces swarm-owned marker interfaces that wrap the corresponding
contracts from `laravel/ai`:

- `BuiltByBerry\LaravelSwarm\Contracts\Agent` — extends
  `Laravel\Ai\Contracts\Agent`.
- `BuiltByBerry\LaravelSwarm\Contracts\HasStructuredOutput` — extends
  `Laravel\Ai\Contracts\HasStructuredOutput`.

The runtime contract is unchanged: every method already declared by the
vendor interfaces continues to exist. The markers exist so the Swarm public
surface (the `Swarm` contract, hierarchical/parallel runners, route planner,
`SwarmFake`, and the streaming event base class) can advertise types it
controls. This shields applications from churn in `laravel/ai`'s pre-1.0
contracts.

**Migration required for custom agent classes.** Application code that
defines an agent and feeds it into Swarm must implement the new marker
interface:

```php
// v0.4 — vendor contract directly
use Laravel\Ai\Contracts\Agent;

class MyAgent implements Agent { /* ... */ }
```

```php
// v0.5 — swarm-owned marker
use BuiltByBerry\LaravelSwarm\Contracts\Agent;

class MyAgent implements Agent { /* ... */ }
```

Because the swarm marker extends the vendor interface, a class that
implements `BuiltByBerry\LaravelSwarm\Contracts\Agent` continues to satisfy
every place that accepts a `Laravel\Ai\Contracts\Agent` (the vendor APIs and
ecosystem code keep working). The reverse is not true, so agents that only
implement the vendor interface will no longer pass Swarm's hierarchical and
parallel runner type-checks.

Agents that produce structured output (hierarchical coordinators with
`schema()`) should also switch to the swarm-owned
`BuiltByBerry\LaravelSwarm\Contracts\HasStructuredOutput`:

```php
// v0.5
use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Contracts\HasStructuredOutput;

class MyCoordinator implements Agent, HasStructuredOutput { /* ... */ }
```

#### `Swarm\Streaming\StreamEvent` base class

Every `SwarmStream*` event now extends
`BuiltByBerry\LaravelSwarm\Streaming\StreamEvent` instead of
`Laravel\Ai\Streaming\Events\StreamEvent` directly. The swarm-owned base
class still extends the vendor `StreamEvent` so the invocation-id tracking,
`type()` method, and `toArray()` contract behave identically — only the
declared ancestor in the type tree changes. Consumers that type-hint against
`BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent` need no changes.

If you wrote custom listener code that type-hinted against the vendor
`Laravel\Ai\Streaming\Events\StreamEvent` to receive swarm events, that
hint still matches because `Swarm\Streaming\StreamEvent` is-a vendor
`StreamEvent`. New code should depend on the swarm-owned base instead.

#### Vendor data types intentionally remain passthrough

Some Laravel AI types still flow through Swarm unchanged because wrapping
them would advertise a portability story Swarm cannot deliver. These types
are documented as **passthrough** and may evolve with `laravel/ai`:

- `Laravel\Ai\Responses\Data\ToolCall` — embedded on
  `BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall::$toolCall` and
  handled by the internal `captureToolCall` paths in the sequential and
  static-hierarchical stream runners.
- `Laravel\Ai\Responses\Data\ToolResult` — embedded on
  `BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult::$toolResult`
  and handled by the matching `captureToolResult` paths.
- `Laravel\Ai\Responses\AgentResponse` — read inside the
  `MergesAgentUsage` trait when extracting usage from the value returned
  by `Agent::prompt()`.
- `Laravel\Ai\Streaming\Events\TextDelta`, `TextEnd`, `ReasoningDelta`,
  `ReasoningEnd`, `ToolCall`, `ToolResult`, `StreamEnd`, and `Error` —
  yielded as-is from the provider stream into the runner's translation
  layer.
- `Laravel\Ai\FakePendingDispatch` — referenced by
  `BuiltByBerry\LaravelSwarm\Responses\DurableSwarmResponse::syncQueueRouting()`
  to bypass queue-routing reads under `Bus::fake()`.

Treat these as read-only snapshots of the vendor data. Their shapes are not
covered by Swarm's semver guarantees; if `laravel/ai` changes them, Swarm
will surface those changes with the corresponding vendor upgrade.

### Medium Impact Changes

None.

### Low Impact Changes

None.

## Upgrading to v0.4.0

v0.4.0 ships four new contracts that extend the audit and identity surface:
`ActorResolver`, `CapturePolicy`, `SinkFailureHandler`, and `SwarmAuditSigner`.
Each one is bound in the service container with a conservative default that
preserves v0.3 behavior. Applications that do not bind custom implementations
do not need to change any code, but the new defaults and the deprecation of
the boolean capture keys are documented below.

### High Impact Changes

There are no breaking changes in v0.4.0. Every new contract is additive and
ships a default binding that matches v0.3 behavior.

### Medium Impact Changes

#### Actor binding for swarm runs

A new `BuiltByBerry\LaravelSwarm\Audit\Actor` value object and
`BuiltByBerry\LaravelSwarm\Contracts\ActorResolver` contract describe who
initiated a swarm run. The container binds `ActorResolver` to
`DefaultActorResolver` out of the box, which reads the actor from the active
context and falls back to the authenticated user when one is available.

Set the actor explicitly through `RunContext::withActor()`:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

$context = RunContext::make()->withActor(
    new Actor(id: (string) $user->getKey(), type: 'user', label: $user->email),
);
```

You may also bind an actor onto the active context before dispatch:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Facades\Context;

Context::add('swarm:actor', new Actor(
    id: (string) auth()->id(),
    type: 'user',
    label: auth()->user()?->email,
));
```

To enforce that every run carries an actor, set
`swarm.audit.actor.required` to `true` (env `SWARM_AUDIT_ACTOR_REQUIRED`,
default `false`). When the flag is on and resolution yields `null`, the
runner throws `BuiltByBerry\LaravelSwarm\Exceptions\MissingActorException`
at dispatch instead of starting a run without identity. The reserved
metadata key `actor` always flows through to sinks regardless of the
metadata allowlist.

Regulated callers (21 CFR Part 11, SOC 2, and similar audit regimes) should
set `SWARM_AUDIT_ACTOR_REQUIRED=true` and bind the actor explicitly through
`Context::add('swarm:actor', ...)` or `RunContext::withActor()` before each
dispatch. The default resolver is a convenience for application code, not
an attestation that an actor was present.

To bind a custom resolver, replace the default binding in a service
provider:

```php
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;

public function register(): void
{
    $this->app->bind(ActorResolver::class, MyActorResolver::class);
}
```

#### Capture policy contract supersedes the boolean capture keys

The `BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy` contract replaces the
`swarm.capture.*` boolean keys with per-field decisions. The container binds
`CapturePolicy` to `BooleanCapturePolicy` by default, which reads the existing
boolean keys and returns `CaptureDecision::Full` or `CaptureDecision::Redact`
for each field. No action is required for applications that only use the
booleans.

The boolean capture keys are **deprecated** in v0.4 and are scheduled for
removal in v0.5. They continue to work through `BooleanCapturePolicy` for the
duration of the v0.4 line.

> **Historical note:** through the v0.4–v0.11 lines, `CaptureDecision::Skip`
> behaved identically to `Redact` at the field level (the value was persisted as
> `[redacted]`). As of **v0.12.0**, `Skip` is true omission on the evidence
> surfaces — the field is absent from persisted/emitted payloads and `NULL` on
> the nullable evidence columns. The operational active-context store
> (`swarm_contexts.input`) retains the input for durable resume. See
> [Upgrading to v0.12.0](#upgrading-to-v0120).

A minimal custom policy:

```php
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;

class TenantCapturePolicy implements CapturePolicy
{
    public function inputs(EvidenceEnvelope $envelope): CaptureDecision
    {
        return $envelope->tenantId() === 'regulated'
            ? CaptureDecision::Redact
            : CaptureDecision::Full;
    }

    public function outputs(EvidenceEnvelope $envelope): CaptureDecision { /* ... */ }
    public function artifacts(EvidenceEnvelope $envelope): CaptureDecision { /* ... */ }
    public function activeContext(EvidenceEnvelope $envelope): CaptureDecision { /* ... */ }
}
```

Bind it in a service provider:

```php
$this->app->bind(CapturePolicy::class, TenantCapturePolicy::class);
```

#### Sink failure handler contract and `halt` failure policy

The `BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler` contract makes
the audit dispatcher's reaction to sink failures pluggable. The container
binds it to `ConfiguredSinkFailureHandler` by default, which reads the
existing `swarm.audit.failure_policy` config value.

`failure_policy` accepts a new third value, `'halt'`, alongside the existing
`'swallow'` and `'log'`. When a sink throws under `halt`, the dispatcher
raises `BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException`, which
implements the `HaltsSwarmExecution` marker interface so callers can
distinguish it from generic sink errors.

The default value of `swarm.audit.failure_policy` remains `'swallow'` in
v0.4. The flip to a stronger default — currently planned as `'queue'` —
waits for the audit outbox work in v0.5. Regulated workloads that require
audit-or-abort behavior should set the value to `'halt'` today.

The dispatcher retry loop is capped at `MAX_HANDLER_ITERATIONS = 5` so a
handler that always returns `RetryInline` cannot loop indefinitely.

A minimal custom handler:

```php
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use Throwable;

class TenantSinkFailureHandler implements SinkFailureHandler
{
    public function handle(EvidenceEnvelope $envelope, Throwable $error): SinkFailureDecision
    {
        return $envelope->tenantId() === 'regulated'
            ? SinkFailureDecision::Halt
            : SinkFailureDecision::Swallow;
    }
}
```

Bind it in a service provider:

```php
$this->app->bind(SinkFailureHandler::class, TenantSinkFailureHandler::class);
```

### Low Impact Changes

#### Audit signing slot

The `BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner` contract adds a
signing slot inside the audit dispatcher. There is no default binding, so
v0.3 behavior — unsigned envelopes — is preserved. When a signer is bound,
it is invoked after envelope enrichment and before sink emit.

Implementations must not mutate or remove existing envelope keys; they may
only add signature fields. Signing failures route through the same
`SinkFailureHandler` as sink failures, so strict regulated workloads should
combine a signer with `failure_policy=halt`.

A minimal custom signer:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;

class HmacAuditSigner implements SwarmAuditSigner
{
    public function __construct(private readonly string $key) {}

    public function sign(EvidenceEnvelope $envelope): EvidenceEnvelope
    {
        $signature = hash_hmac('sha256', $envelope->canonicalPayload(), $this->key);

        return $envelope->withSignature(['alg' => 'HS256', 'value' => $signature]);
    }
}
```

Bind it in a service provider:

```php
$this->app->bind(SwarmAuditSigner::class, fn () => new HmacAuditSigner(config('app.audit_key')));
```

### Composer

Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
php artisan migrate
```

If your application caches configuration during deploys, rebuild the cache
after publishing or editing config:

```bash
php artisan config:cache
```

## Upgrading to v0.3.5

No migrations. No breaking changes. Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
```

### `swarm:health --durable` now fails on missing `SWARM_CAPTURE_ACTIVE_CONTEXT`

`swarm:health --durable` now reports a `failed` status (and exits `1`) when
`SWARM_CAPTURE_ACTIVE_CONTEXT` is not enabled. The runner has always thrown
`SwarmException` at dispatch when this env is missing for queued or durable
execution; the new check surfaces the misconfiguration at preflight rather
than at the first live run.

Operators whose deployments already rely on `SWARM_CAPTURE_ACTIVE_CONTEXT=true`
need take no action. CI/CD pipelines that previously passed `swarm:health
--durable` in an environment where the env was unset but durable execution was
never actually exercised may newly exit `1` on the same command. Set
`SWARM_CAPTURE_ACTIVE_CONTEXT=true` in any environment that runs queued or
durable swarms — the value was already required at runtime; the check has only
become more honest about it.

## Upgrading to v0.3.4

No migrations. No breaking changes. Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
```

### `make:swarm` now prompts for topology on TTY

`make:swarm` now prompts interactively for topology when run from a TTY without
`--topology`. Existing scripts and CI invocations that piped a topology or
relied on the previous silent default of `sequential` are unaffected:
non-interactive callers (`Artisan::call()`, piped stdin) continue to default to
`sequential`. If you want to preserve the previous one-shot behavior in
interactive shells, pass `--topology=sequential` explicitly.

### Documentation site

The full documentation now lives at https://swarm.builtbyberry.com. The
in-repo `docs/` directory remains the offline mirror. No application action is
required — Composer metadata updates automatically when you upgrade.

## Upgrading to v0.3.3

No migrations. No breaking changes. Run the standard upgrade flow:

```bash
composer update builtbyberry/laravel-swarm
php artisan config:clear
```

### New config key: `static_hierarchical.stream_parallel_branches`

A new key controls how parallel groups behave when a `StaticHierarchical` swarm is
streamed. The package default is `concurrent`.

If you have **published `config/swarm.php`**, add the new block so the key is
configurable from your application config:

```php
'static_hierarchical' => [
    'stream_parallel_branches' => env('SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES', 'concurrent'),
],
```

Applications that have not published the config are unaffected — the missing key falls
back to the package default automatically.

See [Static Hierarchical Topology — Streaming](docs/static-hierarchical-topology.md#streaming)
for valid values (`concurrent`, `sequential`) and their behavior.

## Upgrading to v0.3.0

### Transactional outbox and `swarm:relay` scheduler entry

Run `php artisan migrate` after updating the package. Two migrations are added:

- `2026_05_11_000001_create_swarm_durable_outbox_table` — creates `swarm_durable_outbox`
- `2026_05_11_000002_optimize_swarm_durable_outbox_indexes` — replaces the initial composite
  index with two targeted drain indexes (plus a PostgreSQL partial index)

**Action required for all durable swarm users:** add `swarm:relay` to your scheduler. Without
it, durable swarms will stall after every step because the outbox is never drained.

```php
// app/Console/Kernel.php (or routes/console.php in Laravel 11+)
Schedule::command('swarm:relay')->everyMinute();
```

If you also schedule `swarm:recover`, the relay should run at the same or higher frequency.
The relay is a fast, bounded operation (default limit 100 rows per run) and is safe to run
every minute on any database.

To verify the relay is working after deploying, run:

```bash
php artisan swarm:health --durable
```

The **Outbox relay** check warns when unclaimed rows are older than 2× the reservation timeout.
If you see that warning in a healthy environment, it means `swarm:relay` is not scheduled.

**`DurableOutbox::drain()` return type changed**

`DurableOutbox::drain()` now returns `DrainResult` (namespace
`BuiltByBerry\LaravelSwarm\Responses\DrainResult`) instead of `int`. The bundled
`DatabaseDurableOutbox` implementation is updated automatically.

If your application provides a **custom `DurableOutbox` implementation**, update its
`drain()` method signature and return a `new DrainResult(dispatched: $n, skipped: $m)`
instance. The `dispatched` count is entries successfully dispatched to a queue driver;
`skipped` is permanently invalid entries deleted without dispatch.

Applications that only *inject* `DurableOutbox` need no changes.

### `run_id` foreign-key constraints

Run `php artisan migrate` after updating the package. Migration
`2026_05_04_000001_add_run_id_foreign_keys_to_swarm_tables` adds
`ON DELETE CASCADE` foreign keys from every child table to its parent:

- `swarm_contexts`, `swarm_artifacts`, `swarm_run_steps`, `swarm_stream_events`
  → `swarm_run_histories`
- `swarm_durable_branches`, `swarm_durable_node_states`,
  `swarm_durable_run_state`, `swarm_durable_node_outputs`,
  `swarm_durable_signals`, `swarm_durable_waits`, `swarm_durable_labels`,
  `swarm_durable_details`, `swarm_durable_progress` → `swarm_durable_runs`
- `swarm_durable_child_runs.parent_run_id` → `swarm_durable_runs` (CASCADE)
- `swarm_durable_runs.parent_run_id` self-referential (SET NULL)
- `swarm_durable_waits.signal_id` → `swarm_durable_signals` (SET NULL)
- `swarm_durable_webhook_idempotency.run_id` → `swarm_durable_runs` (SET NULL)
- `swarm_durable_child_runs.child_run_id` — **no FK** (independent lifecycle)

Before applying this migration in an existing install, take a database backup
and check each child table for orphaned rows whose parent `run_id` no longer
exists. Foreign-key creation will fail on those rows. Export, delete, or
reconcile orphaned operational records before running `php artisan migrate`.

For general information about the FK contract and prune order, see
[docs/maintenance.md § Foreign-key constraints and prune order](docs/maintenance.md#foreign-key-constraints-and-prune-order).

**Custom table names:** If you have published the package migrations and renamed
any table, run the same orphan checks against your renamed tables and add the
equivalent FK constraints to your published copies. Without them, orphan rows
can accumulate once the parent table is pruned.

### Durable runtime schema split

Run `php artisan migrate` after updating the package. The migration creates
`swarm_durable_node_states` and `swarm_durable_run_state`, migrates existing
`route_plan`, `node_states`, `failure`, and `retry_policy` values from
`swarm_durable_runs`, then drops those columns. `DurableRunStore::find()` and
related inspection APIs keep the same PHP array shape; only the physical layout
changes.

If you override table names, publish `config/swarm.php` and set
`SWARM_DURABLE_NODE_STATES_TABLE` / `SWARM_DURABLE_RUN_STATE_TABLE` when you rename
the new tables.

### `DurableSwarmManager` surface trim

`DurableSwarmManager` no longer exposes `create()`, `dispatchStepJob()`, or
`dispatchBranchJob()`. Run row creation happens through `DurableRunStore` during
the normal start path, and durable step/branch jobs are built by
`BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJobDispatcher`. Typical
application code should keep using `dispatchDurable()` and operator methods on
the manager; see
[docs/durable-runtime-architecture.md](docs/durable-runtime-architecture.md) for
the full map and testing notes.
