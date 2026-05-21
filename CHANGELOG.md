# Changelog

## v0.9.0 - unreleased

Memory Foundation — first-class scoped, snapshot-replayable memory subsystem with RunContext consolidation. Foundation for v0.10.0 propagation/operator surface and v0.11.0 memory-as-tool DX. Vector-backed recall ships as the `laravel-swarm-memory-vector` companion package.

### Added

_To be filled in during release wrap-up._

### Changed

_To be filled in during release wrap-up._

## v0.8.0 - 2026-05-21

One-command install. A new `swarm:install` orchestrator plus four targeted sub-installers (`swarm:install:durable`, `swarm:install:audit`, `swarm:install:pulse`, `swarm:install:examples`) cut the read-the-docs-and-hand-wire-five-things ritual down to a single Artisan command, and a curated runnable starter example pack ships so a fresh Laravel app is up and dispatching swarms inside a minute. New concrete `LogChannelSwarmAuditSink` (the zero-config dev/staging sink the audit installer binds), new `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` provider-free agent base class (the starter examples extend it; `make:swarm:agent` scaffolds it), and a polished `make:swarm:swarm` + `make:swarm:agent` generator pair with topology-aware stubs that match the starter pack. The legacy `make:swarm` keeps working as a deprecated alias. Getting Started and Advanced Setup docs rewritten around the new install flow. No migration changes. No breaking changes.

### Added

