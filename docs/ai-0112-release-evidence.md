# Laravel AI 0.11.2 adoption contracts and release evidence (C6)

This is the complete disposition index for approved Audit 225 v8 / Decision 2357:
**44 rows: eight adopt-now, 30 retain-in-Swarm, six defer-new**. It preserves the
approved responsibilities; it does not revise the audit or add integrations.
The baseline is Swarm v0.25.0 `be7df78e8fde12362cfff9007cfe723d572a5e4f`.
C6 starts from C5 merge `334ab6691e19328fd55c595dafe68a9a4d278a1f` and changes
only documentation. This component evidence does not establish publication or
completion of the separate release gates below.

## What was executed

The [C4 preservation report](ai-0112-preservation-evidence.md) maps every A/R row
to executed tests and their actual T/P/D/C lanes. Its linked test files are
locators, not independent proof of execution. The
[C5 upgrade report](ai-0112-upgrade-evidence.md) adds old-job/active-row fixtures,
reverse readers, custom native storage, dependency provenance and bounded
companion smoke. Controlled native HTTP tests execute official native code
against fake transport; they do not call a live provider. Intent-only Swarm fakes
do not prove execution, and solver-only minimum checks are not runtime tests.
No modified prototype result is counted here.

C5's independently reviewed head `7d945d6044ac2bb42e3234154ce7ea17ebae94d1`
merged through [PR #501](https://github.com/builtbyberry/laravel-swarm/pull/501).
The following final-head logs were re-read for C6:

| Execution record | Actual result and dependency evidence |
| --- | --- |
| [Stable matrix 35514025657](https://github.com/builtbyberry/laravel-swarm/actions/runs/35514025657) | PHP 8.4/8.5 lowest/current: each 2,235 tests / 10,542 assertions, 91.2% coverage, strict process 97/122 and analysis. Current lanes also compliance 51/171 and lint. AI v0.11.2 `ee2c5162838d440c4e2e629ea93c8c87e838eaed`; framework lowest v13.16.0 `66d5cdac5afd508dc6519ca59f5cc9b2c93a2b67`, current v13.32.0 `cdd8b33c246719acdd118c705ce8c7ab5ef48a96`. |
| [Moving-dev PR run 35514025592](https://github.com/builtbyberry/laravel-swarm/actions/runs/35514025592) | PHP 8.5: 2,235/10,542; strict process 97/122, compliance 51/171, analysis/lint. AI 0.x-dev `9969ca9693ee3686dcfbc59d0743e5f5a3dcfbca`; framework 13.x-dev `2799b7b1f44a90d0c0ecc2954991739408cdd380`. Verified lock/installed sources precede suites. This is pre-merge PR evidence, not the post-main gate. |
| [Real DB run 35514025543](https://github.com/builtbyberry/laravel-swarm/actions/runs/35514025543) | MySQL 8/Postgres 16, each stable/moving-dev: 10 tests / 162 assertions without skips. These are the real-DB process group, not historical fixtures (which run on SQLite). |

C4's 28 restored negative controls and C5's 36 restored controls are recorded
in their reports. C6 adds no behavior or guard, so no new mutation is claimed.
The C6 PR must retain its own final-head verification and independent review;
these historical runs do not substitute for that review.

## Complete disposition index

A means the selected native boundary is exercised within its approved scope,
not wholesale replacement. R means Swarm still owns the responsibility, with
its existing limitations. Each link names the matching row in the C4 table;
read that row's discriminator and lane together with the executed runs above.

| ID | Approved responsibility | Disposition and evidence |
| --- | --- | --- |
| A01 | native prompt/stream execution | Adopt selected boundary; [C4 A01](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A02 | provider/model/options/failover | Adopt selected boundary; [C4 A02](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A03 | native prompt middleware | Adopt selected boundary; [C4 A03](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A04 | native tools, AgentTool and deferred discovery | Adopt selected boundary; [C4 A04](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A05 | native structured output | Adopt selected boundary; [C4 A05](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A06 | remove obsolete vendor fake coupling | Adopt selected boundary; [C4 A06](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A07 | consume native invocation/tool/stream identity | Adopt selected boundary; [C4 A07](ai-0112-preservation-evidence.md#selected-native-integrations). |
| A08 | native agent conversation history, opt-in | Adopt selected boundary; [C4 A08](ai-0112-preservation-evidence.md#selected-native-integrations). |
| R01 | public front doors, context and responses | Retain in Swarm; [C4 R01](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R02 | sequential multi-agent flow | Retain in Swarm; [C4 R02](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R03 | top-level parallel workflow | Retain in Swarm; [C4 R03](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R04 | generated hierarchy and validated route plan | Retain in Swarm; [C4 R04](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R05 | static hierarchy, bounded loops and rollups | Retain in Swarm; [C4 R05](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R06 | queued workflow and multi-worker hierarchical joins | Retain in Swarm; [C4 R06](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R07 | dispatch validation, budgets and timeouts | Retain in Swarm; [C4 R07](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R08 | durable workflow cursor and checkpoint transaction | Retain in Swarm; [C4 R08](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R09 | retries, backoff and recovery policy | Retain in Swarm; [C4 R09](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R10 | leases/fencing, branches and durable outbox | Retain in Swarm; [C4 R10](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R11 | named waits, signals and timeouts | Retain in Swarm; [C4 R11](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R12 | authenticated durable webhook ingress | Retain in Swarm; [C4 R12](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R13 | child swarms and parent reconciliation | Retain in Swarm; [C4 R13](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R14 | operator controls and display read seams | Retain in Swarm; [C4 R14](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R15 | typed streaming, lazy response, broadcasts and replay | Retain in Swarm; [C4 R15](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R16 | causal log, node grammar, attempt void/seal and views | Retain in Swarm; [C4 R16](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R17 | compaction, hot/cold retention and context growth | Retain in Swarm; [C4 R17](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R18 | scoped memory, propagation and write capture | Retain in Swarm; [C4 R18](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R19 | snapshots, FrozenView and completed stream checkpoints | Retain in Swarm; [C4 R19](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R20 | Swarm memory tools and filesystem opt-in wrapper | Retain in Swarm; [C4 R20](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R21 | workflow guardrails and payload limits | Retain in Swarm; [C4 R21](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R22 | lifecycle events, telemetry, duration and job failures | Retain in Swarm; [C4 R22](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R23 | audit actors, capture, signing, chains and outbox | Retain in Swarm; [C4 R23](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R24 | persistence privacy and operational/evidence distinction | Retain in Swarm; [C4 R24](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R25 | run context/artifacts/history and custom-store seams | Retain in Swarm; [C4 R25](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R26 | retention, foreign keys and maintenance safety | Retain in Swarm; [C4 R26](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R27 | operational CLI, installers and generated code | Retain in Swarm; [C4 R27](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R28 | configuration, attributes, extension registration and companions | Retain in Swarm; [C4 R28](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R29 | workflow fakes, assertions and compatibility helpers | Retain in Swarm; [C4 R29](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |
| R30 | per-run context isolation and cleanup | Retain in Swarm; [C4 R30](ai-0112-preservation-evidence.md#retained-swarm-responsibilities). |

R28 companion contract smoke is supplemented by C5; released companion
installability remains open under C5-R1. A08 proves ordinary opt-in native
history and separate Swarm propagation, not automatic composition of both
`messages()` owners. R19/R30 evidence is bounded checkpoint/reset/interleaving
proof, not universal exactly-once effects or ambient native-state isolation.

## Named exclusions

These are unsupported new promises, not removed Swarm functionality. The
retained rows and existing rejection tests continue to apply.

| ID | Approved deferred integration | Present boundary |
| --- | --- | --- |
| D01 | native pending-tool approval as an unfinished Swarm node | Native pending outcomes fail before affected-step success; no native approval pause/decision/resume bridge. Existing Swarm waits/signals remain. |
| D02 | resume generation after saved approved result, plus native completion receipt recovery | No post-result native continuation or completion-receipt reconciliation. Checkpoint/retry can repeat effects; no exactly-once promise. |
| D03 | whole-workflow queued then/catch callbacks | No whole-workflow queued then/catch. Use lifecycle events; stream each/then remain supported. |
| D04 | structured-output worker streaming | No structured-output worker streaming. Preserve structured-stream rejection and synchronous generated coordinator. |
| D05 | newer conversation inspection/ownership/receipt interfaces | No newly adopted conversation inspection, ownership, pagination or receipt interfaces beyond the official minimum; no forced custom-store methods. |
| D06 | development-only and prototype-only execution contracts | No development/prototype PendingStep, AgentInput or Continuation contract in the supported minimum; moving-dev CI is an early-warning lane. |

## Public contracts and native ownership

[Runnable](../src/Concerns/Runnable.php) keeps `prompt()` and its `run()` alias,
`queue()`, `stream()`, all three broadcast helpers and `dispatchDurable()`.
[PendingRun](../src/Support/PendingRun.php) exposes inline in-process builders;
background/durable dispatch needs a declared, container-resolvable swarm class.
The [public-surface index](public-surface.md) remains the response/operator,
attribute and configuration reference. No public response type, config default,
store signature, serialized job format, schema or supported deprecated helper
is removed by this adoption. Only the selected dependency break applies to all
installations; C2's explicit native-outcome rejection and C3's evidence semantics
have their own documented impact.

- Streaming and broadcasts support sequential, generated hierarchical and static
  hierarchical paths. Generated coordinators prompt synchronously. Top-level
  parallel live streaming remains rejected. Owners:
  [SwarmRunner](../src/Runners/SwarmRunner.php),
  [DispatchValidator](../src/Runners/DispatchValidator.php).
- Generated hierarchy queueing defaults to in-process branch declaration order;
  `multi_worker` opts into database-backed branches and joins. Static hierarchy
  keeps its in-process queue path. The
  [resume job](../src/Jobs/ResumeQueuedHierarchicalSwarm.php) uses
  [ConfiguresDurableAdvanceJob](../src/Jobs/Concerns/ConfiguresDurableAdvanceJob.php),
  not [ordinary queue settings](../src/Jobs/Concerns/ConfiguresQueuedSwarmJob.php).
- [QueuedSwarmResponse](../src/Responses/QueuedSwarmResponse.php) proxies declared
  pending-dispatch methods; it does not provide whole-workflow `then()` / `catch()`.
  [StreamableSwarmResponse](../src/Responses/StreamableSwarmResponse.php) retains
  `each()` / `then()`; [lifecycle events](events.md) remain the background completion
  mechanism. Native agent queue callbacks do not imply Swarm workflow callbacks.

### Opt-in native tools and history

Configure tool discovery on the native agent's `tools()` method, for example
`return [new \Laravel\Ai\Providers\Tools\ToolSearch([new SearchCatalog])];`
where the application's `SearchCatalog` implements the native tool contract.
There is no Swarm discovery resolver or automatic tool registration. The
[pinned official ToolSearch source](https://github.com/laravel/ai/blob/ee2c5162838d440c4e2e629ea93c8c87e838eaed/src/Providers/Tools/ToolSearch.php)
and [executed native workflow cases](../tests/Feature/Adoption/NativeWorkflowPreservationTest.php)
cover supported wire shape and fail-closed unsupported-provider, duplicate-wrapper
and incompatible stateless-store requests. AgentTool nesting remains an agent
operation: its error text is not equivalent to a failed Swarm worker or join.

Native conversation history is opt-in through Laravel AI's own conversation
traits and `ConversationStore`. `RunContext::withConversationId()` binds Swarm
memory scope; it is not permission to access a native conversation and does not
auto-enable native persistence. Native `RemembersConversations` and Swarm
[RemembersRunContext](../src/Concerns/RemembersRunContext.php) both own `messages()`;
no automatic combined adapter is supplied. Application composition must preserve
roles, exclusions, authorization and store ownership without doubling history.

Swarm capture flags and designated-column encryption govern Swarm stores only.
Native conversation rows can retain plaintext prompts/tool data even when all
Swarm capture flags are off and Swarm sealing is enabled. Set native storage,
access, retention and encryption policy separately. The
[C5 custom-store case](../tests/Feature/Adoption/CustomConversationStoreTest.php)
observes actual Swarm histories and native history independently; it does not
prove native approval continuation or arbitrary custom stores.

### Retry and operator limits

Provider failover is inside a native invocation; workflow/job retries are Swarm
operations with different state and costs. Native completion can precede a failed
Swarm checkpoint; rerunning unfinished work can repeat tools and provider calls.
Fences protect persisted state, not remote effects. Do not convert whole-run retry
policy into a provider list or infer effect safety from FrozenView.
[Native pending approval](native-outcome-boundary.md) fails without automatic
retry, but other effects may already have occurred; inspect before operator restart.

The [executed preservation limits](ai-0112-preservation-evidence.md#supported-combinations-and-operational-limits)
remain: active-context capture is required for queued/durable execution; signal
record-before-release and run/key idempotency leave a crash window; child recovery
needs captured input and claimed intents can strand; non-final stream checkpoints
are best-effort, final/unfinished work may reexecute; cancellation is cooperative;
durable stores have concrete database requirements. No child recovery repair,
universal custom-store parity, or new effect-safety guarantee ships here.

## Exact deletion manifest

The only selected removal is A06's obsolete vendor fake coupling, completed in
[C1 PR #497](https://github.com/builtbyberry/laravel-swarm/pull/497), merge
`fcc1eb36ce2f5bff7fc9b79606964a5283f5e238`. Comparing it to the baseline:

| Owning source | Exact coupling removed / retained replacement |
| --- | --- |
| [SwarmFake](../src/Testing/SwarmFake.php) | Remove `use Laravel\Ai\FakePendingDispatch`. Existing queue/durable constructions resolve the new same-namespace Swarm-owned fake. |
| [DurableSwarmResponse](../src/Responses/DurableSwarmResponse.php) | Replace that vendor import with `BuiltByBerry\LaravelSwarm\Testing\FakePendingDispatch`; the existing routing-suppression type check follows the internal fake. |
| [FakePendingDispatch](../src/Testing/FakePendingDispatch.php) | Added inert implementation extending Laravel PendingDispatch; not a vendor shim and never wrapping a real job. Public response constructor/proxy types stay unchanged. |

No Swarm runtime file or supported workflow responsibility was deleted. The
[dedicated fake tests](../tests/Unit/Testing/FakePendingDispatchTest.php),
[Swarm fake suite](../tests/Unit/SwarmFakeTest.php) and
[audit interception tests](../tests/Unit/Testing/SwarmFakeAuditInterceptsTest.php)
ran in the full stable/dev suites above: fluent identity, unsupported methods,
destruction/GC and zero execution effects are tested separately from real jobs.
The [C4 A06 row](ai-0112-preservation-evidence.md#selected-native-integrations)
links real job/coordination evidence. Other runtime replacement/deletion requires
row-specific executable parity and separate authority; native API availability
or prototype success is insufficient.

## Upgrade, choices and outstanding release gates

Follow [UPGRADING](../UPGRADING.md#upgrading-to-v0260): retain official stable
`^0.11.2` (drop 0.10), stop intake/drain work, inventory old jobs/active rows, deploy
the dependency/code pair, restart workers and rehearse application-specific modes,
operators and custom stores. Keep APP_KEY and retention/recovery ownership intact.
Old readers can parse corrected denied/failed result flags while losing meaning:
**retain a correction-preserving reader or block downgrade**, including cold
storage. Worker drain and parseability alone do not establish semantic rollback.

Chosen: retain proven Swarm workflow ownership and expose the actual supported
boundaries. Rejected: blanket native replacement, invented queued callbacks,
automatic history composition and deleting evidence to enable downgrade. Evidence:
C1-C5 executable reports, exact-source semantic review and C6's independently
reviewed documentation diff. DocumentationReferenceTest validates resolving
references only; it does not prove these sentences true.

Release follow-through beyond the C6 evidence snapshot:

- **C5-R1, medium, owner Daniel:** the C5 snapshot found companion manifests
  excluding adoption core/AI versions. Closure requires fresh Packagist-only
  installation against published core v0.26 and all four companions. Temporary
  candidate aliases, path repositories, synthetic metadata and core v0.25 installs
  do not establish that released ecosystem compatibility.
- **C6-R1 and C6-R2 are fixed:** [PR #503](https://github.com/builtbyberry/laravel-swarm/pull/503)
  corrected queue-setting ownership and conditional retry/replay comments after
  C6. Independent verification confirmed unchanged executable PHP tokens and
  resolving source references; Marshall records both findings as fixed.
- Independent release readiness and its required findings remain separate from
  component change review; this report does not clear or approve that gate.
- After a separately authorized main merge, dispatch the exact compatible
  `nightly.yml` on main, verify actual official AI `0.x-dev` / framework `13.x-dev`
  lock and installed sources, and retain successful required-suite evidence
  **before any tag, shipment or release completion**. PR/local green results do
  not satisfy this post-main gate. C6 authorizes no such merge, dispatch or tag.
