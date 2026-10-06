# Native-feature ecosystem proof (v0.28.0)

This is the combined, ready-for-wrap evidence for the Laravel Swarm **v0.28.0**
native-feature package: the new native Laravel AI feature access (P1–P11) verified
together with the completed adoption baseline and the four companion packages. It
records the frozen candidate set, the combined fresh-application install proof, the
per-companion compatibility evidence, the F/R assessment reconciliation, and the
limits carried forward. It does **not** publish packages, merge to `main`, tag, or
run paid providers — those remain later shipping gates.

It sits beside the two prior-release analogs it reuses — the
[Laravel AI 1.0 ecosystem procedure](ai-1-ecosystem-evidence.md), the
[companion map](ai-1-companion-evidence.md), and the
[adoption evidence index](ai-1-release-evidence.md) — and the v0.28.0 ownership
contract, [Native Ownership and Limits](native-ownership-and-limits.md).

## Frozen candidate set

The combined candidate is core `release/v0.28.0` at its reviewed head plus the four
companions at their **reviewed candidate-source** heads. The companion changes are
sanctioned siblings — each a separate claim, branch, and configured change review in
its own project — merged via pull requests into their own `release/vX.Y.Z` branches.
They are **not yet tagged or published**; publication (tag, `main` merge, Packagist)
is a later shipping gate, exactly as the ai-1 ecosystem proof deferred it. The pinned
candidate refs below are the reviewed source commits, now ancestors of each companion's
release branch. Core 0.28 is unreleased, so the core candidate is a reviewed commit
(as ai-1 used a reviewed commit, not a tag).

