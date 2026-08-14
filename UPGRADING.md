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

As of **v0.23.0** the package declares `"minimum-stability": "stable"` with
`"prefer-stable": true` in [`composer.json`](composer.json). `laravel/ai` ships
stable tags on the 0.9 line, so **applications need no special stability
settings** to install Swarm.

Before v0.23.0 this section instructed applications to propagate
`"minimum-stability": "dev"`. That is no longer necessary. If you added those
keys solely to install this package, you can remove them — they loosen the
resolution floor for your entire dependency tree, not just for Swarm.

Note that `minimum-stability` is read by Composer from the **root** package
only; a dependency's value is ignored during your application's resolve. So this
change is hygiene for Swarm's own resolution, and the setting that governs your
application is always your own.

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

`laravel/ai` is required in the **^0.10** range as of v0.23.1 (support for 0.9
was dropped; 0.8 was dropped in v0.20.0; 0.6 / 0.7 earlier, in v0.13.0) and is
**pre-1.0**. Public
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
composer require laravel/ai:0.9.0
```

That pins your application’s dependency resolution. It does not change the semver
range Laravel Swarm declares for Packagist.

As of v0.23.0 this package’s `composer.json` uses `"minimum-stability": "stable"`
with `"prefer-stable": true`. Your application needs no special Composer
stability settings to install Swarm — see
[Composer minimum-stability](#composer-minimum-stability).

## Upgrading to v0.23.1

**`laravel/ai` moves to `^0.10.3`.** laravel/ai 0.10 widens the `Agent` contract's
prompt-family methods so their first parameter accepts an approval continuation as
well as a prompt string: `prompt()`, `stream()`, `queue()`, `broadcast()`,
`broadcastNow()`, and `broadcastOnQueue()` now take
`Laravel\Ai\Approvals\Decisions|string` as their first argument (the
human-in-the-loop approval-resume path).

Swarm's `BuiltByBerry\LaravelSwarm\Contracts\Agent` extends the vendor contract, so
the change flows through. **If your application implements the `Agent` contract
directly, or overrides any of those six methods on a `ScriptedAgent` subclass,
widen the first parameter to `Decisions|string` to match.** A plain string prompt
behaves exactly as before; the `Decisions` case is only reached on the approval
continuation path, which Swarm does not itself model in this release. Agents that
subclass `ScriptedAgent` and override only `reply(string): string` need no change.

## Upgrading to v0.23.0

**Plain `laravel/ai` agents now work with Swarm unchanged.** Swarm type-hints
`Laravel\Ai\Contracts\Agent` at every public entry point and runner gate. This
reverses the v0.5.0 marker-contract migration: implementing
`BuiltByBerry\LaravelSwarm\Contracts\Agent` is no longer necessary.

```php
// Now accepted everywhere — no swarm-specific interface needed.
use Laravel\Ai\Contracts\Agent;

class CompetitorResearcher implements Agent
{
    use \Laravel\Ai\Promptable;

    public function instructions(): string
    {
        return 'Compare competitors.';
    }
}

Swarm::agent(new CompetitorResearcher)->prompt('...');
```

**No action required for existing agents.** `BuiltByBerry\LaravelSwarm\Contracts\Agent`
remains as a `@deprecated` alias and still extends the vendor contract, so
classes written against it since v0.5.0 keep working. It is slated for removal
in v1.0; prefer the vendor contract in new code.

### Breaking: custom `MemoryPropagationPolicy` implementations

If you implement `BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy`,
change the third parameter's type-hint:

```php
-use BuiltByBerry\LaravelSwarm\Contracts\Agent;
+use Laravel\Ai\Contracts\Agent;

 public function present(array $candidateEntries, RunContext $context, ?Agent $agent): array
