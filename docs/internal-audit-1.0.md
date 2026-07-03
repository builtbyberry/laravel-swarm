# `@internal` Promotion Survey (1.0 prep) — #52

This is a deliberate, conservative pre-1.0 audit of every `@internal` marker in
`src/`. It asks, for each class, whether the shape has hardened into a genuine
**user extension point** worth the permanent commitment of a public API — not
whether it merely *could* be exposed.

**Governing principle.** Public surface is a permanent commitment. Default =
**keep `@internal`**. A class is promoted only when it meets ALL THREE:

1. It is genuinely how a USER EXTENDS the package (custom sink, resolver, driver,
   dispatcher chain) — an extension point, not an implementation detail.
2. Its shape has been STABLE for at least two minors.
3. Third-party code would be reasonable to depend on it directly.

**Non-goals.** Do not promote anything tied to `laravel/ai`'s pre-1.0 surface
(`@internal` is insurance against that drift). Do not promote engine mechanics or
execution internals.

## Headline result

**Net promotions this survey: 0.** The two classes the issue named as the
most-likely promotions — the `AuditOutbox` contract and its `AuditDrainResult`
result — turned out to be **already public** (no `@internal` tag; already listed
in `docs/public-surface.md` since v0.5.0). The issue text describing them as
`@internal` is stale. Every other named and scanned candidate is either an engine
mechanic, a decomposition helper, a persistence driver implementation, or a
provisional schema-coupled surface that fails the two-minors bar — all **KEEP**.

This is the expected, correct outcome for a conservatively-run survey: the
extension surface a user actually needs (custom `SwarmAuditSink`,
`SinkFailureHandler`, `AuditOutbox`, `SwarmAuditSigner`, guardrails, memory
stores, telemetry sinks, durable routing/wait/child contracts) is *already*
public. What remains `@internal` is genuinely internal.

## The named prime candidates (issue #52)

| Class | Recommendation | Justification |
| --- | --- | --- |
| `Contracts\AuditOutbox` | **Already public — no action** | Not `@internal` (verified: 0 `@internal` tags on the interface) and already in `docs/public-surface.md` "Audit Extension Points" (v0.5.0+), fully documented in `docs/audit-evidence-contract.md#audit-outbox`. The issue's premise that it is `@internal` is stale. Nothing to promote. |
| `Responses\AuditDrainResult` | **Already public — no action** | Not `@internal`; already in `docs/public-surface.md` "Responses And Support Objects" (v0.5.0+) as the result of `AuditOutbox::drain()`. |
| `Audit\RunAuditEmitter` | **Keep `@internal` — document the workaround** | Engine mechanic, not an extension point. It is a run-level *emit facade* that composes the `run.started`/`run.completed`/`run.failed` payload dictionaries and delegates all sink dispatch, signing, and failure routing to `SwarmAuditDispatcher`. It has no contract interface, is bound as a concrete singleton, and is consumed only by `SwarmRunner`. A user who wants to change audit behavior binds a custom `SwarmAuditSink` / `SinkFailureHandler` / `AuditOutbox` / `SwarmAuditSigner` (all already public) — they never replace `RunAuditEmitter`. Fails criteria 1 and 3. |
| `Persistence\DatabaseAuditOutbox` | **Keep `@internal` — document the workaround** | Concrete database implementation of the public `AuditOutbox` contract. Users needing different retry/store behavior bind their own `AuditOutbox` implementation; the concrete class is schema-coupled to `swarm_audit_outbox` and is not an extension point. Fails criterion 1. |
| `Audit\NoOpAuditOutbox` | **Keep `@internal` — no action** | Cache-mode fallback implementation of `AuditOutbox`. Selected automatically by the container; not something a user constructs or subclasses. Fails criteria 1 and 3. |
| `Runners\DispatchValidator` | **Keep `@internal` — no action** | Pure engine mechanic — dispatch-time reflection/contract validation (agents present, topology streamable, queueability, durable/streaming infrastructure ready) for the runner orchestrator. No interface, bound as a concrete singleton, consumed only by `SwarmRunner`. A decomposition helper, not an extension point. Fails criteria 1 and 3. |
| `Runners\LeaseManager` | **Keep `@internal` — no action** | Durable-leasing internal for the runner orchestrator (queue-lease-seconds policy + coordination-run fail/complete inside a freshly acquired lease). This is a **subtle surface** (distributed leasing) whose contract is entangled with orchestrator flow control; exposing it would freeze leasing mechanics. No interface, concrete singleton, consumed only by `SwarmRunner`. Fails criteria 1, 2 (leasing mechanics still evolve), and 3. |
| `Audit\SwarmAuditDispatcher` | **Keep `@internal` — document the workaround** | The "dispatcher chain" the issue mentions is customized by *binding the public contracts it composes* (`SwarmAuditSink`, `SinkFailureHandler`, `AuditOutbox`, `SwarmAuditSigner`, `CapturePolicy`), not by replacing the concrete dispatcher. Exposing it would freeze its enrichment/retry internals. Fails criterion 1. |

## Streaming substrate contracts (v0.15.0+) — promoted in v0.17.0

Two contracts in `src/Contracts/` were `@internal` at the time of this survey
(v0.16.0) and have since been promoted:

