# Laravel AI 1.0 adoption evidence

This index records the v0.27.0 adoption candidates and the evidence prepared for
release-wrap. Component acceptance does not establish release readiness,
publication or a safe migration of an application's own data. The five release
PRs remain draft; changelogs remain unreleased.

## Core component sources

The baseline is v0.26.3, `38b3b649f3416a31c8d1f39ecefd77446e676a3c`.
Each component below merged into `release/v0.27.0` after its own gates,
independent configured review and fresh verification.

| Component | PR | Merge source |
| --- | --- | --- |
| C1: native contracts | [524](https://github.com/builtbyberry/laravel-swarm/pull/524) | `e0035c98a7911844ebcf47d15c957da1cf4d5b99` |
| C2: application upgrade contract | [525](https://github.com/builtbyberry/laravel-swarm/pull/525) | `202bc6e90cb1524a27513cd9edafca3af74c2e2e` |
| C3: retained workflows and state | [526](https://github.com/builtbyberry/laravel-swarm/pull/526) | `48ad4ef690363ca40ba7d3bd50e63e7fbe76ba4b` |
| C4: companion coordination | [527](https://github.com/builtbyberry/laravel-swarm/pull/527) | `7076cc6b6d31f4ef98080e98dcc4c9330680407b` |
| C5: integrated candidate and recipes | [528](https://github.com/builtbyberry/laravel-swarm/pull/528) | `f02ff52f58af9469b801c9780de01405031a2761` |

C6 is this documentation reconciliation. Its own reviewed head, checks, merge
identity and Marshall transition are recorded on its component PR after landing;
the document does not predeclare its own merge. The core release PR is
[523](https://github.com/builtbyberry/laravel-swarm/pull/523).

## Combined installed proof

The [reproducible procedure](ai-1-ecosystem-evidence.md) completed against core
`2d91d9fd4cd6ecb0aac54cc9af1db35f9fb56606` and the four immutable sources in
the [companion map](ai-1-companion-evidence.md): Pulse 0.1.8, Filament 0.3.0,
MCP 0.2.0 and memory-vector 0.2.0. The official upstream pins were AI 1.0.0,
Laravel 13.33.0 and MCP 1.0.0. Full identities, manifest hashes and results are
attached in the [C5 evidence](https://github.com/builtbyberry/laravel-swarm/pull/528#issuecomment-5807763645).
The emitted report SHA-256 is
`3c779bddc1efd9bc18ec9bae16e780bb976a6ad4c4dd7e1edb280cd14ae58fb9`.

That run used a fresh application, lock, vendor directory and Composer home/cache,
with five explicitly recorded candidate repositories. It verified eight exact
lock/installed source and archive identities, actual provider discovery,
migrations, nine command registrations, assistant parity/refusal, workflow
execution, history/replay, Pulse, Filament, authenticated MCP and vector memory.
Eight replay events and two Pulse runs were observed; four response and nine
embedding requests used deterministic native HTTP fakes with stray calls blocked.
Four provenance corruptions and an incorrect expected output failed their guards;
restoration returned green with exact metadata/database bytes.

The initial vector fixture failure and bounded correction remain visible in the
PR evidence. The successful run is a new install at the corrected source above.
C6 changes documentation only: it preserves C5 runtime, manifests, tests,
workflows and harness bytes. Its later SHA is not presented as the source of an
earlier application execution.

## Preservation and platform gates

The [54-row ledger](ai-1-preservation-evidence.md) maps retained and native
behavior to executable tests. The [persisted-state evidence](ai-1-upgrade-evidence.md)
and ledger retain the real v0.26.3 writer identity and immutable fixture hashes;
the older v0.25 fixture remains separately identified. C3's twelve behavioral and
three provenance faults failed and restored; C5's assistant added seven
consequential fault probes, preserving the old recipe/default and optional,
transitive, section, constraint-style, selected-action and backup boundaries.

Final C5 local gates passed: 2,887 tests / 26,855 assertions; strict process
concurrency 107 / 193; compliance 51 / 171; PHPStan and Pint clean. All fourteen exact-head hosted checks passed with 91.5% coverage on all four
coverage lanes. Both audits found no security advisories; the two Laravel 13.16
Pest 4 lanes retain two reported deprecations. The [gate outputs and fresh
verification](https://github.com/builtbyberry/laravel-swarm/pull/528#issuecomment-5807875047)
and [24-lens independent review](https://github.com/builtbyberry/laravel-swarm/pull/528#issuecomment-5807852855)
record no unresolved C5 finding.

The core gate set covers PHP 8.4/8.5 stable and lowest dependency resolution with
the existing coverage floor, exact Laravel 13.16/Pest 4 compatibility, official
moving AI/Laravel development sources, strict process concurrency, analysis,
style and compliance. Four real MySQL 8/PostgreSQL 16 stable/moving jobs execute
native conversion and concurrency cases. Both advisory Composer audit results
are inspected separately from their informational CI policy. Companion-specific
platforms, actual Filament rendering and PostgreSQL/pgvector evidence remain in
the companion map; the combined SQLite application does not replace them.

## Consumer and release boundaries

Applications use the [explicit upgrade recipe](upgrade-assistant.md#laravel-ai-10-recipe)
and [native conversation migration procedure](native-conversation-upgrade.md),
including pending-turn preflight, stopped writers, coordinated backups and
compatible readers/workers. Native storage has its own capture, encryption and
retention boundaries. The assistant always reports `runtime_verified=false`.
No real application database was converted in this program.

The next phase is release-wrap and its configured change/readiness review for
core and companions. Main merges, tags, GitHub Releases and public installation
remain outside this handoff. Later authorized shipping must verify exact main
checks plus the separate post-main moving-development dispatch before tagging;
publish and observe core first, then companions. Finally run the no-override,
fresh default-Packagist five-package proof. Report any partial publication state;
candidate installation does not establish public availability.

Child correctness remains v0.28.0 and branch chains v0.29.0 with their existing
tracker/component identities and graphs. Native approval continuation and unrelated
product work remain outside this adoption release.