- **`php artisan swarm:install` main interactive installer (#85).** New orchestrator command — the single entry point an operator runs after `composer require builtbyberry/laravel-swarm`. Walks the user through Swarm setup with `laravel/prompts` (`select` for the persistence driver choice, `confirm` for every sub-installer offer), publishes `config/swarm.php` via a direct file copy (sidestepping `vendor:publish`'s boot-time destination capture so the installer works inside the test harness from #92), seeds the canonical Swarm `.env` keys (`SWARM_PERSISTENCE_DRIVER`, `SWARM_TOPOLOGY`, `SWARM_TIMEOUT`, `SWARM_MAX_AGENT_STEPS`, `SWARM_AUDIT_FAILURE_POLICY`, the four `SWARM_CAPTURE_*` toggles) plus safe defaults additively to `.env` and `.env.example` (operator-overridden values are left untouched; missing keys are appended under a `# Laravel Swarm — added by swarm:install` header), runs `php artisan migrate --force` on the database persistence path or scaffolds a `LaravelSwarm::ignoreMigrations()` call into `AppServiceProvider::register()` (cache-only path, sentinel-fenced with `// swarm:install — cache-only persistence; do not edit between markers` so re-runs are no-ops, using the same `BODY_INDENT` + relaxed-regex pattern `InstallAuditCommand` established post-#102), warns to stderr via `$this->output->getErrorStyle()->writeln(...)` when `QUEUE_CONNECTION=sync` (refuses nothing — mutating `config/queue.php` is out of scope by design), then offers and dispatches each sub-installer via `$this->call('swarm:install:durable', $args)` etc., forwarding `--no-interaction` through. Sub-installer dispatch honors `--with-<name>` / `--without-<name>` flags for CI use, defaults to "yes" interactively for audit / examples (and durable when the database persistence driver was chosen), gates `swarm:install:pulse` on `class_exists(\Laravel\Pulse\Pulse::class)` so Pulse-absent hosts never see the offer, and forwards `--all` to `swarm:install:examples` under `--no-interaction` so the sub-installer does not refuse its "pass --all or --example" precondition. Closing "you're ready" panel emits a per-step summary plus next-step pointers (`swarm:health`, `make:swarm:swarm`, `docs/getting-started.md`). Idempotent by default — a second `--no-interaction` run is a byte-for-byte no-op (proven via `assertSecondRunIsNoOp()` from the #92 harness) for both the database and cache-only persistence paths. `--force` re-publishes `config/swarm.php` in place. Eleven feature tests in `tests/Installer/InstallCommandTest.php` cover the happy path, cache-only scaffold, both idempotency contracts, `.env`-override preservation, `--persistence` validation, the sync-queue warning, `--with-examples` / `--with-audit` dispatch (verified via on-disk effects of the sub-installers, not output scraping), the Pulse-absent silent skip (via a `PulseAbsentInstallCommand` subclass that overrides the detection method), and `--force` overwrite. Registered in `SwarmServiceProvider::commands()` as `BuiltByBerry\LaravelSwarm\Commands\Install\InstallCommand`. Documented at the top of the README Installation section as the recommended path; the legacy manual flow is retained as an "Advanced setup" subsection. The full `docs/getting-started.md` rewrite lands in #93.
- **`php artisan swarm:install:pulse` sub-installer (#88).** New targeted installer that wires up the Laravel Pulse integration without hand-edits. Detects Pulse via `class_exists(\Laravel\Pulse\Pulse::class)` (no `composer show` shell-out) and refuses with an actionable copy-paste hint when Pulse is missing (`composer require laravel/pulse` → `vendor:publish` → `migrate`). Confirms `config/pulse.php` is published and refuses with a publish hint when it is not. Inserts the `SwarmRuns` and `SwarmStepDurations` recorders inside the existing `recorders` array via balanced-bracket scan (not regex), fenced with `// swarm:install:pulse recorders — managed` sentinels. Publishes the stock Pulse dashboard view to `resources/views/vendor/pulse/dashboard.blade.php` if absent, then injects the `<livewire:swarm.runs />`, `<livewire:swarm.steps />`, and `<livewire:swarm.audit-outbox />` cards inside a `{{-- swarm:install:pulse cards --}}` managed block immediately before the closing `</x-pulse>` tag. Card selection via interactive `multiselect` (laravel/prompts) or `--cards=runs,steps,audit-outbox` (with validation that surfaces unknown card names); `--no-interaction` defaults to all three. Each mutated file is copied to `<file>.bak` exactly once before the first write — re-running with `--force` rewrites the managed blocks in place but never clobbers the pre-install backup. Idempotent by default: a clean re-run with no `--force` detects both managed-block sentinels and exits 0 with no file mutations (proven byte-level by the installer test harness's `assertSecondRunIsNoOp()`). Registered in `SwarmServiceProvider`. Documented as the new "Quick Setup" section at the top of `docs/pulse.md` (manual two-edit instructions retained below for operators who prefer to install by hand). Feature tests in `tests/Installer/InstallPulseCommandTest.php` cover the refusal-when-Pulse-absent path (using a test-only subclass that overrides the detection method), the refusal-when-config-not-published path, `--cards` validation, the happy path on a clean skeleton (default cards + recorders + dashboard publication + `.bak`), `--cards=runs,steps` restricting the dashboard tags written, default-mode idempotency, and the `--force` rewrite + `.bak`-preservation contract.
- **Runnable starter example pack at `stubs/examples/` (#89).** Three curated, end-to-end runnable starter examples that the upcoming `swarm:install:examples` command (#90) will copy into a user's app: `sequential-blog-pipeline` (the three-agent hello world — outline → draft → polish, in-memory `prompt()`), `parallel-research-fanout` (three scouts run concurrently on the same task, demonstrating Parallel topology + container-resolvable agents + fan-out / join), and `durable-approval-workflow` (the showcase — two-step sequential swarm in durable mode with a `RoutesDurableWaits` checkpoint between the steps, resumed by a `policy_decision` signal). Each example ships as a complete `app/Ai/Swarms/<Name>/`, `app/Ai/Agents/<Name>/`, and `app/Console/Commands/SwarmExample<Name>Command.php` tree plus a per-example README; the namespace uses a `{{ rootNamespace }}` placeholder so the installer (#90) can rewrite to the user's `App\` namespace. Index lives at `stubs/examples/README.md`. Each runner registers a `swarm:example:<name>` artisan command. Smoke tests in `tests/Feature/Examples/` render the stubs into a temp directory under a test-only namespace and run them against the real `SwarmRunner` / `DurableSwarmManager` so the shipped stubs are proven runnable on every CI pass. Docs published as new `docs/examples.md`, cross-linked from `docs/README.md`. The existing 14 read-the-README reference examples under `examples/` are unaffected.
- **`BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` abstract base class (#89).** Runnable, provider-free agent that returns scripted text so starter examples, smoke tests, and "show the shape" demos execute end-to-end without configuring a Laravel AI provider or burning API credit. Subclasses implement `instructions()` and `reply(string $prompt): string`; the shipped `prompt()` wraps the reply in a standard `AgentResponse`. `stream()`, `queue()`, and the three broadcast helpers raise with a clear message pointing callers at the right next step (use a `Promptable` agent with `Agent::fake()`). Lives under `src/Testing/` alongside `SwarmFake` and `FakeDurableSwarmManager`.
- **`swarm:install:examples` sub-installer (#90).** New Artisan command that copies the curated starter example pack (#89) from the package's `stubs/examples/` directory into the host Laravel app, rewriting the `{{ rootNamespace }}` (and legacy `{{ namespace }}`) placeholder in every PHP file to the host app's PSR-4 root read from `composer.json` (`App\` by default; non-`App\` PSR-4 layouts pointed at `app/` are detected automatically). Auto-discovers available examples by scanning the bundled stubs directory and reads each one's one-line description from its `README.md`, so a new starter dropped under `stubs/examples/<name>/` shows up in the picker with no installer change required. Interactive mode uses `laravel/prompts` `multiselect()` to pick one, several, or every example; CI / scripted use is covered by `--example=<name>` (repeatable, single-shot), `--all` (install everything), and `--force` (overwrite existing files in the host app); `--no-interaction` requires one of `--all` or `--example`, errors loudly otherwise. The full example tree is preserved on copy — `app/Ai/Swarms/<Name>/`, `app/Ai/Agents/<Name>/`, and `app/Console/Commands/SwarmExample<Name>Command.php` — so Laravel 11+ Artisan auto-discovery picks the runner commands up on the next boot without touching `routes/console.php`. The package-internal `stubs/examples/<name>/README.md` files are deliberately not copied; they remain reference material inside the package, not noise in the user's `app/` tree. Skipping is per-example and additive: if `app/Ai/Swarms/SequentialBlogPipeline/BlogPipeline.php` already exists, the installer warns and leaves the on-disk file untouched while still installing the other selected examples in the same run. Idempotent by default (a second invocation with no `--force` is a byte-level no-op on every file in the skeleton). Prints "you can now run" hints with the exact `php artisan swarm:example:<name>` command for each freshly-installed example. Registered in `SwarmServiceProvider`; lives under the new `src/Commands/Install/` namespace alongside the other v0.8.0 sub-installers. Documented in `docs/examples.md` as the recommended install path. Tests at `tests/Installer/InstallExamplesCommandTest.php` exercise discovery, namespace rewriting (default `App\` plus a custom `Acme\Platform\` PSR-4 root), refusal on existing files, `--force` overwrite, idempotency double-run, unknown-example rejection, non-interactive flag enforcement, the `--all`-vs-`--example` mutual exclusion, and the runnable-hint output.
- **`make:swarm:swarm` and `make:swarm:agent` generators (#91).** Split the v0.7-era single `make:swarm` generator into two dedicated commands so the generator surface matches the two kinds of class an app actually writes. `make:swarm:swarm <Name>` scaffolds a swarm class under `app/Ai/Swarms/` and accepts `--topology=sequential|parallel|hierarchical|static-hierarchical` (default sequential; prompts interactively when omitted on a TTY, defaults silently to sequential under `Artisan::call()` or CI so scripts stay scriptable). `make:swarm:agent <Name>` is brand new — it scaffolds an agent under `app/Ai/Agents/` extending `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` (the same shape the starter examples in `stubs/examples/` use), with `instructions()` and `reply()` carrying TODO markers pointing at the swap-to-`Promptable` upgrade path for plugging in a real LLM. Both generators ship publishable stubs (`swarm.stub`, `swarm.parallel.stub`, `swarm.hierarchical.stub`, `swarm.static-hierarchical.stub`, `swarm.agent.stub`) under the existing `swarm-stubs` publish tag and check `base_path('stubs/')` for a customized copy before falling back to the shipped version. Stubs were rewritten so generated code is visually consistent with the runnable starter examples in `stubs/examples/` — same class header docblocks, same `agents()` return type, same `// new \App\Ai\Agents\…` placeholder shape. Documented in new `docs/generators.md` (linked from `docs/README.md`); `docs/sequential.md`, `docs/parallel.md`, `docs/static-hierarchical-topology.md`, `docs/public-surface.md`, and the README were updated to use the new commands.
- **`swarm:install:audit` sub-installer (#87).** New targeted installer that wires the audit pipeline into a host application in a single command. Without it, audit users have to discover that the default `SwarmAuditSink` binding is `NoOpSwarmAuditSink` (silent discard), write the sink binding by hand, and remember the four optional extension contracts (`SwarmAuditSigner`, `ActorResolver`, `CapturePolicy`, `SinkFailureHandler`) that turn on the regulated-deployment behaviors. The installer scaffolds a `SwarmAuditSink` binding into `app/Providers/AppServiceProvider::register()` behind unique `// swarm:install:audit — managed bindings` sentinel comments (so re-runs are no-ops), accepts `--sink=readable|noop|custom` to pick the binding shape (default `readable` interactively, `custom` under `--no-interaction` so CI runs do not silently route evidence to the application log), confirms the `swarm_audit_outbox` table is present (and offers to run `php artisan migrate` when it is not, on the database persistence driver), prints the current `SWARM_AUDIT_FAILURE_POLICY` and `SWARM_CAPTURE_*` flags with one-line explainers so the operator sees what is being recorded before shipping, and cross-links to `swarm:audit:status`, `swarm:audit:reconcile`, and `swarm:trace` for verification. Three optional flags (`--with-signer`, `--with-actor-resolver`, `--with-capture-policy`) emit additional TODO-marker bindings for regulated deployments. Refuses cleanly when AppServiceProvider is missing or its `register()` method body cannot be located. Registered in `SwarmServiceProvider`, with full feature coverage via the installer test harness (`tests/Installer/InstallAuditCommandTest.php`).
- **`php artisan swarm:install:durable` sub-installer (#86).** Targeted setup command for the durable execution runtime — drops the read-the-docs-and-hand-wire-five-things ritual that durable adopters previously faced. The command (a) verifies `swarm.persistence.driver` is `database` and refuses with an actionable error when it is `cache`, (b) probes the durable runtime tables (`swarm_durable_runs`, `swarm_durable_outbox`) and offers to run `php artisan migrate --force` when they are absent (or warns when `--skip-migrate` is set), (c) appends a managed scheduler block to `routes/console.php` registering `Schedule::command('swarm:relay')->everyMinute()`, `swarm:recover` (every five minutes), and `swarm:prune` (daily) — guarded by an idempotency marker (`// swarm:install:durable schedule entries — managed; do not edit`) and a per-line presence check so it never duplicates entries the user wired by hand, (d) inspects `QUEUE_CONNECTION` and refuses on `sync` (bypassable with `--allow-sync-queue` for local experiments only), and (e) prints copy-paste worker snippets for plain `queue:work`, the `config/horizon.php` supervisor `queue` list, and a Forge/Supervisor `.conf` block. Flags: `--queue=<name>` overrides the printed queue name (defaults to the configured `swarm.durable.queue.name` or the package convention `swarm-durable`); `--migrate`/`--skip-migrate` make the migration step explicit for non-interactive use; `--allow-sync-queue` opts in to the local-only sync path; `--no-interaction` skips prompts and warns when migrations are pending. Out of scope by design: installing Horizon, writing to `config/queue.php`, or spawning worker processes. Ships with a feature test suite under `tests/Installer/InstallDurableCommandTest.php` (eight tests via the v0.8.0 installer test harness, #92) covering the success path, double-run idempotency, both refusal paths, the sync-queue bypass, the `--queue` override, missing-tables warning, and the "user already wired schedule entries" merge case. Registered in `SwarmServiceProvider::commands()` as `BuiltByBerry\LaravelSwarm\Commands\Install\InstallDurableCommand`; documented in `docs/durable-execution.md` as the new Quick Setup entry point.
- **`BuiltByBerry\LaravelSwarm\Audit\LogChannelSwarmAuditSink` concrete sink.** Log-channel-backed `SwarmAuditSink` implementation that writes every audit record as a structured log entry (`swarm.audit.<category>`) to the configured Laravel log channel (defaults to `audit`, falls back to the default channel when `audit` is not configured). Ships as the concrete class the `swarm:install:audit --sink=readable` installer binds — replacing an earlier draft that mistakenly pointed the binding at the `ReadableSwarmAuditSink` extension contract (an interface, not instantiable). Implements only `SwarmAuditSink`, not `ReadableSwarmAuditSink`, because log channels are not queryable; `swarm:trace` degrades gracefully when this sink is bound. Lives under `src/Audit/` alongside `NoOpSwarmAuditSink`. Production deployments should still ship a bounded backend (database, queue, SIEM export); this sink is the zero-config dev/staging default.
- **Shared installer command test harness at `tests/Installer/` (#92).** New `BuiltByBerry\LaravelSwarm\Tests\Installer\InstallerTestCase` base class that materializes a minimal Laravel-shaped scratch skeleton (`config/`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, `.env`, `composer.json`, plus the standard `database/`, `resources/`, `storage/`, `bootstrap/`, `public/`, `tests/` directories) into a temp directory per test, re-points the booted testbench application at it via `$this->app->setBasePath(...)` so `app_path()` / `config_path()` / `base_path()` resolve into the fixture, and tears the directory down on `tearDown()` for full isolation and parallel safety. Ships ergonomic helpers — `runInstaller()`, `assertInstallerFailsWith()`, `assertFileContains()`, `assertEnvKey()`, `assertScheduleEntry()`, `assertProviderBinding()`, `writeSkeletonFile()`, `skeletonPath()`, `snapshotSkeleton()` — plus a fluent idempotency double-run check (`runInstaller(...)->twice()->assertSecondRunIsNoOp()`) that hashes every file in the skeleton before/after and fails loudly on any byte-level drift. Includes a `NoOpInstallerCommand` fixture and end-to-end smoke test (`tests/Installer/NoOpInstallerSmokeTest.php`) plus eleven self-tests covering every helper (`tests/Installer/InstallerTestCaseHelpersTest.php`). The harness uses lightweight filesystem fixtures only — no new `composer require-dev` entries beyond the already-present `orchestra/testbench`. Wired into the default `composer test` lane and the `Installer` PHPUnit testsuite; downstream consumers documented in `tests/Installer/README.md`. Foundational infrastructure for #85, #86, #87, #88, and #90 — no public swarm-runtime surface change.

### Changed

- **`make:swarm` is now a deprecated alias for `make:swarm:swarm` (#91).** Running it continues to produce a swarm class under `app/Ai/Swarms/` exactly as before — no shape change, no flag change, no behavior change — but it now prints a deprecation notice on stderr pointing callers at `make:swarm:swarm` (for swarms) and `make:swarm:agent` (for agents). Existing scripts, docs, and tutorials keep working. The alias is slated for removal in a future major release; track #91 for the deprecation window.
- **Getting Started and Advanced Setup docs rewritten around `swarm:install` (#93).** New `docs/getting-started.md` is the canonical new-user landing page: a single-command install via `swarm:install`, post-install verification with `swarm:health`, and a five-minute walkthrough of running the `sequential-blog-pipeline` starter from #89 end-to-end with no API key. New `docs/advanced-setup.md` preserves the manual flow as a stable appendix — it covers the manual equivalent of every step the installer performs (config publish, env seeding, database vs. cache persistence with the `LaravelSwarm::ignoreMigrations()` scaffold pattern, scheduler entries for `swarm:relay` / `swarm:recover` / `swarm:prune`, audit sink binding, Pulse recorder + dashboard registration, and copying the starter examples) for environments where the installer cannot run. README Installation section now points at both docs for further reading and at the per-feature Quick Setup sections (`swarm:install:durable`, `swarm:install:audit`, `swarm:install:pulse`, `swarm:install:examples`) for the targeted sub-installers. Cross-link audit pass added a one-paragraph "`swarm:install` is the broader entry point" pointer to the top of each Quick Setup section in `docs/durable-execution.md`, `docs/audit-evidence-contract.md`, `docs/pulse.md`, and `docs/examples.md`. `docs/configuration.md` now leads with `swarm:install` and demotes the bare `vendor:publish --tag=swarm-config` invocation to a manual fallback. `docs/README.md` index gains entries for both new docs at the top of the Getting Started section. The legacy 6-step bash block in the README is gone — the manual flow lives in `docs/advanced-setup.md` now. No public-surface change, no migration change, no breaking change.

## v0.7.0 - 2026-05-20

Developer experience, test coverage, and one new operator forensics surface. One new opt-in public-surface contract (`ReadableSwarmAuditSink`) and one new Artisan command (`swarm:trace`). No migration changes. No breaking changes.

### Added

- **`swarm:trace <run_id>` audit-chain reconstruction CLI + `ReadableSwarmAuditSink` contract (#44).** New read-only Artisan command that walks a single run's audit chain end-to-end by merging three sources into a chronological timeline: sink-side records via the new opt-in `BuiltByBerry\LaravelSwarm\Contracts\ReadableSwarmAuditSink` extension contract (`forRun(string $runId): iterable<array>`), pending and dead-letter rows from `swarm_audit_outbox` with attempt counts and `last_error`, and the lifecycle entry from the bound `RunHistoryStore`. Default output is a human-readable timeline table; `--json` mirrors the `swarm:audit:status` / `swarm:audit:reconcile` shape so the same monitoring scrapers can consume it; `--include-payloads` attaches the full evidence envelope per record (off by default — payloads can be large); `--limit=N` (default 1000) bounds sink-side reads so a long-running run cannot exhaust memory in the command's in-memory sort (outbox and history rows are bounded by the run itself and not subject to the limit). The contract is intentionally opt-in: the shipped `NoOpSwarmAuditSink` does not implement it and existing custom sinks remain valid. When the bound sink is `NoOpSwarmAuditSink` or does not implement `ReadableSwarmAuditSink`, the command degrades to outbox + history only and surfaces a clear `degraded: true` flag and per-source note explaining the limitation. Same graceful degradation when the audit outbox is unavailable (cache persistence driver, missing table). The command is read-only and never mutates audit state. The command unseals encrypted-at-rest data on output (`last_error` always; full payload under `--include-payloads`) — in regulated environments do not redirect to durable storage; see `docs/audit-evidence-contract.md` "Security and retention". Registered in `SwarmServiceProvider` and added to `docs/public-surface.md` under Artisan Commands and Audit Extension Points; full read contract documented in `docs/audit-evidence-contract.md` under a new "Reading the Audit Chain" section.
- **`SwarmFake` intercepts for the v0.4 audit-extension contracts (#42).** Three new static helpers — `SwarmFake::interceptCapturePolicy()`, `interceptSinkFailureHandler()`, and `interceptSwarmAuditSigner()` — swap the container binding for the corresponding contract to a recording decorator and return a recorder with first-class assertion methods (`assertCaptured`, `assertCapturedDecision`, `assertCapturedWith`, `assertSinkFailureRouted`, `assertSinkFailureRoutedAs`, `assertSigned`, plus `Never*` variants). Each decorator wraps an optional delegate so existing policy / handler / signer logic still drives behavior; the recorder only captures inputs, the routed decision, and the resulting payload. Replaces the v0.4.3 workaround-pattern documentation; `docs/testing.md` "Testing Audit Extension Points" gains a new leading section covering the intercepts and how they preserve `SwarmFake`'s "doesn't touch the dispatcher" design property — recording happens when the real dispatcher resolves the contract from the container during a non-faked run, so `SwarmFake` itself never constructs or invokes the dispatcher.
- **`RunContext::fake()` test helper for ad-hoc test setup (#43).** New named constructor on the public `RunContext` value object that returns a context with sensible test defaults (deterministic run id `"fake-run-id"`, empty input, no actor). Override any slot via the `$overrides` array (`run_id`, `input`, `data`, `metadata`, `artifacts`, `actor` — the latter delegates to the existing `withActor()` builder and accepts `Actor | Authenticatable | "type:id" | "id" string | null`). Composes cleanly with the existing fluent builders (`->withActor()`, `->withLabels()`, `->mergeData()`, etc.) so rich test setups stay one fluent chain instead of three. Documented in `docs/testing.md` with a worked example bridging `RunContext::fake()` into `SwarmFake` assertions. Pure additive — zero changes to existing public methods.
- **End-to-end audit-chain test with mid-flight signer rotation (#41).** New `tests/Feature/AuditChainEndToEndTest.php` exercises the full chain (enqueue → drain attempt → transient failure → re-attempt → eventual success or dead-letter) through the real `SwarmAuditDispatcher` and `DatabaseAuditOutbox` rather than unit-testing the components in isolation. Four parameterized scenarios cover happy-path replay and full chain-to-dead-letter, each in both `encrypt_at_rest=true` and `=false` modes; the K1 signature is asserted to persist across rotation to K2, `attempts` progression is verified, `last_error` sealing-at-rest is verified via `SwarmPersistenceCipher::open()` for storage-shape neutrality, and the dead-letter transition's `Log::error` is asserted to carry the K1 signature in the final state. Locks down the chain-integrity properties (signature stability, attempt-count progression, retention enforcement) as one cohesive regression story.
- **Process-concurrency coverage for audit outbox SKIP LOCKED (#40).** New `tests/ProcessConcurrency/AuditOutboxConcurrencyTest.php` proves that two parallel `DatabaseAuditOutbox::drain()` calls each claim a disjoint subset of pending rows and that a single stale reservation is reclaimed by exactly one worker — guarantees the existing SQLite-bound regression tests cannot prove. Tagged `skip-locked-real-db` so it skips cleanly on the testbench in-memory SQLite (which honors neither cross-process state nor `FOR UPDATE SKIP LOCKED`) and is exercised against a real MySQL/Postgres connection via the new `composer test:process-concurrency:real-db` lane and a new `.github/workflows/tests-real-db.yml` matrix job. The default CI lane (`test:process-concurrency:ci`) excludes the group under `--fail-on-skipped` so SQLite-only CI keeps passing while the real-DB lane enforces the lock contract end-to-end.
- **Regression coverage for the audit evidence envelope `schema_version` bump rule (#76).** New `tests/Unit/Audit/EvidenceSchemaVersionTest.php` asserts that every audit category emitted in `src/` (37 categories, excluding telemetry-only `stream.event` / `broadcast.event`) carries `schema_version === EvidenceEnvelope::SCHEMA_VERSION` after dispatch through `SwarmAuditDispatcher`. Additional canaries assert the constant is `"2"` (guarding against an inadvertent further bump without coordinated CHANGELOG / UPGRADING / docs updates), that the deprecated `SwarmAuditDispatcher::SCHEMA_VERSION` mirror tracks the envelope constant, and that a caller-supplied `schema_version` cannot override the envelope's enriched value. A full audit pass across `src/`, `tests/`, `examples/`, and `database/` found zero stale `"1"` emitters — the codebase was already clean; this commit is pure regression coverage to keep it that way.

### Changed

- **`CONTRIBUTING.md` refresh for v0.5+ patterns (#47).** New "Audit pipeline contributions" section covers the bind-a-contract-in-the-container extension pattern across all six audit contracts (`ActorResolver`, `CapturePolicy`, `SwarmAuditSigner`, `SinkFailureHandler`, `SwarmAuditSink`, `AuditOutbox`), the additive-vs-`schema_version`-bump rule for envelope changes (with the v0.4→v0.5 `command.*` actor-unification as the reference example), and the dispatcher routing contract including the `MAX_HANDLER_ITERATIONS = 5` runaway guard and cache-driver degrade behavior. New "Stability Surface" section documents the `@internal` PHPDoc convention, when to ask before extending public surface, and the Pulse component pattern (Recorders are public surface; Livewire/Support are `@internal`). New "Test Tier Expectations" section codifies the `Unit` / `Feature` / `ProcessConcurrency` lanes — including a "Writing `tests/ProcessConcurrency/*` worker closures" subsection that documents the two non-obvious traps the package's own audit-outbox concurrency test hit during development: closure scope class (Pest auto-generated `P\Tests\…` classes do not exist in child PHP processes spawned by the process driver — define worker closures in free functions, not inline; `static` alone is not enough) and child container bootstrap (testbench's child Laravel boots without the package being tested — worker closures that need swarm container bindings must call `app()->register(SwarmServiceProvider::class)` and set the minimum config the binding chain requires).
- **`docs/public-surface.md` refresh for v0.5- and v0.6-era audit surface (#75).** Listed surface that landed with the v0.5 audit outbox and v0.6 operator commands but had not made it into the public-surface table: all five `SinkFailureDecision` cases (`Queue` and `DeadLetter` added in v0.5 alongside the audit outbox), the `AuditOutbox` contract, the `AuditDrainResult` response value object, the `swarm:audit:status` and `swarm:audit:reconcile` v0.6 operator commands, the `--audit` / `--durable` focus flags on `swarm:health`, and the `--type=audit` lane on `swarm:relay`. v0.7-era additions (`swarm:trace`, `ReadableSwarmAuditSink`) were added in #44 itself.

## v0.6.0 - 2026-05-20

Operator UX, developer experience, and test coverage on top of the v0.5 audit outbox foundation. No new public-surface contracts. No migration changes. No breaking changes.

### Added

- **`swarm:audit:status` summary command (#37).** New read-only Artisan command that surfaces the audit outbox at a glance for operators triaging from the CLI; reports counts (pending / reserved / stale_reserved / dead_letter), an age-distribution histogram, the top-5 dead-letter categories, the oldest pending and dead-letter rows, and the configured retention window alongside the next-prune count. `--json` mirrors the `SwarmHealthCommand` shape so the same monitoring scrapers consume both. Degrades cleanly on cache persistence by skipping with an informational note instead of querying a table that does not exist.
- **`swarm:audit:reconcile` forensic CLI (#36).** New operator-driven Artisan command for dead-letter triage; defaults to `list` mode and gains `--show=<id>` (display a single row with its sealed payload unsealed for human review), `--requeue=<id>` (re-enqueue a dead-letter record for one more relay pass), and `--dismiss=<id> --reason="..."` (permanently dismiss a record with a mandatory operator-supplied reason). Supports `--json`, `--force` (required for mutations under `--json`), `--limit`, and `--status` flags. Pending rows can be listed or shown but the relay owns their lifecycle — mutations are dead-letter-only.
- **`command.audit_reconcile` audit category.** Emitted on every `swarm:audit:reconcile` requeue, dismiss, and show action so triage is itself chain-of-custody evidence; payload carries `action`, `target_id`, `target_category`, `target_run_id`, `prior_attempts`, `reason` (required on dismiss, optional on requeue, omitted on show), `target_created_at`, `target_age_seconds`, and `target_payload_digest` (sha256 hex over the stored payload bytes, present on dismiss only so a dismissal can be cross-checked against a forensic backup without unsealing). Enumerated in `docs/audit-evidence-contract.md` as a frozen category. Custom `SwarmAuditSink` implementations that allowlist categories or schema-validate payloads must add `command.audit_reconcile` to their allowlist — without it, operator triage actions are silently dropped, exactly the records that exist to survive scrutiny.
- **`swarm.audit-outbox` Pulse Livewire card (#38).** New operator-facing dashboard card showing dead-letter count (red alarm if > 0), pending count, stale-pending count (amber alarm if > 0), oldest pending and dead-letter ages, and the configured retention setting; mirrors the `SwarmRuns` / `SwarmSteps` card layout already shipped by the package. Renders a neutral state with zero DB queries on cache persistence so dashboards do not error out under the unsupported driver. Documented in `docs/pulse.md` alongside the existing cards.
- **Operator runbook at `docs/operator-runbook-audit-outbox.md` (#45).** Scenario-driven 3am-triage walkthrough across five sections: dead-letter triage decision tree, stale-pending decision tree, sink-permanently-broken procedure, retention decision tree per regulatory regime (21 CFR Part 11, SOC 2, HIPAA — deliberately defers regime-specific retention guidance to compliance officers rather than asserting it), and forensic reconstruction (forward marker for `swarm:trace` in v0.7). Cross-linked from the README Production Checklist, `docs/maintenance.md`, and the `docs/README.md` index so operators discovering the package from any entry point find the runbook.
- **v0.5 audit-chain walkthroughs in `examples/durable-compliance-review/` and `examples/privacy-capture/` (#46).** `durable-compliance-review` gains a full simulated-outage scenario with baseline config, a `RecoveringSinkDemo` fixture that fails then heals, `swarm:audit:status` and `swarm:health --audit` output samples, recovery via `swarm:relay --type=audit`, and dead-letter triage via `swarm:audit:reconcile --show/--requeue/--dismiss`. `privacy-capture` gains a smaller "v0.5 Audit Chain For Redacted Evidence" section covering what `failure_policy=queue` means for redacted payloads and the privacy implications of `--show` on sealed outbox rows.
- **Integration test coverage for the v0.5 audit outbox flow (#39).** New `tests/Feature/AuditOutboxIntegrationTest.php` covering three end-to-end scenarios: DB-backed failing-sink → outbox → replay → dead_letter; cache-backed log-and-swallow fallback; and cross-lane `swarm:relay` draining both durable and audit lanes in a single pass. Extracted the existing `CountingThrowingSink` fixture from `tests/Unit/Audit/SinkFailureHandlerTest.php` into `tests/Fixtures/CountingThrowingSink.php` for reuse across the suite.

## v0.5.0 - 2026-05-19

### Added

- **Audit outbox + queue/dead-letter failure policies (#20).** Sink failures can now be persisted to the new `swarm_audit_outbox` table and retried via `swarm:relay --type=audit`. The relay command drains both the durable lane and the audit lane in a single pass (or each independently with `--type=step` / `--type=audit`). `SinkFailureDecision` gains `Queue` and `DeadLetter` cases. `ConfiguredSinkFailureHandler` recognizes `queue` and `dead_letter` failure-policy values in addition to the v0.4 `swallow` / `log` / `halt`. `swarm.audit.outbox.max_attempts` (default 5) controls how many retry passes a record gets before moving to the dead-letter status. `command.relay` evidence gains `audit_replayed_count` and `audit_dead_lettered_count` fields. Dead-letter transitions emit `Log::error` so monitoring stacks can alert on undelivered audit evidence. The `payload` and `last_error` columns are sealed when `swarm.persistence.encrypt_at_rest` is enabled.
- **Opt-in audit outbox retention.** `swarm.audit.outbox.dead_letter_retention_days` (env `SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS`, default `null`) controls automatic pruning of dead-letter records via `swarm:prune`. Default null preserves all dead-letter rows indefinitely so regulated callers do not silently erase compliance evidence. Pending and reserved rows are never pruned.
- **Audit outbox health checks.** `swarm:health` runs two new checks by default: pending staleness (relay running?) and dead-letter count (any undelivered evidence?). New `--audit` flag runs only the audit checks for focused incident investigation. Both checks skip silently on the cache persistence driver where the outbox is unavailable.

### Changed

- **BREAKING (audit defaults):** `swarm.audit.failure_policy` defaults to `queue` (was `swallow` in v0.4) (#20). When a bound `SwarmAuditSink` throws, failed evidence is now persisted to the audit outbox for retry instead of silently dropped. On database persistence, run `php artisan migrate` to create `swarm_audit_outbox` and schedule `swarm:relay` (the existing schedule drains both lanes). On cache persistence the dispatcher detects the unavailable outbox and falls back to log-and-swallow automatically. Set `SWARM_AUDIT_FAILURE_POLICY=swallow` to restore v0.4 behavior.
- **BREAKING (audit envelope):** `EvidenceEnvelope::SCHEMA_VERSION` bumps from `"1"` to `"2"` (#30). The bump signals a shape change on `command.*` evidence: the legacy top-level `actor` literal (`'actor' => 'artisan'`) is removed from `command.pause`, `command.resume`, `command.cancel`, `command.recover`, and `command.relay` payloads. Actor identity now flows through the standard `metadata.actor` slot as an `Actor` value object array, matching how every other category (`run.*`, `step.*`, `durable.*`) already exposes it. `swarm:prune` evidence, which previously carried no actor at all, now also emits `metadata.actor`. See `UPGRADING.md` v0.5.0 block for the migration walk-through.
- **REFACTOR (internal):** `SwarmRunner` (930 lines) decomposed into three focused collaborators (#21): `RunAuditEmitter` (centralizes run-level audit payload composition), `DispatchValidator` (dispatch-time validation), and `LeaseManager` (queue lease-seconds policy + durable coordination lease helpers). The class is `@internal` and the public API (`run`, `runQueued`, `stream`, `broadcast`, `queue`, `broadcastOnQueue`, `dispatchDurable`, `resumeQueuedHierarchicalAfterJoin`) is unchanged.

## v0.4.3 - 2026-05-19

### Added

- `SwarmFake` gains three new actor assertions (#34): `assertDispatchedWithActor(Actor|string|callable)`, `assertDispatchedWithAnyActor()`, and `assertNeverDispatchedWithActor()`. Helpers inspect every dispatch bucket (run, queue, durable, stream) for a `RunContext` whose `metadata.actor` matches. Bare-string and structured-array tasks never carry an actor — pass an explicit `RunContext::fromTask($task)->withActor(...)` when you want the binding to be visible to `SwarmFake`.
- New `## Testing Audit Extension Points` section in `docs/testing.md` documents the three patterns for testing the four v0.4 audit-extension contracts (`CapturePolicy`, `SinkFailureHandler`, `SwarmAuditSigner`, `ActorResolver`): unit-test the contract directly, bind a recording `SwarmAuditSink` for end-to-end audit checks, or use `'halt'` failure policy to assert run-level halt behavior. Worked examples reference the package's own `tests/Unit/Audit/` suite.
- New `## Asserting Actor Binding` section in `docs/testing.md` walks through the three new SwarmFake assertions with code samples, including the `Context::add('swarm:actor', ...)` + explicit `withActor()` pattern for tests that bridge the Laravel Context facade and SwarmFake.

### Changed

- Reframed the original #34 issue scope. The issue asked for assertions on all four v0.4 audit extension contracts, but only `Actor` has a dispatch-time signal SwarmFake can observe — the other three are runtime concerns inside the audit dispatcher, a path SwarmFake intentionally skips. The new docs section captures the three contracts' actual test patterns instead of pretending SwarmFake covers them.

## v0.4.2 - 2026-05-19

### Fixed

- Durable retry handler and branch advancer now log caught exceptions instead of swallowing them silently (#1). Previously, any exception thrown inside an agent's `prompt()` call (or any tool side-effect, broadcast dispatch, etc.) was caught and either rescheduled for retry or marked as a terminally failed branch with no entry in the application log, `failed_jobs`, or anywhere else — producing symptoms identical to transient LLM API failures and making the root cause invisible without reading the package source.
  - `DurableRetryHandler::scheduleRunRetryIfAllowed` and `scheduleBranchRetryIfAllowed` now emit `Log::warning('Durable swarm {step,branch} failed — scheduling retry.', [...])` before scheduling, with `run_id`, `retry_attempt`, `max_attempts`, `next_retry_at`, `exception` class, and `message` (plus `branch_id` / `agent_class` on the branch path).
  - `DurableBranchAdvancer` now emits `Log::error('Durable swarm branch failed — retries exhausted or non-retryable.', [...])` before calling `markBranchFailed`, with the same fields. The run-level terminal failure path already rethrows via `DurableStepAdvancer`, so failed-jobs / Laravel exception handler observability is already present there; the branch path was the silent one because its catch block returns normally after the failure.
- Both `DurableRetryHandler` and `DurableBranchAdvancer` now accept a constructor-injected `Psr\Log\LoggerInterface`. Container auto-resolution handles existing wiring; no service-provider changes required for application code.

## v0.4.1 - 2026-05-19

### Fixed

- `durable.*` audit categories now source `swarm_class` and `topology` from the durable run row instead of optional `RunContext::metadata` mirrors (#28). Previously these fields were typed `string|null` and would emit `null` whenever metadata was absent; they are now guaranteed non-null on all six `durable.*` categories (`durable.completed`, `durable.failed`, `durable.cancelled`, `durable.paused`, `durable.checkpointed`, `durable.checkpointed_hierarchical`). The change is implemented via two new helpers on `DurableRunContext` (`swarmClassFor()`, `topologyFor()`) that delegate to the existing `requireRun()` row lookup. Sinks that previously branched on null no longer need that branch.
- `command.relay` audit events with `status: "error"` now include `exception_class` (#32). The frozen schema in `docs/audit-evidence-contract.md` has documented this field since v0.4.0 but the emit in `SwarmRelayCommand` was missing it — the doc and the code now match.
- `durable.cancelled` audit events now include `duration_ms` (#33), bringing the cancelled category into parity with `durable.completed` and `durable.failed`. Computed via the existing `DurableRunContext::durationMillisecondsFor()` helper.
- `webhook.signal_received` audit events now include `swarm_class` (#29), bringing the category into parity with the sibling `webhook.start_*` categories. The field is plumbed through the new optional `swarmClass` property on `DurableSignalResult` — the property is nullable for backward compatibility with the public `FakeDurableSwarmManager` test fake, but is always populated in the production webhook path.

### Changed

- `docs/audit-evidence-contract.md` frozen schema updated: dropped `string|null` annotations on `durable.*` `swarm_class` and `topology`, added `duration_ms` to `durable.cancelled`, added `swarm_class` to the `webhook.signal_received` envelope. All marked with `(since v0.4.1)` so sinks know when the new shape became reliable.

## v0.4.0 - 2026-05-18

### Added

- **Actor / identity binding for audit evidence (#14).** New `BuiltByBerry\LaravelSwarm\Audit\Actor` value object (immutable id/type/name/metadata with `Actor::system()`, `Actor::user($authenticatable)`, and `Actor::fromAny()` named constructors). New `ActorResolver` contract bound by default to `DefaultActorResolver`, which reads `Context::get('swarm:actor')` first (survives queue serialization), then falls back to `auth()->user()`, then null. Resolution happens once at every dispatch entry point (`run`, `queue`, `broadcastOnQueue`, `dispatchDurable`, `stream`) so attribution captures dispatch-time identity rather than worker-time. The resolved actor is stored under the reserved `metadata.actor` key, which `EvidenceEnvelope` now emits on every audit record regardless of the configured allowlist. New `RunContext::withActor(Actor|Authenticatable|string|null)` fluent setter overrides the bound resolver. New `swarm.audit.actor.required` config flag (env `SWARM_AUDIT_ACTOR_REQUIRED`, default `false`) — when `true`, runs without a resolvable actor throw `MissingActorException` at entry. Regulated callers (21 CFR Part 11, SOC 2) enable the flag and bind actor via `Context::add('swarm:actor', $actor)` before dispatch.
- **`CapturePolicy` contract for declarative capture decisions (#15).** New `BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy` (multi-method: `inputs`, `outputs`, `artifacts`, `activeContext` — each taking `?RunContext` and `?Actor`). New `CaptureDecision` enum (`Full | Redact | Skip`). New `BooleanCapturePolicy` bound by default; reads the existing `swarm.capture.*` booleans and returns `Full` when true, `Redact` when false — preserves v0.3 capture behavior exactly. Custom policies bind via `$this->app->bind(CapturePolicy::class, MyPolicy::class)` and make per-run decisions with context and actor visibility. `SwarmCapture` is refactored to delegate every capture decision to the bound policy; the public v0.3 surface (`input()`, `output()`, `context()`, `step()`, `capturesInputs()`, etc.) is unchanged so all 17+ existing injection sites continue to work.
- **`SinkFailureHandler` contract with halt support (#16).** New `BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler` returns a `SinkFailureDecision` enum (`Swallow | RetryInline | Halt`). New `ConfiguredSinkFailureHandler` bound by default maps the existing `swarm.audit.failure_policy` config values plus a new `'halt'` value (alongside existing `'swallow'` and `'log'`). When the handler returns `Halt`, the dispatcher throws `AuditSinkHaltedException`, which carries the new `HaltsSwarmExecution` marker interface — `SwarmRunner` detects the marker and surfaces the halt as a deliberate run-level failure rather than swallowing it as an audit concern. The dispatcher retry loop is capped at `SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS = 5` to prevent runaway loops from buggy custom handlers; exceeding the cap throws a `SwarmException` with the original sink failure as `$previous`. `Queue` and `DeadLetter` decision cases are reserved for v0.5 alongside the audit outbox.
- **`SwarmAuditSigner` contract for tamper-evident evidence (#13).** New `BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner` is invoked in the dispatcher after envelope enrichment and before sink emit. No default binding — when no signer is bound, the dispatcher emits payloads unchanged (v0.3 behavior preserved). Custom signers attach cryptographic signatures (HMAC, ECDSA, chain-signed hashes) to every audit record; implementations must not mutate or remove existing keys, only add signature fields. Signing failures route through the same `SinkFailureHandler` as sink failures, so callers who want strict halt-on-signing-failure set `swarm.audit.failure_policy=halt`. Signing scope (entire payload vs canonical subset), algorithm, and chain-signing semantics are implementation concerns.
- **Audit evidence envelope schema freeze.** `docs/audit-evidence-contract.md` now formally commits to the v0.x envelope shape: `schema_version`, `category`, `occurred_at`, plus the enumerated category-specific correlation fields. Additive changes (new fields, new categories) ship within the v0.x line; breaking shape changes increment `schema_version` and land in a future minor with a per-version UPGRADING block. Sinks that parse strictly should switch on `schema_version`.
- **`@internal` PHPDoc applied across the codebase.** 93 internal classes marked: runners, persistence stores, jobs, dispatchers, telemetry helpers, support utilities, Pulse Livewire components, and routing internals. Public surface (Facades, contracts, value objects, response DTOs, events, exceptions, Artisan commands, Pulse Recorders, `SwarmServiceProvider`, `RunContext`) deliberately left unmarked and committed to per the new `## Stability and the public API` section in `UPGRADING.md`. Consumers can now grep `@internal` to distinguish package internals from the stability surface.

### Changed

- `SwarmAuditDispatcher` constructor signature changed: now takes `(SwarmAuditSink, ConfigRepository, SinkFailureHandler, ?SwarmAuditSigner)` — `LoggerInterface` is no longer injected directly (it moves into `ConfiguredSinkFailureHandler`). The dispatcher is marked `@internal`; application code that resolves it via the container is unaffected.
- `SwarmCapture` constructor signature changed: now takes `(ConfigRepository, CapturePolicy)`. The class is marked `@internal`; container-resolved usage is unaffected.
- `SwarmRunner` constructor adds an `ActorResolver` dependency. The runner is marked `@internal`; container-resolved usage is unaffected.
- `composer.json` `extra.branch-alias.dev-main` bumped from `0.3.x-dev` to `0.4.x-dev`.

### Deprecated

- The four `swarm.capture.*` boolean config keys (`capture.inputs`, `capture.outputs`, `capture.artifacts`, `capture.active_context`) are deprecated in favor of binding a custom `CapturePolicy`. The booleans remain functional through `BooleanCapturePolicy` and are scheduled for removal in v0.5 with a per-version UPGRADING block.

## v0.3.5 - 2026-05-18

### Added

- `swarm:health --durable` now proactively checks `SWARM_CAPTURE_ACTIVE_CONTEXT` and reports `failed` when the env var is not enabled. Queued and durable dispatch require active-context capture; the runner has always thrown `SwarmException` at dispatch when it is missing, but the misconfiguration is now caught at preflight rather than at the first live run. CI/CD pipelines that gate on `swarm:health --durable` exit code may newly exit `1` in environments where the env was implicitly relied on but unset — see [UPGRADING.md](UPGRADING.md#swarmhealth---durable-now-fails-on-missing-swarm_capture_active_context).
- New `docs/app-key-rotation.md`: runbook covering the encryption asymmetry between sealed operational rows and unaffected audit evidence, the drain-then-rotate and in-place re-encryption strategies, and how retention windows interact with key rotation. Cross-linked from `docs/configuration.md`, `docs/persistence-and-history.md`, and the docs index.
- New `docs/metadata-allowlist-governance.md`: governance doc covering metadata vs capture payloads, named anti-patterns (raw user identifiers, regulated product names, authentication material, high-cardinality free text, mutable PII buckets), and the allowlist review checklist. Cross-linked from `docs/audit-evidence-contract.md`, `docs/observability-correlation-contract.md`, and the docs index.
- `.github/workflows/nightly.yml`: informational nightly job runs the full suite against `laravel/framework:dev-main` with as-aliased `illuminate/*` packages so Composer accepts them against the package's `^13.0` constraints. Marked `continue-on-error: true`; surfaces upstream churn without gating PR CI. README gets a dedicated nightly status badge.
- `.github/workflows/mutation.yml`: daily Pest mutation testing job via `pestphp/pest-plugin-mutate` (already a Pest 4 transitive). Runs at 07:17 UTC plus `workflow_dispatch` for on-demand. 120-minute timeout cap; `continue-on-error: true`. A gating threshold via `--min` will land in a follow-up once baseline scores stabilise.

### Changed

- README production checklist surfaces `swarm:relay` alongside `swarm:recover` and `swarm:prune` with frequency, purpose, and a cross-link to `docs/durable-execution.md`. Operators wiring durable swarms from the README no longer have to discover separately that the outbox drain command must be scheduled.
- README and UPGRADING document the `minimum-stability: dev` / `prefer-stable: true` propagation requirement explicitly, the rationale (`laravel/ai` is pre-1.0 and ships dev-tagged releases), and the plan to drop the requirement when `laravel/ai` reaches 1.0.
- `examples/README.md` clarifies that example files are reference-only and must be copied into the consuming application's namespace before use. Previously the autoload behavior was implied but never stated.
- CI enforces an 80% line-coverage floor via the new `composer test:coverage:ci` script (Pest `--coverage --min=80`). Local `composer test:coverage` is left unchanged so iteration is not slowed by an enforced threshold. The 80% floor is set deliberately below currently measured coverage so the gate enforces rather than aspires; raise it deliberately as the suite grows.
- `composer test:mutation` swapped from `infection/infection` to Pest's native mutation plugin (`pestphp/pest-plugin-mutate`). Infection 0.33's auto-generated PHPUnit config emits the legacy `<filter>` element, which Pest 4 rejects — producing a false negative even when every test passes. The Pest-native path integrates with the existing test runner and removes the XML mismatch.

### Fixed

- `tests/Feature/SwarmRecoverCommandTest.php`: applied missed Pint formatting.

## v0.3.4 - 2026-05-15

### Added

- `make:swarm` now scaffolds all four topologies (sequential, parallel, hierarchical, static-hierarchical). Previously only sequential and static-hierarchical were supported.
- `make:swarm` now prompts interactively for topology when run from a TTY without `--topology`. Pass `--topology=sequential` to preserve the previous non-interactive behavior in scripts. Non-interactive callers (`Artisan::call()`, piped stdin) continue to default to sequential.
- New published stubs: `swarm.hierarchical.stub` and `swarm.parallel.stub` — available via `php artisan vendor:publish --tag=swarm-stubs`.
- `swarm:recover` now warns when outbox rows are aging past the staleness threshold without being relayed. Surfaces the message "N outbox row(s) aging past Xs without being relayed — is swarm:relay scheduled?" to help operators detect an unscheduled relay.
- Full documentation audit: 9 new reference docs (`introduction.md`, `sequential.md`, `parallel.md`, `execution-modes.md`, `events.md`, `run-context.md`, `artifacts.md`, `error-handling.md`, `configuration.md`), 4 expanded docs (`pulse.md`, `guardrails.md`, `durable-execution.md`, `observability-logging-tracing.md`), new `examples/static-hierarchical-content-swarm/` example, and a restructured `docs/README.md` with audience-based navigation.

### Changed

- Package homepage and Packagist documentation link now point to the new documentation site at https://swarm.builtbyberry.com. `composer.json` `homepage` was updated and a new `support.docs` entry was added. README adds a documentation-site badge, a top-of-file callout, and promotes the docs site to the first bullet in the metadata list; the in-repo `docs/README.md` is now labeled "In-repo docs" and recommended for offline use.

## v0.3.3 - 2026-05-15

### Added

- **StaticHierarchical topology** (`Topology::StaticHierarchical`): eliminates the coordinator LLM
  call when routing is static. Swarms carry `#[Topology(TopologyEnum::StaticHierarchical)]`,
  implement the new `HasRoutePlan` contract, and return a fixed route-plan array from `plan()`.
  Supports `prompt()`, `run()`, `queue()` (in_process), and `stream()`. `dispatchDurable()` is
  unsupported in v1 and throws before any infrastructure check fires.
  - Sequential worker nodes always stream live text deltas.
  - Parallel groups in `stream()` use `concurrent` mode by default (branches run via
    `ConcurrencyManager`; the sequential tail streams live) or `sequential` mode (each branch
    streams in declaration order). Controlled by `#[StreamParallelBranches('concurrent'|'sequential')]`
    on the swarm class, or the `swarm.static_hierarchical.stream_parallel_branches` config key
    (`SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES`).
  - Same parallel groups, `with_outputs` named-output synthesis, DAG validation, and `MaxAgentSteps`
    enforcement as hierarchical. `MaxAgentSteps` counts worker nodes only — there is no coordinator
    step.
  - Swarm response and `SwarmCompleted` metadata include `topology`, `route_plan_start`,
    `executed_node_ids`, `executed_agent_classes`, `parallel_groups`, `executed_steps`, and
    `execution_mode`. `coordinator_agent_class` is intentionally absent.
  - Documentation: `docs/static-hierarchical-topology.md`.

## v0.3.2 - 2026-05-13

### Changed

- Test suite verified against `laravel/ai` v0.6.8. Two test fixtures were relying on an implicit per-class agent fake fallback removed in v0.6.8; they now explicitly fake all agents in the swarm.

### Added

- `swarm:relay --max-attempts=N`: limits the number of drain iterations when used with `--drain-until-empty`. Without this flag the loop continues only while there is real progress; with it, the loop also retries through batches of pure transient failures up to N times total, making it suitable for clearing backlogs during a recovering queue outage. Iterations run consecutively with no sleep — size N accordingly.
- `swarm:health --durable` outbox staleness check now reports three states: "no pending rows" (ok), "N pending rows, relay appears active" (ok), and "N rows aging past {threshold}s — is swarm:relay scheduled?" (warning). Previously the check was binary ok/warning with a single threshold.
- `swarm.durable.relay.stale_warning_threshold_seconds` config key (`SWARM_DURABLE_RELAY_STALE_WARNING_THRESHOLD_SECONDS`). `0` falls back to `2 × reservation_timeout_seconds` (backwards-compatible default).
- Static "Relay scheduling" note row added to `swarm:health --durable` output. Reminds operators that `swarm:relay` must be scheduled. Status is `note` (informational only; does not affect exit code).
- `swarm:health --durable` now includes an **Outbox queue routing** check: warns when outbox rows reference a `queue_connection` not present in `config/queue.php`. Rows with an unknown connection are permanently skipped at drain time; this surfaces them before they accumulate silently.
- `DrainResult` gains two new fields: `claimed` (rows reserved in phase 1) and `reclaimed` (subset whose `reserved_at` was already set, indicating a prior relay worker did not complete dispatch). Both default to `0` (backwards-compatible). Custom `DurableOutbox` implementations may populate these fields when returning `DrainResult`; applications that only consume the result need no changes.
- `swarm:relay` audit payload now includes `claimed_count` and `reclaimed_count` alongside the existing `dispatched_count`, `skipped_count`, and `failed_count`.
- `swarm.limits.max_metadata_bytes` config key (`SWARM_MAX_METADATA_BYTES`; `null` = uncapped). Enforced via `SwarmPayloadLimits::checkMetadata()` at run start in `SwarmRunner` and `DurableSwarmStarter`. The `truncate` overflow policy does not apply to metadata; only `fail` fires when the limit is exceeded.
- Documented recommended queue topology patterns in `docs/maintenance.md`: minimal (single queue), durable sequential (separate worker pool), and durable with parallel branches (third pool to prevent saturation deadlock on saturated step queues).
- Added `## Metadata Governance` section to `docs/audit-evidence-contract.md` covering the allowlist approach, custom sink redaction example, and scope clarification (sink-layer only; does not affect `RunContext` or database capture).

### Fixed

- `swarm:relay` / `DatabaseDurableOutbox::drain()` reported a false green when all entries in a batch failed transiently (queue driver down): `total()` returned 0, `--drain-until-empty` exited, and the command printed "No pending outbox entries were found." The new `DrainResult::$failed` counter tracks transient failures separately; the command now exits with status 1 and a descriptive warning when unresolved transient failures remain at exit, and the "no pending entries" message is only printed when the outbox is genuinely empty.
- `DatabaseDurableOutbox::drain()` silently rerouted entries with an unknown `queue_connection` to the application default queue instead of treating them as permanently invalid. An unknown stored connection name is now an `UnexpectedValueException` (row deleted, reported, counted as `skipped`) — the same contract as an unknown `dispatch_type`. The previous behaviour undermined queue-isolation guarantees.
- `DatabaseDurableOutbox::dispatchEntry()` blindly cast missing or non-integer `step_index` payload fields to `(int)` (giving step 0) and missing or empty `branch_id` fields to `(string)` (giving `''`). Both cases are now validated and throw `UnexpectedValueException` when the required field is absent or the wrong type, correctly treating the row as permanently invalid rather than dispatching the wrong job.

## v0.3.1 - 2026-05-12

### Fixed

- `swarm:relay` / `DatabaseDurableOutbox::reserve()` threw `BadMethodCallException: Call to undefined method Builder::skipLocked()` on Laravel 13 with Postgres or MySQL. `FOR UPDATE SKIP LOCKED` must be expressed as a string passed to `->lock()` — there is no chainable `skipLocked()` method on `Illuminate\Database\Query\Builder`. ([#3](https://github.com/builtbyberry/laravel-swarm/issues/3))

## v0.3.0 - 2026-05-12

### Added

- **Transactional outbox for durable dispatch:** `DurableOutbox` contract and `DatabaseDurableOutbox`
  implementation write outbox rows atomically inside checkpoint, branch-wait, and retry transactions.
  `swarm:relay` (new Artisan command) drains and dispatches them; it must be scheduled
  (`Schedule::command(‘swarm:relay’)->everyMinute()`). Two-phase drain (claim with `SKIP LOCKED`,
  dispatch, batched delete) prevents duplicate jobs under concurrent relay workers. Per-entry error
  isolation means a single bad row cannot poison the batch. Fixes the parallel-topology join-boundary
  stall (GitHub issue #2).
- `swarm:relay` Artisan command: drains the durable outbox and dispatches queued jobs. Options:
  `--type=step|branch` (filter by dispatch type), `--limit=N` (default 100, max 10 000),
  `--drain-until-empty` (loop until the outbox is clear). Run `swarm:relay --help` for details.
- `swarm:health --durable` now includes an **Outbox relay** check: warns when unclaimed or
  stale-reserved outbox rows are older than 2× `swarm.durable.relay.reservation_timeout_seconds`,
  helping detect a stalled relay or a relay worker that crashed mid-dispatch.
- `swarm:prune` now prunes orphaned `swarm_durable_outbox` rows (rows whose parent run has expired
  and been pruned but whose outbox entry was not cascade-deleted, e.g. reserved rows that expired).
- Migration `2026_05_11_000002_optimize_swarm_durable_outbox_indexes.php`: replaces the composite
  `swarm_outbox_drain_idx` with two targeted indexes—`(available_at, id)` for unfiltered drains and
  `(dispatch_type, available_at, id)` for type-filtered drains—plus a PostgreSQL partial index on
  `(available_at, id) WHERE reserved_at IS NULL`.
- **Guardrails v1:** `SwarmInputGuardrail`, `SwarmStepGuardrail`, `SwarmOutputGuardrail`, optional
  `DefinesGuardrails::guardrails()`, centralized `SwarmGuardrailRunner`, `GuardrailViolation`
  (`policyCode`, `reason`, `metadata`, `scope`, `::block()`),
  `config/swarm.php` `guardrails.*` (including child inheritance and parallel sync policy), wiring through
  `SwarmRunner`, durable starters, sequential/parallel/hierarchical/stream/durable paths, and
  `MissingQueueLeaseSchemaException` for missing queued-lease columns (distinct from runtime
  `LostSwarmLeaseException`). Documentation: `docs/guardrails.md`. Feature and unit tests under
  `tests/Feature/Guardrails*.php`, `tests/Unit/Runners/SwarmGuardrailRunnerTest.php`,
  `tests/Unit/Exceptions/GuardrailViolationTest.php`.

### Changed

- **Breaking:** `DurableOutbox::drain()` now returns `DrainResult` (`Responses\DrainResult`) instead
  of `int`. `DrainResult` exposes `dispatched` (entries queued successfully), `skipped` (permanently
  invalid entries deleted without dispatch), and `total()`. Custom `DurableOutbox` implementations
  must update their return type and return a `new DrainResult(dispatched: $n, skipped: $m)` instance;
  applications that only inject the contract need no changes.
- `DrainResult` lives in the `Responses\` namespace, not `Contracts\`.
- `DatabaseDurableOutbox::drain()` now uses a two-catch dispatch loop: `UnexpectedValueException`
  signals a permanently invalid row (unknown `dispatch_type`, non-array or malformed JSON payload) —
  the entry is reported via `report()`, deleted immediately, and counted in `skipped`. Any other
  `Throwable` is treated as transient — the entry retains `reserved_at` and is re-claimable after the
  reservation timeout.
- `DatabaseDurableOutbox::drain()` collects dispatched IDs and issues a single batched `WHERE IN`
  DELETE at the end of each loop iteration instead of one DELETE per dispatched entry.
- `command.relay` audit event field renamed from `dispatched` to `dispatched_count`; the failure-path
  event now also includes `dispatched_count` and `skipped_count` reflecting entries processed before
  the error.
- `DurableRunRecorder::checkpointSequential` and `checkpointHierarchical` accept an optional
  `?callable $withTransaction` that is invoked inside the DB transaction before commit, enabling
  atomic checkpoint + outbox writes. This is an `@internal` API used by collaborators only.
- `DurableHierarchicalCoordinator::checkpointBranchWait` similarly accepts `?callable $withTransaction`
  for atomic branch-wait + outbox enqueue.
- `DurableRetryHandler` now enqueues zero-delay retries inside the retry transaction rather than after
  it, closing the crash window between retry state commit and dispatch.
- `DurableLifecycleController::resume` now uses the freshly loaded post-resume run row for queue
  routing instead of the stale pre-resume snapshot.
- `DurableRecoveryCoordinator::recover` `$dispatchStep` and `$dispatchBranch` parameters are now
  required (no default closures). This is an `@internal` change.
- `QueuedHierarchicalDurableCoordinator` delegates `validateStepTimeoutSeconds` to `DurableRunContext`
  instead of maintaining a duplicate copy.
- `GuardrailViolation` uses a `policyCode` property instead of `code`, avoiding collision with PHP’s
  inherited `Exception::$code`.

### Fixed

- `swarm:health --durable` outbox relay check now detects stale-reserved rows — entries claimed by a
  relay worker that crashed mid-dispatch — in addition to unclaimed rows. Previously a crashed relay
  appeared healthy for up to the full reservation timeout window.
- `DurableRetryHandler::scheduleBranchRetryIfAllowed` now wraps its state change in a transaction,
  consistent with the run retry path. Fixes a narrow window where a branch retry state change could
  be written without the corresponding outbox enqueue completing atomically.
- Output-phase guardrail violations are handled inside `SwarmRunner::runWithExecutionMode()`’s primary
  `try` so failures call `historyStore->fail`, emit `SwarmFailed`, and merge safe guardrail metadata—same
  as other orchestration failures (previously `finalizeSuccessfulSwarmExecution()` sat outside that
  `try`, so `GuardrailViolation` could escape without lifecycle handling). The queued hierarchical
  resume-after-join success path wraps finalization the same way.
- `queue()` and `broadcastOnQueue()` now persist a preflight failure row and dispatch `SwarmFailed`
  when an input guardrail blocks at dispatch time (previously the violation was thrown without any
  history or event being written).
- `dispatchDurable()` now persists a preflight failure row and dispatches `SwarmFailed` when an input
  guardrail blocks before the durable transaction runs (previously the violation escaped without any
  history row).
- Stream input guardrails now fire eagerly at `stream()` call time, before `StreamableSwarmResponse`
  is constructed. Previously they ran lazily inside the generator and only fired when the caller began
  iterating, leaving a window where a blocked stream was returned without any history or event written.
- `own_global_and_parent` child-inheritance mode now logs a `warning` (via injected `LoggerInterface`)
  when the parent swarm class cannot be found or resolved from the container, instead of silently
  dropping parent guardrails.

## v0.2.0 - 2026-05-05

### Added

- Durable workflow controls: native wait/signal, pause/resume/cancel/recover,
  progress, labels/details, child swarms, authenticated webhooks, webhook
  idempotency retention, and operator commands including `swarm:inspect`,
  `swarm:progress`, `swarm:signal`, and `swarm:health`.
- Durable runtime hardening: multi-wait timeout recovery, retry dispatch
  deduplication, configurable durable job tries/timeouts/backoff, coordinated
  hierarchical parallel execution for `queue()` via `multi_worker`, durable
  state side tables, and database-level run-id foreign keys.
- Enterprise evidence and telemetry hooks: `SwarmAuditSink`,
  `SwarmTelemetrySink`, schema-versioned evidence envelopes, lifecycle and
  operator evidence categories, queue timing telemetry, stream/broadcast event
  telemetry, and metadata allowlists.
- Persistence and retention controls: `swarm:prune --dry-run`,
  `swarm.retention.prevent_prune`, database encrypt-at-rest sealing with
  `sw0:` prefixes, decrypt failure policy configuration, and cache/database
  readiness probes.
- Release-quality guardrails: `LaravelSwarm::ignoreMigrations()`, PHPStan level
  7, coverage and process-concurrency CI lanes, serializing concurrency test
  coverage, and `composer test:process-concurrency`.
- Webhook callback auth is now a release-ready driver. `callback` supports
  native callables, invokable classes resolved through the container, and
  `Class@method` strings resolved through the container; only strict `true`
  authorizes a request.

### Changed

- **Breaking:** `swarm.capture.inputs`, `outputs`, `artifacts`, and
  `active_context` now default to **false**. Enable the needed
  `SWARM_CAPTURE_*` values for persisted prompts/outputs; queued and durable
  execution require `active_context=true`.
- **Breaking (extend-only):** `DatabaseContextStore`, `DatabaseRunHistoryStore`,
  and `DatabaseDurableRunStore` constructors now accept
  `SwarmPersistenceCipher`; custom subclasses or manual construction must pass
  the cipher from the container.
- `SwarmPersistenceCipher` now injects `Psr\Log\LoggerInterface`; decrypt
  failures follow `swarm.persistence.decrypt_failure_policy`
  (`null_with_log`, `legacy`, or `throw`) instead of always returning opaque
  ciphertext.
- Durable step advancement internals were decomposed into focused collaborators
  while preserving public manager, job, event, response, and persistence
  contracts.
- Completed database run-history context now seals `context.input`, and
  `SequentialStreamRunner` now writes history before context to satisfy FK
  ordering.
- GitHub Actions now covers stable-latest and lowest dependency lanes for the
  PHP 8.5 / Laravel 13 support range, with coverage, Pint, PHPStan, and
  process-concurrency checks.

### Documentation

- Reworked the README and examples around a Laravel-style learning path:
  install, create a swarm, run it, choose an execution mode, then add
  production operations.
- Added a documentation index and public surface coverage matrix mapping swarm
  methods, responses, attributes, testing helpers, durable manager operations,
  Artisan commands, and extension points to their canonical guides.
- Expanded durable waits/signals, retries/progress, child swarms, and webhooks
  into full user guides with prerequisites, copy-paste examples, edge cases,
  testing notes, and related documentation.
- Added focused examples for stream broadcasting, durable waits/signals, durable
  retries/progress/child swarms, and durable webhook ingress.
- Added a flagship human-in-the-loop support review example showing durable
  waits, app-owned broadcast notifications, review endpoints, signal handling,
  and frontend pseudocode.
- Added or expanded guides for upgrading, durable runtime architecture,
  workflow operations, durable webhooks, observability logging/tracing,
  observability correlation, audit evidence, operational query contracts,
  persistence/privacy, migration/FK safety, and production maintenance.
- Clarified Composer stability expectations for `laravel/ai`, release
  discipline, README badges, Packagist guidance, and human contributor entry
  points.
- Removed internal package-review notes from distributed documentation; release
  docs now include only user-facing and contributor-facing package guidance.

### Security

- Hardened durable webhook token auth so blank configured tokens cannot match
  blank bearer tokens.
- `auth.driver=none` fails during route registration outside `local` and
  `testing`, unsupported webhook auth drivers fail during route registration,
  and callback auth now fails closed for blank, malformed, missing, or
  non-callable callback configuration.

## v0.1.10 - 2026-05-01

### Documentation

- Documented dependency and upgrade expectations for PHP, Laravel, and
  `laravel/ai` in `README.md` and `AGENTS.md` (integration testing after Composer
  bumps; changelog covers Swarm-owned changes only).
- Added `CONTRIBUTING.md` with contributor workflow, maintainer ownership,
  review expectations, and release discipline guidance.

### Added

- **Coordinated hierarchical parallel for `queue()`:** optional
  `swarm.queue.hierarchical_parallel.coordination` (`in_process` default,
  `multi_worker` opt-in) and `#[QueuedHierarchicalParallelCoordination]` for
  per-swarm overrides. Multi-worker mode reuses durable branch storage, leases,
  join, `AdvanceDurableBranch`, `ResumeQueuedHierarchicalSwarm`, cancel, and
  `swarm:recover`; public lifecycle metadata stays `execution_mode: queue`.
- Migration adding `coordination_profile` to `swarm_durable_runs` (indexed;
  default `step_durable`) plus `CoordinationProfile` enum.
- `ClaimsQueuedRunExecution::acquireQueuedRunContinuationLease()` for resuming
  the primary history lease after a parallel join.

### Changed

- `DatabaseDurableRunStore::recoverable()` excludes
  `queue_hierarchical_parallel` coordination rows so recovery does not dispatch
  `AdvanceDurableSwarm` for queue-only coordination parents.

## v0.1.9 - 2026-04-29

### Added

- Added Laravel AI-style swarm stream broadcast helpers:
  `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()`. These helpers are
  sequential-only and broadcast typed swarm stream events rather than lifecycle
  events for every topology.
- Documented and tested broadcast transport failures, including pre-terminal
  failures that fail run history and terminal delivery failures that leave
  completed swarm history intact while failing the helper or queued job.

## v0.1.8 - 2026-04-29

### Breaking / Contract Changes

- Added `StreamEventStore::forget(string $runId)` so replay stores can
  invalidate already-written events when replay persistence is disabled after a
  partial write failure. Custom `StreamEventStore` implementations must add this
  method.

### Added

- Added `docs/streaming.md` as the canonical `stream()` guide and cross-linked it
  from the README, persistence, testing, structured input, examples, and agent
  context.
- Added `swarm.streaming.replay.failure_policy` /
  `SWARM_STREAM_REPLAY_FAILURE_POLICY` with `fail` as the default and
  `continue` as an opt-in mode for continuing live streams when replay
  persistence fails.

### Fixed / Hardened

- Hardened persisted stream replay failure handling so `fail` marks the live run
  failed coherently, while `continue` discards partial replay events before
  continuing without persisted replay for that response.

## v0.1.7 - 2026-04-28

### Added

- Added a composite replay lookup index on `swarm_stream_events(run_id, id)`
  to keep replay scans ordered and efficient as event volumes grow
- Added typed streamed event coverage for final-agent non-text upstream events:
  `swarm_text_end`, `swarm_reasoning_delta`, `swarm_reasoning_end`,
  `swarm_tool_call`, and `swarm_tool_result`
- Added a dedicated `SequentialStreamRunner` orchestration path to separate
  sequential streaming flow from non-stream execution paths

### Changed

- Updated persistence/history documentation to explicitly state that
  `swarm.limits.max_output_bytes` applies to persisted replay event payloads in
  addition to step/history and lifecycle event output surfaces
- Documented streaming overflow `fail` behavior so operators know earlier
  deltas can be emitted before terminal events are omitted after overflow
- Updated streaming docs and examples with the expanded event schema and
  provenance-first replay behavior for upstream final-agent streamed events

### Fixed / Hardened

- Removed duplicate streamed step-end output limit application by deriving
  `SwarmStepEnd` output from the existing recorded step output path
- Hardened streaming tests with resilient agent-based assertions and added
  coverage for replay payload limits and overflow fail replay behavior
- Preserved upstream event IDs and timestamps for typed final-agent streamed
  events in replay payloads
- Hardened streamed reasoning/tool payload redaction by preserving keys while
  replacing values with `[redacted]` when output capture is disabled

## v0.1.6 - 2026-04-26

### Added

- Added database-backed durable operational state for application-owned
  inspectors, dashboards, operators, and future connectors
- Added durable runtime columns for execution mode, route start/current node,
  completed node IDs, node states, failure metadata, attempts, lease
  timestamps, recovery counters, operator control timestamps, timeout state,
  queue routing, and terminal timing
- Added persisted hierarchical route plan and route cursor visibility for
  active durable runs so inspectors can report route progress while recovery
  still has the raw data it needs
- Added durable runtime node-state tracking for coordinator, sequential step,
  worker, completed, failed, paused, cancelled, and leased states
- Added durable runtime inspection coverage for active and terminal durable
  runs through the existing durable store surface

### Changed

- Documented durable runtime inspection as neutral durable operational state for
  application-owned dashboards, operators, and future connectors
- Added the `DurableRunStore::find()` documentation path for durable runtime
  inspection while keeping `SwarmHistory` as the stable history surface
- Changed terminal hierarchical durable runs to retain an inspection-safe route
  projection instead of the raw active route plan
- Clarified that cache-backed persistence does not provide the durable runtime
  inspection surface
- Updated durable execution, persistence/history, and hierarchical routing docs
  to describe active route-plan sensitivity and terminal route projection
  behavior

### Fixed / Hardened

- Redacted durable runtime failure metadata through the existing capture policy
  before persisting run failure and node failure state
- Removed the one-off `RecordsDurableRunFailureMetadata` capability contract and
  folded redacted failure metadata into the durable store contract
- Hardened terminal durable completion, failure, and cancellation so route-plan
  projection replacement and durable node-output deletion happen atomically
- Hardened terminal hierarchical durable records so worker prompts, finish
  literal output, and node metadata are not retained after completion, failure,
  or cancellation
- Deleted intermediate durable node-output rows at terminal states while
  retaining sanitized route/cursor/node inspection state
- Made durable recovery scans pure queries and moved recovery bookkeeping to an
  explicit `markRecoveryDispatched()` call after redispatch succeeds
- Guarded recovery bookkeeping so stale recovery results cannot mutate terminal
  durable runs
- Preserved existing history inspection APIs while adding the durable runtime
  inspection surface additively

## v0.1.5 - 2026-04-26

### Added

- Added durable hierarchical execution through `dispatchDurable()` using a
  persisted route plan and route cursor
- Added durable hierarchical node-output persistence with one row per node
  output instead of a growing runtime JSON blob
- Added targeted durable hierarchical node-output reads for `with_outputs` and
  finish-node `output_from` dependencies

### Changed

- Extended durable execution support from sequential swarms to sequential and
  hierarchical swarms
- Hierarchical durable parallel groups execute branch workers sequentially in
  declaration order for v1 while keeping the same parallel-safe validation
  rules as synchronous hierarchical execution
- Split durable checkpoint persistence into an internal recorder so the durable
  manager owns orchestration flow while checkpoint, terminal, pause, resume, and
  artifact persistence stay transactionally grouped
- Added an upgrade note for the `swarm_contexts.input` `longText` migration:
  large production tables should run package migrations during a maintenance
  window, and rolling this column back to `text` can fail once long prompts have
  been stored

### Fixed / Hardened

- Hardened durable hierarchical checkpoints so route cursor advancement,
  context persistence, node-output persistence, artifact persistence, history
  sync, and durable `next_step_index` advancement commit atomically
- Hardened terminal durable completion, failure, and cancellation so runtime
  route plans, route cursors, and durable node-output rows are cleared together
  with terminal history/context persistence
- Hardened durable pause and resume so runtime state and history cannot drift if
  history sync fails
- Preserved accumulated usage across durable hierarchical jobs before
  checkpointing the next step
- Redacted durable hierarchical cursor data from captured terminal history and
  context when output capture is disabled
- Hydrated persisted hierarchical route plans defensively with package-level
  `SwarmException` messages when runtime state is malformed, including invalid
  control references and output dependencies

### Documentation

- Updated durable execution, hierarchical routing, structured input, maintenance,
  README, and example documentation for durable hierarchical support
- Documented that durable fan-out/fan-in remains out of scope for this release

## v0.1.4

### Breaking / Contract Changes

- Laravel 13 is now enforced through explicit `illuminate/*:^13.0` component constraints
- Structured task arrays, explicit context data, context metadata, and persisted artifact payloads must now be plain data only: strings, integers, floats, booleans, null, and arrays containing only those values
- Objects, enums, closures, resources, `JsonSerializable`, and `Stringable` values are rejected at queue and persistence boundaries instead of being serialized or cast
- Invalid global or per-store persistence drivers now fail clearly instead of silently falling back to cache
- Sequential, parallel, hierarchical, and streamed swarms with no agents now throw a `SwarmException` instead of returning successful empty or unchanged responses
- `#[Timeout]`, `#[MaxAgentSteps]`, and `SWARM_DURABLE_STEP_TIMEOUT` values must be positive integers
- Parallel swarm agents must be container-resolvable by class; parallel execution resolves agents inside Laravel Concurrency workers instead of capturing configured agent instances
- Hierarchical swarms now require unique worker classes after the coordinator
- `queue()` now validates topology, timeout, max steps, empty agents, parallel resolvability, and hierarchical worker uniqueness before dispatching

### Added

- Added durable sequential execution with `dispatchDurable()`, `DurableSwarmResponse`, durable runtime storage, one-step-per-job advancement, and recovery-safe checkpointing
- Added durable pause, resume, cancel, and recover commands, plus `DurableSwarmManager` controls for application UIs
- Added coordinator-driven hierarchical DAG routing with validated worker, parallel, and finish nodes
- Added capture privacy controls for inputs and outputs using `SWARM_CAPTURE_INPUTS`, `SWARM_CAPTURE_OUTPUTS`, and `[redacted]` event/persistence values
- Added durable runtime table migration and configuration for durable queue routing, step timeout, and recovery grace
- Added a migration changing `swarm_contexts.input` to `longText`
- Added Larastan/PHPStan configuration and `larastan/larastan` as a required development quality gate
- Added GitHub Actions CI for Pest, Larastan/PHPStan, and Pint
- Added release-ready examples for sequential, queued, streamed, tested, parallel, hierarchical, durable, privacy-sensitive, run-inspector, and operations-dashboard swarm patterns

### Changed

- Replaced the full `laravel/framework` runtime Composer constraint with explicit Laravel 13 Illuminate component constraints
- Reworked hierarchical execution from placeholder routing into a validated route-plan runtime with explicit coordinator schema expectations
- Updated package migration publishing to use Laravel 13's migration publishing path while continuing to auto-load package migrations
- Updated repository packaging metadata with `.gitattributes`, a stronger `.gitignore`, Composer branch aliasing, and package-style lock-file hygiene
- Changed database context writes to use the same normalized `RunContext::toArray()` shape as cache-backed context persistence
- Changed database context persistence to use `updateOrInsert()` instead of an exists-then-insert flow
- Updated parallel execution to capture scalar task and class data only before resolving each agent in the concurrency worker
- Redacted terminal persisted context snapshots, failure messages, events, and automatic artifacts according to capture settings while keeping live agent handoff and returned responses unchanged
- Improved Pulse run and step metrics aggregation and documented how Pulse complements application-owned lifecycle dashboards

### Fixed / Hardened

- Hardened artifact persistence with strict payload normalization for both cache and database repositories, including clear failures for non-array metadata and invalid nested metadata values
- Hardened prune behavior for missing package tables, terminal `cancelled` rows, durable runtime rows, and active-run preservation
- Hardened durable lease ownership, recovery, duplicate step handling, startup rollback, and invalid persisted step timeout handling
- Hardened queued execution so invalid swarm definitions fail before dispatch and duplicate database-backed deliveries do not corrupt terminal state
- Hardened capture behavior so disabled capture settings apply consistently to persisted inspection surfaces and failure events
- Hardened structured input reconstruction so queued payloads remain plain data when workers rebuild `RunContext`
- Hardened parallel and hierarchical parallel execution so missing concurrency results throw instead of fabricating successful empty outputs
- Expanded test coverage across durable execution, hierarchical routing, privacy capture, persistence boundaries, pruning, queue fail-fast behavior, Pulse metrics, and artifact normalization

### Documentation

- Added durable execution, hierarchical routing, maintenance, persistence/history, structured input, testing, and Pulse documentation updates for the new runtime contracts
- Added explicit privacy and data-capture documentation covering raw prompt/output storage, `[redacted]`, automatic artifacts, failure messages, terminal context snapshots, and metadata caveats
- Added queue and durable worker guidance for Laravel queue timeouts, `retry_after`, and provider-call duration
- Added application run-inspector and operations-dashboard examples based on real Laravel usage patterns
- Updated README guidance around Laravel Swarm's positioning, queue semantics, durable execution, streaming, persistence, events, examples, and release contracts

## v0.1.3

- Hardened lightweight queued swarm execution with lease-based retry recovery so duplicate deliveries do not strand or replay active database-backed runs
- Removed pre-v1 queued `then()` / `catch()` callbacks and tightened queued lifecycle behavior around lease-safe failure handling and event integrity
- Added prune-based retention hardening across database-backed history, context, and artifact stores, including active-run protection and safe handling of custom configured table names
- Improved database-backed queued install safety with clearer lease-column validation errors for partially migrated history tables
- Expanded queueing and persistence coverage around retries, pruning, lease loss, custom table names, and schema validation failure modes
- Updated the README and maintenance/persistence docs to clarify the lightweight queue contract, event-listener guidance, and database retention behavior

## v0.1.2

- Added durable database-backed persistence for swarm context, artifacts, and run history
- Added auto-loaded package migrations, optional migration publishing, and configurable persistence driver resolution with per-store overrides
- Replaced the hierarchical placeholder with coordinator-driven `route()` execution and explicit routed-agent validation errors
- Hardened queued swarm behavior around container resolution, callback fluency, queue-safe workflow definitions, and pending-dispatch chaining
- Clarified and strengthened sequential streaming behavior, including failure handling, known usage preservation, and completion-state fidelity
- Improved lifecycle observability with populated `SwarmStarted` execution modes and normalized completion metadata across run paths
- Expanded feature and unit coverage for queueing, streaming, persistence, lifecycle events, hierarchical routing, and fake interception
- Rewrote and expanded the README around workflow positioning, configuration, queue semantics, testing, and lifecycle behavior

## v0.1.1

- Rewrote the package documentation around the Laravel-native public API with explicit `run()`, `queue()`, and `stream()` usage
- Added the initial `CHANGELOG.md` and tightened extension-point contract comments and stub comments
- Removed the hardcoded package version from `composer.json` so Git tags define releases cleanly
- Fixed sequential swarm streaming after the execution-policy cleanup by removing a stale execution-mode reference
- Preserved run context handling for queued swarm jobs after the public API simplification

## v0.1.0

- Added `make:swarm` scaffolding for swarm classes in `App\Ai\Swarms`
- Added sequential, parallel, and hierarchical swarm runners
- Added explicit public execution verbs with `run()`, `queue()`, and `stream()`
- Added queue support for background swarm execution
- Added swarm-level streaming for sequential topologies
- Added testing fakes and assertion helpers for swarm runs and queued dispatches
- Added structured swarm responses, lifecycle events, and persistence hooks for context, artifacts, and run history