| Class | Recommendation | Justification |
| --- | --- | --- |
| `Contracts\CausalLogStore` | **Promoted — public since v0.17.0 (#349)** | Stable for two minors since its v0.15.0 introduction, satisfying criterion 2. A design gate ahead of the promotion (#349) confirmed the schema-coupling and sealed-window/void-edge semantics don't block a *read/query-seam* extension point — the gate's finding was that compaction and `#[DurableStreaming]` remain concrete-class-coupled, not that the contract itself is unsafe to expose. Scoped and disclosed accordingly; see the [Streaming Substrate Driver Guide](streaming-substrate-driver-guide.md). |
| `Contracts\ColdArchiveDriver` | **Promoted — public since v0.17.0 (#349)** | Same footing: stable for two minors, schema-coupled to `DatabaseColdArchiveDriver` (`swarm_cold_archives`). The design gate confirmed the atomic base-pointer-swap obligation binds the internal compactor, not contract implementers (graduate()/reclaim() were never part of this interface), and that `readSnapshot()`'s decrypt-or-throw responsibility already sat correctly with callers. Promoted as a read-only extension point; see the [Streaming Substrate Driver Guide](streaming-substrate-driver-guide.md). |

Both promotions are scoped to the read/query seam only — compaction and
`#[DurableStreaming]` per-node streaming remain coupled to the concrete
database implementations and are explicitly out of scope for a custom driver
in v0.17.0. See the driver guide for the full disclosure.

## Everything else — grouped keep

The remaining ~137 `@internal` classes are implementation details with no
extension-point shape. Grouped:

- **Engine / orchestration internals** — the runner family (`SequentialRunner`,
  `ParallelRunner`, `HierarchicalRunner`, the `*StreamRunner`s), the entire
  `Runners/Durable/*` coordinator/advancer/handler set,
  `DurableSwarmManager`/`DurableRunInspector`, `SwarmAttributeResolver`,
  `SwarmStepRecorder`, `SwarmGuardrailRunner`, and the `Jobs/*` queue jobs.
  Execution mechanics; a user drives them through the public runtime methods
  (`prompt()`/`queue()`/`stream()`/`dispatchDurable()`) and the new
  `SwarmOperator` control contract. **KEEP.**
- **Persistence driver implementations** — `Database*`/`Cache*` concrete stores
  (`DatabaseContextStore`, `CacheRunHistoryStore`, `DatabaseDurableRunStore`,
  `TieredStreamEventStore`, `SwarmPersistenceCipher`, …). Each implements an
  already-public store contract; a user binds their own implementation of the
  *contract*, not the concrete driver. **KEEP.**
- **Memory subsystem internals** — `DefaultSwarmMemory`, `DatabaseMemoryStore`,
  `CacheMemoryStore`, `RedactingMemoryStore`, `MemoryReplayCoordinator`,
  `ReplaySwarmMemory`, `SnapshotToolCallNormalizer`, `SwarmMemoryKeys`, etc. The
  public memory surface (`SwarmMemory`, `SnapshotsMemory`,
  `MemoryPropagationPolicy`, `MemoryCapturePolicy`) is already exposed; these are
  the implementations behind it. **KEEP.**
- **Hierarchical routing internals** — `HierarchicalRoutePlanner`, the
  `Hierarchical*Node` set, `HierarchicalRoutePlan`, `LoopRuleValidator`. Engine
  mechanics behind `#[Topology]` / `HasRoutePlan`. **KEEP.**
- **Support value objects / helpers / traits** — `ActiveRunContext`,
  `RunContextMemoryReader`, `ArtifactPayload`, `MonotonicTime`, `PlainData`,
  `SafeReporting`, `SwarmCapture`, `SwarmExecutionState`, `PhpStanTypeAliases`,
  the `Concerns/*` and `Persistence/Concerns/*` traits, etc. Internal plumbing
  used only within the package. **KEEP.**
- **Pulse / telemetry internals** — `Pulse/Livewire/*`, `Pulse/Support/*`, and
  the `Telemetry/*` dispatcher/listener/record set (the public seam is
  `SwarmTelemetrySink`). **KEEP.**
- **Streaming internals** — `StreamEventMapper`, `StreamStepAccumulator`,
  `ContextGrowthGovernor`, `SwarmUnknownEvent`, `SwarmCausalSealBarrier` (public
  seams are the `#[ContextGrowthPolicy]`/`GrowthPolicy` and `CausalLogView`
  surfaces already promoted in v0.15.0). **KEEP.**
- **Command concerns / testing internals** — `Commands/Concerns/*`,
  `Testing\FakeDurableSwarmManager` (the public testing surface is the `fake()`
  assertions + `InteractsWithSwarmEvents` + `ScriptedAgent`). **KEEP.**

## Documented workarounds (for the KEEP-with-workaround rows)

For a user who thinks they want one of the internal audit-pipeline classes:

- **Want different run-level audit payloads or emit behavior** (would-be
  `RunAuditEmitter` user): bind a custom `SwarmAuditSink` — it receives every
  enriched envelope and can reshape/route it. The category vocabulary and payload
  keys are the stable contract, not the emitter class.
- **Want different sink-failure / retry / dead-letter behavior** (would-be
  `SwarmAuditDispatcher` / `DatabaseAuditOutbox` user): bind a custom
  `SinkFailureHandler` (decides `Swallow`/`RetryInline`/`Halt`/`Queue`/
  `DeadLetter`) and/or a custom `AuditOutbox` implementation (your own persisted
  retry surface). Both are already-public contracts the dispatcher composes.
- **Want to read the audit chain from your sink**: implement the public
  `ReadableSwarmAuditSink` extension so `swarm:trace` can query it.

All of these are already public extension points — no promotion required.
