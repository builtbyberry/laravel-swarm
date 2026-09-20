# Laravel AI 0.11.2 upgrade evidence (C5)

This report scopes C5 of the v0.26.0 adoption plan. It complements the
[38-row executed preservation report](ai-0112-preservation-evidence.md), whose
44-row inventory remains eight adopt, 30 retain and six deferred. C5 changes
tests, dependency verification and operational guidance, not production runtime,
schema, serialized job formats, store signatures, config defaults or retention.

## Upgrade and rollback boundaries

Follow the [v0.26.0 operator sequence](../UPGRADING.md#upgrading-to-v0260): stop
intake, drain calls/work, stop workers, deploy dependencies and code together,
refresh autoload/opcache, restart, smoke supported modes/operators, then resume.
Keep prune/recovery schedules and the existing `APP_KEY` boundary. No migration
or backfill is introduced. Application-specific serialized classes/custom stores
still require an upgrade rehearsal.

The [frozen v0.25 fixture](../tests/Feature/Adoption/Fixtures/Upgrade/README.md)
was produced by executing the old code and dependencies, not the candidate.
[UpgradeCompatibilityTest](../tests/Feature/Adoption/UpgradeCompatibilityTest.php)
executes its five job classes, pending/waiting/expired-running durable states,
sealed context and coordinated branch/join. Provider HTTP is prohibited; native
agent fakes execute through real Swarm jobs and runners. These representative
fixtures do not claim compatibility with arbitrary application queue payloads.

`cross-reader.php export` under the candidate followed by `read` under the exact
old runtime passes for three active states, contexts, history, waits and three
replay outcomes. **Its result is DOWNGRADE BLOCKED:** the old reader drops denied
and failed information even though records parse. Retain a correction-preserving
reader or return to reviewed design. Do not delete/rewrite evidence; preserve
C2's rejection/nonretryability. This also applies to cold evidence.

Source comparison against v0.25.0 at
`be7df78e8fde12362cfff9007cfe723d572a5e4f` found no changes in `src/Contracts`,
`src/Memory/Contracts`, migrations or `config/swarm.php`. Native
`ConversationStore` interface source is also unchanged between the tested old
AI v0.10.3 and official v0.11.2. This is a source comparison, not proof that every
custom implementation works. The
[custom-store test](../tests/Feature/Adoption/CustomConversationStoreTest.php)
executes a delegating application store via Swarm with controlled native HTTP:
text roles, stored tool IDs/result IDs/arguments/results, subsequent provider
wire history, one tool effect, and separate native-storage privacy with Swarm
all four capture flags off. It asserts two actual database history records and
redacted context/step/final input and output with empty artifacts (review C5-F1).
It does not exercise native approval continuation. C4's concrete
`DatabaseDurableRunStore` requirement remains a documented constraint.

## Dependency provenance and minimums

Production remains PHP `^8.4`, Illuminate `^13.0` (json-schema `^13.16`) and
official `laravel/ai ^0.11.2`, stable/prefer-stable, with no fork/patch requirement.
The application host's root Composer settings control its actual resolution.

| Executed environment | Laravel AI source | Framework source | Meaning |
| --- | --- | --- | --- |
| Frozen old runtime, PHP 8.5.8 | v0.10.3 `c3848aae389f45c605eefb0dda5bd5fa7df76eaa` | v13.32.0 `cdd8b33c246719acdd118c705ce8c7ab5ef48a96` | Historical fixture generator and reverse reader only |
| Local stable, PHP 8.5.8 | v0.11.2 `ee2c5162838d440c4e2e629ea93c8c87e838eaed` | v13.30.1 `718d17db56861e0a49f644217c8853dab1bff8ce` | Actual stable tests; not framework minimum proof |
| Local moving-dev, PHP 8.5.8 | 0.x-dev `9969ca9693ee3686dcfbc59d0743e5f5a3dcfbca` | 13.x-dev `1d9727160ad440d1ccf2fdcbb7e4f4f36efae212` | Exact official branches; remote heads rechecked 2026-09-20 |
| Production-only minimum solve, platform PHP 8.4.0 | v0.11.2 `ee2c5162838d440c4e2e629ea93c8c87e838eaed` | v13.16.0 `66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67` | Solver evidence only; no Testbench/dev dependencies or runtime claim |

The minimum solver copies production `require`/`conflict`, stable settings and
platform PHP 8.4.0 into an isolated root and adds framework `^13.0` as the
application host (it supplies the concurrency component). Run
`composer update --prefer-lowest --prefer-stable --prefer-dist --no-interaction`.
The combined json-schema floor yields framework 13.16, not 13.0. Testbench can
raise the framework version in test lanes, so their lowest resolution is reported
separately. CI executes actual PHP 8.4 and 8.5 rather than treating a platform
constraint as execution proof.

[verify-adoption-dependencies.php](../.github/workflows/verify-adoption-dependencies.php)
checks production constraints, stable settings, official Git origins, full source
references, matching installed packages, minimum exact AI tag/reference, stable
current versions, and exact compatible moving branches. It fails closed on stable
locks presented as moving-dev and wrong branches/origins/installed revisions.
Development constraints exist only inside CI or temporary harnesses. The original
production manifest is restored before contract tests. A matching Git origin/ref
is provenance metadata, not a cryptographic audit of every installed file.

## Required lanes and negative controls

- `tests.yml`: PHP 8.4/8.5, lowest (exact AI v0.11.2) and current stable;
  provenance, coverage floor, strict process and analysis. Stable-current also
  runs named compliance and lint gates.
- `nightly.yml`: PR, schedule and manual triggers, exact official AI `0.x-dev`
  and framework `13.x-dev`; provenance precedes full tests, strict process,
  analysis, compliance and lint. No soft-failure setting.
- `tests-real-db.yml`: MySQL 8 and Postgres 16, each on stable and moving-dev;
  provenance precedes the actual shared-database process lane, with skipped tests
  treated as failures.

The YAML regression requires exact gate commands, rather than substring matches
that could mistake `composer test:process-concurrency:ci` for `composer test`.
Negative controls temporarily remove dependency rules and required commands,
introduce stable/wrong-dev/soft-failed/skipped lanes, restore the original plain
unknown event, alter its secret wire payload, remove persisted result flags,
drop custom-store history, and bypass each historical job handler. Every mutation
must fail its focused test and be restored before full verification. The unknown
event fixture now extends native `StreamEvent` and retains the id/secret sentinel;
no production mapper or redaction policy is changed.

Local verification on PHP 8.5.8 completed on both exact dependency sets above:

| Command | Stable | Moving-dev |
| --- | --- | --- |
| `composer test` before the C5-F1 test repair | 2,235 tests / 10,523 assertions | 2,235 tests / 10,523 assertions |
| `composer test:process-concurrency:ci` | 97 tests / 122 assertions, no skips | 97 tests / 122 assertions, no skips |
| `composer test:compliance` | 51 tests / 171 assertions | 51 tests / 171 assertions |
| `composer analyse`; `composer lint` | Passed | Passed |
| C5 focused tests plus documentation references after C5-F1 | 37 tests / 187 assertions | 37 tests / 187 assertions |

All 36 negative controls failed and were restored: 33 initial controls and three
C5-F1 controls exposing input capture, output capture and the unused-store
configuration. Thirty-three controls failed assertions; the original moving-dev fixture
and two bypassed historical handlers also raised test exceptions. The CLI itself
returned exit 1 when the stable lock was presented as moving-dev. No production
or vendor mutation remains. Actual matrix coverage and MySQL/Postgres CI results
are required on the final PR head before merge; their run links are retained in
the PR evidence. The historical fixture tests run on SQLite; the real-DB jobs
prove the existing process-concurrency group, not those historical row fixtures.

## Optional companion contract smoke

These isolated suites passed against candidate core using temporary root/path
constraints. Published manifests were preserved separately and companion source
repositories were not edited. This is contract smoke, **not released installation
compatibility** or companion release qualification.

| Companion pinned source | Result |
| --- | --- |
| Pulse `0db5501f3c57991810f1341b4cb97e00546880ad` | 23 tests, 123 assertions |
| Filament `ecf82f8db450b85d11f77df06ada1652d069c795` | 180 tests, 641 assertions |
| MCP `324b6b2781a9d27ce7f1429fd49cbc3a081ed1e8` | 26 tests, 61 assertions |
| memory-vector `3ff066a5bd1ccecc09af73792bad109121fb98c4` | 29 tests, 43 assertions |

Harness: materialize each pinned tree, preserve `composer.json`, admit core
`0.26.0` through a path repository pointing at the candidate, and for vector
admit official AI `^0.11.2`; run `composer update` then `composer test`.
All observed published companion constraints stop at core `^0.25`; vector also
excludes AI 0.11. Marshall release finding **C5-R1**, owner Daniel, retains the
separate companion-release scoping requirement. Passing these harnesses does not
close that release-level gap.

## Separate release gate — still outstanding

After a separately authorized merge to `main`, dispatch the exact compatible
`nightly.yml` workflow on `main`, verify actual official moving-dev lock/source
resolution and every required suite, and retain successful run evidence before
any later tag or release completion. C5's local and PR proof cannot replace this
post-main gate. C5 does not authorize C6, a main merge, workflow dispatch, tagging
or shipment; independent release readiness also remains separate.