| Package | Candidate version | Immutable source (reviewed head) | Candidate evidence |
| --- | --- | --- | --- |
| builtbyberry/laravel-swarm | 0.28.0 | `269f749102f8d4c525c12e5486c3f57893d78d6b` | release/v0.28.0 head, the commit tagged `v0.28.0-rc1` |
| builtbyberry/laravel-swarm-pulse | 0.2.0 | `684ec87922590929c13afc61eb6609ed42e15521` | [PR #13](https://github.com/builtbyberry/laravel-swarm-pulse/pull/13), re-pinned by [PR #14](https://github.com/builtbyberry/laravel-swarm-pulse/pull/14) → release/v0.2.0 head |
| builtbyberry/laravel-swarm-filament | 0.4.0 | `a92b3750c182d52c46690732fef410003d47a7bb` | [PR #47](https://github.com/builtbyberry/laravel-swarm-filament/pull/47), re-pinned by [PR #48](https://github.com/builtbyberry/laravel-swarm-filament/pull/48) → release/v0.4.0 head |
| builtbyberry/laravel-swarm-mcp | 0.3.0 | `f4ddc42d005d8c6d4175088d509cb9d4c8d69ef6` | [PR #19](https://github.com/builtbyberry/laravel-swarm-mcp/pull/19), re-pinned by [PR #20](https://github.com/builtbyberry/laravel-swarm-mcp/pull/20) → release/v0.3.0 head |
| builtbyberry/laravel-swarm-memory-vector | 0.3.0 | `c789595d74615a02e61dd2a2b1e9ac9ee60674d5` | [PR #13](https://github.com/builtbyberry/laravel-swarm-memory-vector/pull/13), re-pinned by [PR #14](https://github.com/builtbyberry/laravel-swarm-memory-vector/pull/14) → release/v0.3.0 head |

Upstream native pins: official Laravel AI `1.1.0`
(`a47929ad17d84528e37f1f85927d14824604b09e`), Laravel 13.34.0
(`c829b4982d29344cbcf1d7ad78eb5d2bb8ae66c4`), and native MCP 1.0.1
(`92987a9d03847801299ff4abe909ba7686147dde`): the current releases on the day the
candidate was cut. `composer.json` requires `laravel/ai` `^1.0`; the package's own
test matrix also covers v1.0.0 and v1.0.1.

### Why the companion siblings were required

Every shipped companion capped its core constraint at `^0.27`, so core 0.28.0 could
not install alongside any of them. Resolving that is companion work — a new minor per
companion adding `^0.28` — owned by each companion's own project, never folded into a
core diff or faked with a constraint override (a scope decision recorded on the
release as finding `P12-ECO1`). Each companion sibling widens its core range to admit
`^0.28`, adds a pinned core-0.28 CI lane that installs and runs its suite against the
frozen core candidate, and adds a discriminating control that rejects a v0.27 core on
the v0.28 lane. All four siblings are additive dependency-compatibility only — no
runtime, schema, or accounting change.

## Combined installed proof

The [reproducible procedure](ai-1-ecosystem-evidence.md) was run against the frozen
candidate set above with `proof.py candidate`. The harness is now release-agnostic:
package versions and refs, and the native `laravel/ai` / `laravel/framework` /
`laravel/mcp` pins, come from `sources.json` (the ai-1 run reproduces unchanged with
its own map), so this run and the ai-1 run share one harness.

- **Emitted report SHA-256:** `43dbe283bd1179c3b4489a47b1f820335eb70965465c3f58f818b7d7e9b0ca4f`
  (run of 2026-10-06 against the release-candidate set above; the first run, against core
  `6c3da95` on Laravel AI 1.0.1, emitted `371d6d9d4c475cd6194e42ce6b8e364a900819c25481eab4af71aa0a825c09fa`)
  (a point-in-time snapshot: the eight pinned candidate identities are fixed, while
  the report also hashes the full resolved lock, whose transitive dependencies resolve
  to their latest compatible releases at run time).
- The run used a fresh application, lock, vendor directory, and Composer home/cache,
  with five explicitly recorded candidate package repositories and no inherited
  application, provider, or Composer configuration. It verified eight exact
  lock/installed source and archive identities (core 0.28.0 + four companions + the
  three native pins), that each installed companion manifest is byte-identical to its
  frozen candidate source, package discovery and migrations (core and Pulse), nine
  command registrations, the four companion installers' `--help`, the native-upgrade
  assistant's then-current `0.26-to-0.27` behavior (standalone/Artisan parity,
  `runtime_verified=false`, the core-0.28 app recognized as an unsupported source beyond
  that recipe's 0.27 target with no actions inferred, and a refused custom-repository
  apply that changed no metadata), and a workflow smoke that produced its expected answer.
- **Discriminating fault checks.** `fault_probes.py` ran four provenance corruptions
  (wrong source ref, wrong version, missing companion, lock/installed disagreement)
  and a deliberately-wrong workflow smoke against the completed proof; each failed its
  targeted guard and was restored to exact green bytes, including the fixture database.
- The candidate repositories are temporary CI-only metadata. This is candidate
  installation evidence, **not** proof that the ecosystem is published or installable
  from released packages; a fresh default-Packagist five-package install after all
  releases remains a separate shipping gate.

### Re-run against the release candidate

The proof above was first run at core `6c3da95`. It was re-run on 2026-10-06 against
the candidate set in the table: core at the commit tagged `v0.28.0-rc1`, which adds
[PR #550](https://github.com/builtbyberry/laravel-swarm/pull/550) through
[PR #564](https://github.com/builtbyberry/laravel-swarm/pull/564) (signed-closure
callback deserialization, encrypted queued payloads, lease-based callback delivery,
write-once database run history, changed `swarm:health` / `swarm:prune` output, and
the `0.27-to-0.28` upgrade recipe), and each companion at its release-branch head after
its core 0.28 lanes were re-pinned to that commit and passed. The combined fresh
install, the identity checks, discovery, migrations, command registrations, installer
help, upgrade-assistant checks and workflow smoke all passed, and `fault_probes.py`
again failed each targeted guard and restored the proof to identical bytes.

A change to core or a companion after these commits is outside this proof and needs
another run before the final tag.

## Per-companion compatibility (core 0.28 lanes green)

Each companion sibling's pinned core-0.28 CI lanes install the frozen core candidate
and run the companion's own suite against it. All are green at the reviewed heads:

| Companion | Core-0.28 lanes | Result |
| --- | --- | --- |
| Pulse | PHP 8.4/8.5 `native1-028` minimum/current | green (recorders, aggregates, cards, installer) |
| Filament | PHP 8.4/8.5 `native1-028` minimum/current | green (usage displays, Filament 5 / Livewire 4) |
| MCP | PHP 8.4/8.5 `native1-028` minimum/current | green (native HTTP discovery, resource reads) |
| memory-vector | PHP 8.4/8.5 scan + PHP 8.4 **real pg17/pgvector** `adoption-028` minimum/current | green (vector write/query/update/forget) |

Each companion's `require` block at its core-0.28 candidate resolves identically to
its already-green core-0.27 lane, because core 0.28's `require` block is byte-identical
to core 0.27's; the companion lanes prove the runtime, and this combined proof adds
the five-package co-resolution that the pairwise lanes cannot.

## F/R assessment reconciliation

The v0.28.0 native-feature adoption assessment tracked findings `F1`–`F9` and
retained/replacement decisions `R1`–`R9`. These are internal assessment identifiers
with no verbatim definitions in the repository; each is closed by the component below
(the authoritative closure record is [Native Ownership and Limits](native-ownership-and-limits.md#retained-and-replaced-decisions-carried-forward)).
Every row reconciles to exactly one disposition — **native delegation** (native
`laravel/ai` owns it), **completed adapter** (Swarm ships a finished adapter),
**demonstrated retained guarantee** (a Swarm guarantee preserved and tested), or
**explicitly accepted limitation** (a limit kept on purpose, with a reopen trigger).
No feature counts here merely because it is "available separately": each is exercised
in-workflow by the cited merged tests.

| Row | Closing component | Disposition | Evidence |
| --- | --- | --- | --- |
| F1 | P1 native-message-inputs | native delegation | Native `UserMessage`/`AgentInput` accepted and delegated, content preserved, attachments isolated — [native-inputs.md](native-inputs.md), `tests/Feature/NativeInputWireParityTest.php` |
| F2 | P2 reconstruct-native-agent-settings | demonstrated retained guarantee | Tool/message/conversation settings survive worker reconstruction — `RunContext::withAgentConfiguration()`, `tests/Feature/NativeAgentSettingsTest.php` |
| F3 | P3 native-step-result-access | demonstrated retained guarantee | Native response data preserved with distinct live/persist privacy — [native-step-results.md](native-step-results.md), `tests/Feature/NativeStepResultTest.php` |
| F4 | P5 native-approval-recovery-proof | explicitly accepted limitation | No public same-turn continuation; permanent fail-closed approval boundary (P6 cancelled) — [native-outcome-boundary.md](native-outcome-boundary.md), `tests/Feature/NativeOutcomeBoundaryTest.php` |
| F5 | P8 native-chat-protocol-adapters | completed adapter | Vercel and AG-UI protocol projection with explicit workflow mapping — [native-chat-protocols.md](native-chat-protocols.md) |
| F6 | P9 workflow-callback-conveniences | completed adapter | Terminal workflow `then()`/`catch()`, default-off, at-least-once — [error-handling.md](error-handling.md#terminal-workflow-callbacks) |
| F7 | P7 parallel-live-stream-multiplexing | demonstrated retained guarantee | Bounded parallel live streaming with explicit identity, no false global order — [streaming.md](streaming.md#parallel-live-multiplexing) |
| F8 | P11 native-ownership-and-limits-contract | native delegation | Native-owned vs Swarm-retained boundary documented — [native-ownership-and-limits.md](native-ownership-and-limits.md) |
| F9 | P10 native-capability-workflow-examples | native delegation | Native media/retrieval capabilities run in-workflow via native tools — [native-capabilities.md](native-capabilities.md), `tests/Feature/Adoption/NativeCapabilityWorkflowTest.php` |
| R1 | P4 native-agent-onboarding | native delegation | Native `make:agent`/Promptable as the normal authoring path — [native-agent-onboarding.md](native-agent-onboarding.md) |
| R2 | P11 | explicitly accepted limitation | Decision recorded with maintenance path in the ownership contract |
| R3 | P4 native-agent-onboarding | completed adapter | Explicit offline demonstration path (`ScriptedAgent`) — [native-agent-onboarding.md](native-agent-onboarding.md) |
| R4 | P11 | explicitly accepted limitation | Structured-output streaming unsupported (upstream), with reopen trigger — [native-ownership-and-limits.md](native-ownership-and-limits.md#limits-kept-on-purpose) |
| R5 | P3 / P11 | demonstrated retained guarantee | Distinct privacy for live access vs persistence, retained and tested |
| R6 | P11 | explicitly accepted limitation | No completion-receipt reconciliation; whole-workflow queued `then()`/`catch()` exist as default-off terminal callbacks, not a receipt-reconciliation guarantee |
| R7 | P1 / P5 / P11 | demonstrated retained guarantee | Explicit topology-stable attachment recipients and isolation |
| R8 | P3 / P7 / P8 / P11 | demonstrated retained guarantee | Result and stream mapping preserves capture, identity, and replay |
| R9 | P1 / P3 / P5 / P11 | demonstrated retained guarantee | Native content and identity preserved across every supported path |

## Combined journeys and retained guarantees (merged coverage)

The new native features are exercised together with the retained guarantees by the
already-merged Adoption suite — this component reuses that coverage rather than
duplicating it:

- **Native capabilities in a real workflow:** `tests/Feature/Adoption/NativeCapabilityWorkflowTest.php`
  carries native classification, image, speech, transcription, embedding, reranking,
  and vector-store capabilities across a genuine multi-step sequential workflow,
  keeping each nested capability's typed result and usage at the tool layer.
- **Capture/identity/isolation preserved:** `tests/Feature/Adoption/NativeWorkflowPreservationTest.php`
  keeps native conversation opt-in, roles isolation, and separate storage privacy.
- **Mixed-worker upgrade safety and old-state reads:** `tests/Feature/Adoption/NativeUpgradeCompatibilityTest.php`,
  `tests/Feature/Adoption/UpgradeCompatibilityTest.php`, and
  `tests/ProcessConcurrency/SwarmUpgradeConcurrencyTest.php` execute frozen v0.26.3
  durable/queue/branch jobs under new handlers and race the sidecar-lock recipe.
- **Permanent native-approval rejection boundary across execution modes:**
  `tests/Feature/NativeOutcomeBoundaryTest.php`, `NativeOutcomeHttpTest.php`, and
  `tests/ProcessConcurrency/NativeOutcomeConcurrencyTest.php` prove no false
  step/workflow success, automatic retry, approval-protocol frame, or approval-specific
  callback wait — the F4/P6 boundary.

## Limits and constraints carried forward

Each of these is documented in place with a reopen trigger; none is a silent drop.

- **CI compatibility advisory (release readiness `CI-COMPAT-ADVISORY`).**
  The `PHP 8.4/8.5 - Laravel 13.16 compatibility (Pest 4)` lane passes with a
  lane-scoped ignore of only advisory `PKSA-d5tc-s1qs-h781` (PR #555); any other
  advisory still fails that exact-floor lane. Reopen when the declared Laravel
  floor moves to a fixed release or the advisory status changes, then remove the
  ignore and rerun the floor proof.
- **Companion `^0.28` support (release readiness `P12-ECO1`).** The shipped companions
  capped core at `^0.27`; this component's four sanctioned siblings add `^0.28`. Until
  those siblings are merged, tagged, and published, a default-Packagist five-package
  install of core 0.28 + companions is not yet possible — that is the deferred
  publication gate. Reopen trigger: companion releases land and publish.
- **Permanent native-approval rejection boundary.** Swarm ships no fork, patch, or
  disguised continuation for behavior `laravel/ai` does not officially support; the
  fail-closed boundary is preserved (P5 proved it; P6 is cancelled). Reopen trigger:
  official upstream same-turn continuation support, independently verified.
- **Structured-output streaming unsupported** (upstream `laravel/ai` v1.0.1) and the
  other recorded limits — see [Native Ownership and Limits](native-ownership-and-limits.md#limits-kept-on-purpose).
- **Native conversation storage is configured separately** from Swarm capture/sealing —
  see the same contract.

## Deployment order and rollback

Consumer migration, deploy order, drain conditions, and the revert-unsafe boundary for
newly persisted native state (native results, sealed operational envelopes, and the
`swarm_callback_deliveries` callback-delivery table) are documented in
[UPGRADING.md](../UPGRADING.md), including the callback-specific
[migration, deploy, relay, and rollback order](../UPGRADING.md#terminal-workflow-callbacks):
migrate first, deploy readers with flags off, enable after every worker is on v0.28,
and drain before rollback when newly persisted state has been written.
This component adds no migration and no new persisted state of its own.

## Completed work and new-work handoff

- **Completed (merged into `release/v0.28.0`):** P1–P11, plus the hosted-CI
  stabilization components. F1–F9 / R1–R9 reconciled above.
- **This component (P12):** the combined candidate proof, the F/R reconciliation, the
  four sanctioned companion siblings (candidate sources merged to their release
  branches; publication deferred), and this evidence
  index.
- **Later shipping gates (out of this component):** configured release
  readiness/change-review closure at wrap, `main` merge, tagging, the post-main
  moving-development gate, companion publication, and the fresh default-Packagist
  five-package installation proof. Separately-owned v0.29 child-recovery and v0.30
  branch-chain work are respected and not integrated here.