```

This is required, not optional. PHP allows an implementation to *widen* a
parameter type but never to *narrow* it, so a policy still type-hinting the
swarm marker is now narrower than the interface and will raise a fatal error
when the class loads. The interface had to widen for vendor agents to reach a
policy at all — leaving it narrow would have thrown a `TypeError` at runtime
instead.

No other contract changed, and no application-facing behavior changed.

## Upgrading to v0.22.0

**No required action — additive, developer-experience release.** Every addition is new public surface you can adopt at your own pace. One behavior note before the list: `swarm:health` gains new checks that can exit non-zero (see below) — worth knowing if you gate CI on it.

- **Class-free entry points.** `Swarm::agent($agent)` runs a single agent through the full governed pipeline, and `Swarm::sequential()` / `Swarm::parallel()` / `Swarm::hierarchical($coordinator, [$workers])` run inline multi-agent swarms — all without authoring a `Swarm` class, all with the same audit, guardrails, capture, telemetry, and encrypt-at-rest as a class-based swarm. The class-free builders run **in-process** (`prompt`/`run`/`stream`/`broadcast`/`broadcastNow`); for **queued or durable** execution, author a one-agent `Swarm` class (`make:swarm:swarm YourSwarm`) *(corrected in v0.23.0 — this line originally named a one-agent flag on that command, which never existed)* — a background run is re-resolved from the container by class on the worker, which an ad-hoc swarm can't provide. See [Execution Modes](docs/execution-modes.md#single-agent-swarmagent) and the [Cookbook](docs/cookbook.md).
- **Testing.** `SwarmFake::interceptSwarmAuditSink()` returns a recording sink with `assertAuditChain()`, `assertEmittedAudit()`, `assertNotEmittedAudit()`, and `assertStepCount()`. See [Testing](docs/testing.md#use-swarmfake-intercepts-for-the-audit-contracts-v07).
- **`swarm:health`** gained governed-by-default checks (guardrails resolvable, audit sink reachable, capture policy sane), included in `--json`. **Behavior note:** the guardrail and capture-policy checks report `failed` (command exits non-zero) when a configured `swarm.guardrails.*` ref or the bound `CapturePolicy` cannot be resolved from the container — a *new* failure condition on this command. If you gate CI on `swarm:health` and have a broken binding, it will now surface here (it would have thrown mid-run regardless). The default `NoOpSwarmAuditSink` is reported as a `note`, not a failure. See [Maintenance](docs/maintenance.md).
- **`make:swarm`** is now an interactive front door (single-agent vs. multi-agent, topology prompts) with a `--single` scaffold; non-interactive/`--no-interaction` usage is unchanged. See [Generators](docs/generators.md).
- **Laravel Boost.** The package ships Boost AI guidelines and a `swarm-development` skill; consuming apps pick them up via `php artisan boost:install`.

No migration, config, or schema change. `Swarm::run()`, `prompt()`, and class-based swarms are untouched. See the [CHANGELOG](CHANGELOG.md) for the full list.

## Upgrading to v0.20.0

**Required action only if your application pins `laravel/ai` below 0.9.** This release raises Swarm's `laravel/ai` requirement from `^0.8` to `^0.9` (v0.9.0); support for the 0.8 line is dropped. Swarm's own source is unchanged — the upgrade is verified against the full test suite and PHPStan level 7 — but Composer will now resolve `laravel/ai` to 0.9.x, so treat it as an integration-test event:

1. Run `composer update laravel/ai --with-all-dependencies`.
2. Run your automated suite plus swarm-heavy smoke paths (queued, streamed, and durable execution).
3. If you use structured-output agents, note that laravel/ai v0.9 uses native Anthropic structured outputs by default and strips markdown code fences before decoding; verify your structured-routing paths once against a live provider.

None of laravel/ai's own breaking changes affect Swarm's integration surface: the removed `TextGateway` contract is not referenced by Swarm, and the Anthropic default-model change (now Claude Sonnet 5) is not pinned or asserted anywhere in Swarm. See the [CHANGELOG](CHANGELOG.md#v0200---2026-07-13) for details.

## Upgrading to v0.19.0

**No required action — additive.** This release adds three public, read-only display seams for companion packages and external readers — `InspectsDurableRuns` (durable-run inspection), `ReadableRunHistoryStore` (run + step history), and `ReadableAuditOutbox` (audit-outbox health) — plus a `SwarmPersistenceCipher::openForDisplay()` helper. They are new interfaces, container-bound alongside the existing stores; no existing contract is widened, and there is no migration, config, or schema change. Every operational read (durable resume, guardrail, `RunHistoryStore::find()`, `AuditOutbox::drain()`) is untouched and still decrypts strictly / fails loud. If you are building an observability surface, bind these contracts instead of the `@internal` cipher or manager; otherwise nothing changes. See the [CHANGELOG](CHANGELOG.md#v0190---2026-07-08) and [Public Surface](docs/public-surface.md#read-only-inspection-contracts-v0190) for details.

## Upgrading to v0.18.0

**No required action — additive.** This release adds the `make:swarm:blueprint` generator and four new curated blueprint trees (`triage`, `extraction`, `memory`, `streaming`) to the starter corpus. It is purely additive — a new Artisan command plus new stub files, with no migration, config, schema, or breaking API change. `swarm:install:examples`' behavior and file output are unchanged (it now also skips the package-side `blueprint.json` metadata alongside `README.md`). If you want the new scaffolder, run `php artisan make:swarm:blueprint <Name> --template=<slug>`; otherwise nothing changes. See the [CHANGELOG](CHANGELOG.md#v0180---2026-07-07) and [Generators](docs/generators.md#make-swarm-blueprint) for details.

## Upgrading to v0.17.4

**No required action — test-only.** This release adds behavior coverage for three public durable-routing capability seams — `ConfiguresDurableRetries`, `RoutesDurableWaits`, and `RoutesDurableBranches` — ahead of the 1.0 surface freeze. No code, config, or schema changes; the contracts themselves are unchanged. See the [CHANGELOG](CHANGELOG.md#v0174---2026-07-06) for details.

## Upgrading to v0.17.3

**No required action — documentation only.** This release restructures the README for scannability (adds a table of contents and groups the configuration-key reference by concern), documents the `swarm:install:memory` sub-installer and its `--with-memory` flag, fixes a non-compiling `#[DurableStreaming]` code example (`Runnable` is a trait, applied via `implements Swarm { use Runnable; }`), and de-stales `AGENTS.md`'s Pulse references after the v0.17.1 companion-package extraction. No code, config, or schema changes. See the [CHANGELOG](CHANGELOG.md#v0173---2026-07-06) for details.

## Upgrading to v0.17.2

**No required action — documentation only.** `v0.17.0` was tagged at the wrong commit and is identical to `v0.16.1`; it must not be used. The changes below shipped in `v0.17.1`, which remains a correct, valid release — this version only fixes docs that said "v0.17.0" for them and adds this note. See the [CHANGELOG](CHANGELOG.md#v0172---2026-07-06) for what happened.

## Upgrading to v0.17.1

**`CausalLogStore` and `ColdArchiveDriver` are now public contracts (#349) — no required action.** No behavior change, no config change, no migration. If you want to implement a custom persistence backend for the streaming substrate's hot/cold tiering, see the new [Streaming Substrate Driver Guide](docs/streaming-substrate-driver-guide.md) for what's actually pluggable (the read/query seam) and what isn't yet (compaction, `#[DurableStreaming]` per-node streaming both stay coupled to the concrete database implementations).

**BREAKING: Pulse integration extracted to a companion package (#351) — required action only if you use Pulse.** If you never installed `laravel/pulse` alongside Swarm, skip this. If you did:

1. Require the new package: `composer require builtbyberry/laravel-swarm-pulse`.
2. Update any imports from `BuiltByBerry\LaravelSwarm\Pulse\*` to `BuiltByBerry\LaravelSwarmPulse\*` — the classes are otherwise unchanged (same recorder logic, same card behavior, same `config/pulse.php` keys, same `swarm.pulse.memory.sample_rate` config knob still read from `config/swarm.php`).
3. If you dispatch `swarm:install` with `--with-pulse` or `--without-pulse` in scripts or CI, remove those flags — the base installer no longer knows about Pulse. Run `php artisan swarm:install:pulse` directly instead (still the same command, now shipped by the companion package).
4. Re-run `php artisan swarm:install:pulse` (or `--force` if the managed blocks are already present) to confirm the card/recorder registration still resolves correctly from the new package.

See [Pulse](docs/pulse.md) for the full install flow.

**Shared `swarm:install*` test harness extracted to `builtbyberry/laravel-swarm-installer-testkit` (#355) — no required action.** Dev-only change to this repo's own test suite (`require-dev`), with no public API or runtime behavior change. Only relevant if you were extending or importing `BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase` directly from outside this repo (not a supported pattern — `tests/` is `autoload-dev`, never part of the public surface); that base class now lives in the new package under `BuiltByBerry\LaravelSwarmInstallerTestkit\InstallerTestCase`.

## Upgrading to v0.16.1

**No required action.** v0.16.1 is a core hardening pass with no migration, no config change, and no breaking API — `swarm:compact` discovery is now a bounded SQL query instead of an unbounded in-memory pluck (#339), `DatabaseAuditOutbox::drain()` batches its retry/dead-letter writes with a per-row fallback (no observable change to `AuditDrainResult`'s shape or the audit trail), and two documentation lines (`AGENTS.md`'s `laravel/ai` version, `CONTRIBUTING.md`'s hook setup step) were corrected.

## Upgrading to v0.16.0

**No required action for most applications.** v0.16.0 is additive and backward-compatible — it promotes a public operator control contract and finalizes several audit and relay surfaces. Notes if any apply to you:

- **Prefer the new `SwarmOperator` contract for programmatic control.** To pause, resume, cancel, signal, or recover durable runs from application code, resolve `BuiltByBerry\LaravelSwarm\Contracts\SwarmOperator` (`app(SwarmOperator::class)`) instead of reaching into `DurableSwarmManager`, which stays `@internal`. The contract is control-only (reads stay on `SwarmHistory` / `RunHistoryStore`), authorization-agnostic (gate the call in your own app), and fails loud on an unknown run. See [docs/durable-execution.md](docs/durable-execution.md#operator-control-contract).
- **`DurableSwarmResponse::pause()`, `resume()`, and `cancel()` return result objects, not `bool`.** They previously returned `true`-or-throw. They now return `DurablePauseResult`, `DurableResumeResult`, and `DurableCancelResult`, which report the *effective* transition (`paused` vs `pause_scheduled`, `cancelled` vs `cancel_scheduled`, `resumed` vs `waiting`). If you assigned the return to a `bool`-typed variable or asserted `=== true`, update it — read `->status` / `->isImmediate()` instead. Most callers ignored the return and need no change; the verbs still throw on an invalid transition exactly as before.
- **`signature_key_id` on signed evidence + `IdentifiesSigningKey` (#49) — no required action; additive and opt-in.** Signed records now carry a `signature_key_id` naming the key that produced the signature, but only when your `SwarmAuditSigner` *also* implements the new opt-in `BuiltByBerry\LaravelSwarm\Contracts\IdentifiesSigningKey` interface (`keyId(): ?string`). Existing signers that implement only `sign()` are unaffected — no field is stamped. To adopt it, implement `IdentifiesSigningKey` and return a **non-secret** key identifier (HMAC key id, cert fingerprint, key-version label); sinks should treat the field as a routing *hint* and retain a try-all-keys fallback. See [docs/audit-evidence-contract.md](docs/audit-evidence-contract.md#exposing-the-key-id).
- **Tolerant `schema_version` verifier — `SinkEnvelopeValidator` (#50) — no required action; additive and opt-in.** A new sink-side helper (`BuiltByBerry\LaravelSwarm\Audit\SinkEnvelopeValidator`) manages the accepted-`schema_version` set for you across rolling deploys. The dispatcher does not consult it and strict-version sinks are unaffected; adopt it only if you want the package to own the supported-versions list. See [docs/audit-evidence-contract.md](docs/audit-evidence-contract.md#versioning).
- **The `@internal` promotion survey (#52) promoted nothing new — no action.** The pre-1.0 audit of `@internal` markers (`docs/internal-audit-1.0.md`) concluded the public extension surface is already correctly drawn. If you were depending on `RunAuditEmitter`, `DispatchValidator`, `LeaseManager`, `SwarmAuditDispatcher`, or a concrete `AuditOutbox` implementation directly (all still `@internal`): don't — customize audit and dispatch behavior by binding the already-public `SwarmAuditSink`, `SinkFailureHandler`, `AuditOutbox`, and `SwarmAuditSigner` contracts instead. The `AuditOutbox` contract and `AuditDrainResult` have been public since v0.5.0 and are unchanged.

### `OutboxDispatchType` is deprecated, split into `RelayLane` + `DurableDispatchType`

`BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType` was named for durable-job dispatching but also carried an `Audit` case for `swarm:relay --type=audit` — even though `Audit` never reaches the durable dispatcher (the audit lane drains a separate outbox). v0.16.0 splits the concept in two:

- **`Enums\RelayLane`** — names the lane a `swarm:relay` invocation drains: `Durable` and `Audit`.
- **`Enums\DurableDispatchType`** — the durable-run dispatch kinds persisted in the `swarm_durable_outbox` `dispatch_type` column: `Step`, `Branch`, `QueuedResume`.

**Nothing you run changes.** The `swarm:relay --type=step|branch|queued_resume|audit` CLI surface is preserved exactly — the flag strings, validation, and lane routing are unchanged. The persisted `dispatch_type` column values (`step`, `branch`, `queued_resume`) are identical, so no migration is required. `OutboxDispatchType` still exists with all four cases and its `isAudit()` method, so existing code keeps working.

**If you reference `OutboxDispatchType` directly** (uncommon — it is not part of the documented facade/command surface), migrate at your convenience:

- `OutboxDispatchType::Audit` / `->isAudit()` → `RelayLane::Audit`.
- `OutboxDispatchType::Step|Branch|QueuedResume` → `DurableDispatchType::Step|Branch|QueuedResume`. The `DurableOutbox::drain()` contract now type-hints `array<DurableDispatchType>`; pass `DurableDispatchType` cases to it.

The enum is scheduled for removal in a future major release.

## Upgrading to v0.15.1

**No required action.** v0.15.1 is three fixes to v0.15.0, with no migration, no config change, and no breaking API. A few notes if any apply to you:

- **`assertEventFired()` now works.** If you followed the testing docs and hit *"Swarm event recording is only available in tests where the recorder has been activated,"* add `use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;` to your test case — the trait the docs referenced now ships. See [docs/testing.md](docs/testing.md#asserting-lifecycle-events).
- **Compaction is now scoped to durable streaming.** `swarm:compact` only ever graduated durable per-node streaming runs (`#[DurableStreaming]`); it now no longer generates phantom work for live, non-durable `stream()` runs. Those runs' hot `swarm_stream_events` rows are bounded by TTL via `swarm:prune` — schedule that command if you run high-volume live streaming and weren't already. (This was the effective behaviour before; v0.15.1 just stops the no-op churn and documents it.)
- **Streaming a structured-output agent now fails loud in-package.** A worker implementing `HasStructuredOutput` placed on a streaming path previously surfaced a bare `laravel/ai` `InvalidArgumentException`; it now throws a `StructuredOutputStreamingException` naming the node, the agent, and the remedy — and, for `#[DurableStreaming]` swarms, fails at dispatch rather than mid-run. The hierarchical coordinator (which legitimately uses structured output and runs via `prompt()`) is unaffected.

## Upgrading to v0.15.0

### New migration: `swarm_cold_archives`

v0.15.0 ships a new migration that creates the `swarm_cold_archives` table. Run `php artisan migrate` after updating the package. The table is inert until the background compactor (#287) is available — existing runs continue to read entirely from the hot store (`swarm_stream_events`). You can override the table name via `SWARM_COLD_ARCHIVES_TABLE` or `config('swarm.tables.cold_archives')`.

### `StreamEventStore` container binding change (internal)

Under the database persistence driver, resolving `StreamEventStore` from the container now returns a `TieredStreamEventStore` instead of a `DatabaseCausalLogStore`. Both classes are `@internal` — operators should not type-check the resolved instance. If your application does `instanceof DatabaseCausalLogStore` on the resolved store binding, update that check to use the `StreamEventStore` contract instead.

### Other migrations in this release

The same `php artisan migrate` run also adds five nullable columns to `swarm_stream_events` (`event_uuid`, `void_type`, `void_target_event_uuid`, `void_reason`, `sealed_at`) plus two run-scoped indexes (#282), the compaction lease/quarantine columns (`compaction_token`, `compaction_leased_until`, `compaction_quarantined_at`) on `swarm_durable_runs` (#287), the durable-streaming identity columns `node_id` + `attempt_epoch` (with a run-scoped index) on `swarm_stream_events` (#298), and the `durable_streaming` pin column on `swarm_durable_runs` (#310). All are additive and nullable (the pin defaults to `false`) — existing rows read back unchanged and no backfill is required.

### New (opt-in): schedule `swarm:compact` to bound the hot log

The background compactor is **not auto-scheduled** — if you stream long or high-volume runs on the database driver, schedule it or the hot `swarm_stream_events` table grows unbounded:

```php
// bootstrap/app.php (or routes/console.php)
$schedule->command('swarm:compact')->hourly();
```

`swarm:compact` discovers runs with a sealed window and dispatches a `CompactSwarmRun` queue job per run, so ensure a worker drains that queue. It is a no-op on the cache driver. Tune the lease with `SWARM_COMPACTION_LEASE_SECONDS` (default `300`). See the [Streaming Substrate Operator Runbook](docs/operator-runbook-streaming-substrate.md) for the retention horizon and the quarantine recovery flow. Applications that do not stream, or that are content to let the hot log retain a run's full event history until `swarm:prune`, need take no action.

### New config block: `swarm.context_growth.*` (inert by default)

A new config block governs a streaming run's hot working set: `swarm.context_growth.{policy,budget_events,hard_cap_events,backpressure_delay_ms}` (env `SWARM_CONTEXT_GROWTH_*`). `budget_events` and `hard_cap_events` default to **null** (inert) — the package imposes no budget unless you set one. Setting `budget_events` activates the framework default `degrade_to_cold` behaviour unless a swarm declares a different rung via `#[ContextGrowthPolicy]`. No action is required to preserve v0.14 behaviour.

### Rolling deploy from v0.14.x (worker compatibility)

A v0.15.0 worker writes a `swarm_causal_seal_barrier` row immediately after the first `SwarmStreamEnd`. The forward-compatibility sentinel that skips unknown event types (`SwarmUnknownEvent`) was **not** backported to v0.14.x, so a **v0.14.x worker that resumes a run after a v0.15.0 worker has written a barrier will throw**. Two safe paths:

- **Coordinated full-fleet restart** — bring all workers to v0.15.0 before any long-running durable run completes. This is the guaranteed-safe path.
- **Standard rolling deploy** — safe in practice when the deploy finishes within one compaction window (default lease `300 s`), since the exposure window is short.

From v0.15.0 onward the sentinel protects you: a future package version's new event types will not crash a co-deployed v0.15.0 worker.

**Durable per-node streaming opt-in.** A swarm opts into durable per-node streaming with the `#[DurableStreaming]` attribute (off unless the attribute is present). It streams every durable topology — sequential, hierarchical, static_hierarchical, and parallel (including fan-out branches); the hierarchical coordinator emits structural events (token streaming is a follow-up). Declaring the attribute on a future, not-yet-wired topology fails loud at dispatch rather than silently no-op'ing. A streamed durable run writes `node_reexecuted` void-edges on top of the seal barriers above — both event shapes a lingering v0.14.x worker cannot read. The opt-in is resolved once and **pinned onto the durable run row at run-start**, so a run streams (or does not) for its whole life regardless of a later redeploy — a run started before you add the attribute will never stream, and one started after will, with no mid-flight flip. The only rolling-deploy concern is therefore the same v0.14.x-cannot-read-barriers window described above: don't add `#[DurableStreaming]` to a swarm with in-flight runs until the fleet is fully on v0.15.0. To pause durable streaming fleet-wide at runtime without a redeploy, set `SWARM_DURABLE_STREAMING_ENABLED=false` (env for `swarm.durable.streaming_enabled`, default `true`); it gates only emission, so opted-in runs fall back to `prompt()` while crashed attempts are still voided and committed nodes still sealed — flipping it mid-run is safe.

## Upgrading to v0.13.0

v0.13.0 raises the **minimum `laravel/ai` to `^0.8`** (dropping `^0.6 || ^0.7`). There are **no migrations** and no `config/swarm.php` changes. The durable persistence shapes (the `tool_calls` snapshot column and the audit envelope) are unchanged, so existing runs replay across the upgrade.

### Minimum `laravel/ai` is now 0.8

`laravel/ai` is not isolated from your application by Swarm. Update your own constraint to a version that resolves `laravel/ai ^0.8`, then re-run your integration tests. If your application is pinned to `laravel/ai` `0.6` or `0.7`, that pin must be raised before upgrading Swarm — Composer will refuse the resolution otherwise.

**Action:** `composer require builtbyberry/laravel-swarm:^0.13` (or `composer update`) and confirm `laravel/ai` resolves to `0.8.x`. Treat it as an integration-test event for your app, per the note at the top of this guide.

### `swarm_tool_call` stream events no longer carry `reasoning_encrypted_content`

laravel/ai 0.8 added a `reasoning_encrypted_content` field to its `ToolCall` DTO (the opaque OpenAI ZDR reasoning blob). Swarm's streamed/persisted `swarm_tool_call` event is deliberately pinned to the fields it owns — `id`, `name`, `arguments`, `result_id`, `reasoning_id`, `reasoning_summary` — and does **not** surface the encrypted blob. This is the same field set previous versions round-tripped, so consumers of the swarm event stream see no new keys.

**Action:** none for the documented event surface. Only code that reached *through* `swarm_tool_call` into a raw `laravel/ai` `ToolCall::toArray()` to read provider-internal reasoning fields (never a supported path) would notice; ZDR encrypted reasoning is the provider's contract and Swarm does not persist or echo it.

## Upgrading to v0.12.3

v0.12.3 is a review-follow-up release with **no migrations** and no config or API changes. One change affects `swarm:health` output.

### `swarm:health` now warns on aged unclaimed audit-outbox rows

The audit-outbox staleness check previously warned only on pending rows whose relay *reservation* had expired. Pending rows the relay had never claimed (e.g. the relay is unscheduled, misrouted, or starved before claim) were not aged, so a backlog could accumulate while the check reported `relay appears active`. The check now also warns when unclaimed pending rows age past `swarm.durable.relay.stale_warning_threshold_seconds` (default `2 × reservation_timeout_seconds`), reporting it as a distinct signal.

**Exit code and `--json` `ok` are unchanged:** a `warning` row is not a `failed` row, so the command still exits `0` and `ok` stays `true`. Monitoring that keys on the exit code or the `ok` field needs no change.

**Action:** only operators who parse the `swarm:health` *table text* (rather than the exit code / `ok` field) and treat the presence of a `warning` as actionable may see a new warning after upgrade — in which case the warning is a true positive: audit evidence is backing up and `swarm:relay` scheduling should be verified.

The same staleness thresholds (audit and durable) are now computed in UTC to match how the outbox stores its timestamps; on deployments with a non-UTC `app.timezone` this corrects a boundary skew that could previously hide or prematurely flag aged rows. No action required.

## Upgrading to v0.12.2

v0.12.2 is a hardening release with **no migrations**. Two changes may require operator attention: the audit signing guard (#223) described below, and the durable advance-job timeout correction (#243) described below that.

### `swarm:health --json` `ok` field now mirrors the exit-code contract (#247)

The `ok` key in `swarm:health --json` output previously returned `false` whenever any row had a status other than `ok` — including `note` (e.g. relay not scheduled) and `warning` (e.g. stale outbox rows). A healthy deployment that had a relay-scheduling note or a stale-outbox warning therefore emitted `{"ok": false}` while exiting 0, making the `ok` field useless for automated monitoring.

**New behavior:** `ok` is `true` if and only if no row has `status=failed`, which is the exact complement of the non-zero exit code. Note and warning rows no longer flip `ok`.

**Action:** scripts that keyed on `ok: false` to detect note or warning rows (e.g. to alert on a missing relay schedule) must now inspect the `checks` array for individual row statuses. Scripts that used `ok: false` only to detect failures continue to work correctly.

Also, audit-outbox checks (`runAuditOutboxChecks()`) are now skipped for failure policies that do not use the outbox (`swallow`, `log`, `halt`). Those configurations now return a single `note` row and exit 0 instead of potentially erroring if the audit-outbox migration has not been run. Scripts monitoring these configurations should expect a `note` row rather than the outbox table/staleness/dead-letter rows.

### `DurableRunStore` interface: `recoverableQueuedResumes()` added (#244)

`DurableRunStore` gained a new required method in v0.12.2:

```php
public function recoverableQueuedResumes(
    ?string $runId = null,
    ?string $swarmClass = null,
    int $limit = 50,
    int $graceSeconds = 300,
): array;
```

**Who is affected:** applications that implement `DurableRunStore` directly with a custom store class. The shipped `DatabaseDurableRunStore` already implements the method.

**Who is not affected:** applications that only *call* the store (via `app(DurableRunStore::class)->...`), use the default database driver, or interact with the store through the `DurableSwarmManager` facade methods.

**Action required:** custom store implementations must add this method. A minimal stub for stores that do not use `QueueHierarchicalParallel` coordination is sufficient:

```php
public function recoverableQueuedResumes(
    ?string $runId = null,
    ?string $swarmClass = null,
    int $limit = 50,
    int $graceSeconds = 300,
): array {
    return [];
}
```

### Durable advance jobs now pin their timeout to `step_timeout + margin` (#243)

Previously `AdvanceDurableSwarm` and `AdvanceDurableBranch` exposed a `timeout()` method, but Laravel's queue layer reads timeout from the job's `$timeout` **property** — the method was never called. Both jobs silently inherited the worker `--timeout` (default 60 s), which could prematurely kill long-running steps and churn leases.

As of v0.12.2 the jobs set `$this->timeout = step_timeout + timeout_margin_seconds` in their constructors, so the computed value is serialized directly into the queue payload and the worker honors it.

**Action required in most deployments:** confirm your queue worker `--timeout` is at least `SWARM_DURABLE_STEP_TIMEOUT + SWARM_DURABLE_JOB_TIMEOUT_MARGIN_SECONDS` (defaults: 300 + 60 = 360 s). Workers that were already running with a high `--timeout` continue to work; workers running at the default 60 s will now correctly time out jobs whose steps exceed the window rather than silently abandoning them.

**No application-code change is required.** `ConfiguresDurableAdvanceJob` is `@internal`; if you subclass it (unsupported), remove any `$timeout` property you declared and call `$this->applyDurableAdvanceJobTimeout()` in your constructor instead.

Note: the job timeout does not hard-cancel an in-flight provider call — it signals the worker to stop waiting for the job process. Raise `SWARM_DURABLE_STEP_TIMEOUT` if your steps routinely take longer than the default 300 s.

### Queued runs are attempted once by default

`InvokeSwarm` and `BroadcastSwarm` now attempt **once** regardless of the queue worker's `--tries` flag. A retry restarts the entire swarm run from step 0 — re-dispatching all tool calls and re-spending all LLM tokens — because these jobs hold no checkpoint. Previously they declared no `$tries` property and silently inherited the worker's global `--tries`, so a common `queue:work --tries=3` setup blind-retried expensive non-durable runs three times.

**Most operators need no action.** The new default (`SWARM_QUEUE_TRIES=1`) is the safe choice for all non-idempotent swarms.

**To opt back in:** if your swarms are idempotent and the token cost of a full restart is acceptable, restore the previous behavior by setting:

```env
SWARM_QUEUE_TRIES=3
```

or in `config/swarm.php`:

```php
'queue' => [
    'tries' => 3,
],
```

`SWARM_QUEUE_TIMEOUT` now also takes effect as expected. Previously the `timeout()` method was silently ignored by the queue worker (Laravel reads the `$timeout` property, not a `timeout()` method); `SWARM_QUEUE_TIMEOUT` is now wired through the property and reaches the serialized job payload. No action required unless you were relying on the setting having no effect.

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

> **Reversed in v0.23.0 — this migration is no longer required.** Swarm now
> type-hints the vendor `Laravel\Ai\Contracts\Agent` at every public entry
> point and runner gate, so plain `laravel/ai` agents work unchanged and the
> swarm marker is a deprecated alias. The reasoning below (that the marker
> shields applications from `laravel/ai` churn) did not hold: the marker
> `extends` the vendor interface, so upstream signature changes propagate
> through it verbatim. If you migrated your agents in v0.5.0 you do not need to
> revert them — they keep working — but new agents should implement the vendor
> contract directly. See [Upgrading to v0.23.0](#upgrading-to-v0230).

**Migration required for custom agent classes** *(superseded — see the note
above)*. Application code that defines an agent and feeds it into Swarm must
implement the new marker interface:

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

## Audit exception messages redacted by default (minor)

As of v0.14.0, the free-text **message** of an exception thrown on an audit
sink-failure, signing, or outbox path is redacted to `[redacted]` in the audit
log context by default. The exception **class/type is always logged**, so failure
diagnosability by type is unchanged. Provider, driver, and tool exception messages
can carry prompt fragments or PII, so this routes them through the same capture
authority as the rest of the runtime.

Controlled by **`swarm.audit.redact_exception_messages`** (env
**`SWARM_AUDIT_REDACT_EXCEPTION_MESSAGES`**), default **`true`**:

- **`true`** (default) — the message is logged as `[redacted]` unless capture already
  permits failure free-text (`swarm.capture.inputs` **and** `swarm.capture.outputs`
  both enabled).
- **`false`** — the raw message is always logged, regardless of capture posture (for
  trusted internal-only deployments that need full diagnostic detail in audit logs).

**Action:** none required. If your monitoring or incident tooling greps audit failure
logs for raw exception text, either enable capture for those runs or set
`SWARM_AUDIT_REDACT_EXCEPTION_MESSAGES=false` to restore the prior verbatim behavior.
