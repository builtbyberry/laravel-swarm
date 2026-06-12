# Public Surface Coverage

This matrix maps Laravel Swarm's public user-facing surface to the guide or
example that documents it. Use it when reviewing documentation changes or when
adding a new public API.

## Runtime Methods

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `prompt()` | Run a swarm synchronously and return `SwarmResponse`. | [README: Running A Swarm](../README.md#running-a-swarm), [Sequential Content Pipeline](../examples/sequential-content-pipeline/README.md) |
| `run()` | Compatibility alias for `prompt()`. | [README: Running A Swarm](../README.md#running-a-swarm) |
| `queue()` | Dispatch a lightweight background swarm job. | [README: Queueing A Swarm](../README.md#queueing-a-swarm), [Queued Workflow Events](../examples/queued-workflow-events/README.md) |
| `stream()` | Run a sequential or static-hierarchical swarm and yield typed stream events. | [Streaming](streaming.md), [Static Hierarchical Topology](static-hierarchical-topology.md#streaming), [Streaming Progress](../examples/streaming-progress/README.md) |
| `broadcast()` | Stream and broadcast typed events immediately. | [Streaming](streaming.md#broadcasting-stream-events) |
| `broadcastNow()` | Stream and broadcast typed events with immediate delivery. | [Streaming](streaming.md#broadcasting-stream-events) |
| `broadcastOnQueue()` | Queue a worker to stream and broadcast typed events. | [Streaming](streaming.md#broadcasting-stream-events) |
| `dispatchDurable()` | Dispatch a checkpointed durable run. | [Durable Execution](durable-execution.md), [Durable Compliance Review](../examples/durable-compliance-review/README.md) |

## Responses And Support Objects

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `SwarmResponse` | Synchronous output, steps, usage, artifacts, metadata, and in-process context. | [README: Running A Swarm](../README.md#running-a-swarm) |
| `QueuedSwarmResponse` | Queued dispatch handle with `runId` and pending-dispatch proxy methods. | [README: Queueing A Swarm](../README.md#queueing-a-swarm) |
| `StreamableSwarmResponse` | Lazy iterable and HTTP response for stream events. | [Streaming](streaming.md) |
| `DurableSwarmResponse` | Durable dispatch handle with operator helper methods. | [Durable Execution](durable-execution.md), [Durable Waits And Signals](durable-waits-and-signals.md) |
| `RunContext` | Explicit run input, run ID, data, metadata, artifacts, labels, and details. Implements `ArrayAccess` — `$context['key']` reads and writes directly through to the `SwarmMemory` Run scope (v0.9.0+). `withConversationId()` binds the run to a conversation (read back with `conversationId()`), making `MemoryScope::Conversation` addressable for snapshot gathering and the `Recall`/`Remember` tools; the id threads through every execution path via run metadata (v0.12.0+). | [Structured Input](structured-input.md), [Persistence And History](persistence-and-history.md), [Swarm Memory](memory.md#runcontext-write-through), [Conversation-scoped memory](memory.md#conversation-scoped-memory) |
| `SwarmHistory` | Query persisted history and replay stored stream events. | [Persistence And History](persistence-and-history.md), [Run Inspector](../examples/run-inspector/README.md) |
| `AuditDrainResult` | Result of an `AuditOutbox::drain()` invocation — `replayed`, `dead_lettered`, `failed`, `claimed`, `reclaimed`. (v0.5.0+) | [Audit Evidence Contract](audit-evidence-contract.md#audit-outbox) |
| `ReplayMode` | Enum used by `#[MemoryReplay]` and `swarm.memory.replay_mode`. Cases: `FrozenView` (deterministic replay from frozen snapshot — the default) and `FreshExecution` (live memory, no snapshot guard). (v0.9.0+) | [Swarm Memory](memory.md#replay-semantics) |

## Attributes

| Attribute | Purpose | Primary documentation |
| --- | --- | --- |
| `#[Topology]` | Set sequential, parallel, hierarchical, or static_hierarchical topology. | [README: Topologies](../README.md#topologies), [Hierarchical Routing](hierarchical-routing.md), [Static Hierarchical Topology](static-hierarchical-topology.md) |
| `#[StreamParallelBranches]` | Control how parallel groups stream in a static-hierarchical swarm (`'concurrent'` or `'sequential'`). | [Static Hierarchical Topology](static-hierarchical-topology.md#streaming) |
| `#[Timeout]` | Set the best-effort orchestration deadline. | [Durable Execution](durable-execution.md#timeouts-and-database-requirements), [Maintenance](maintenance.md) |
| `#[MaxAgentSteps]` | Limit reachable coordinator and worker executions. | [Hierarchical Routing](hierarchical-routing.md#step-limits), [Static Hierarchical Topology](static-hierarchical-topology.md#step-limits-and-maxagentsteps) |
| `#[QueuedHierarchicalParallelCoordination]` | Opt a hierarchical queued swarm into multi-worker parallel coordination. | [Hierarchical Routing](hierarchical-routing.md#queue) |
| `#[DurableParallelFailurePolicy]` | Configure durable parallel branch failure behavior. | [Durable Execution](durable-execution.md#durable-hierarchical-parallel-flow), [Parallel Research Swarm](../examples/parallel-research-swarm/README.md) |
| `#[DurableRetry]` | Declare durable retry policy on a swarm or agent method. | [Durable Retries And Progress](durable-retries-and-progress.md) |
| `#[DurableWait]` | Declare durable waits entered after checkpoints. | [Durable Waits And Signals](durable-waits-and-signals.md) |
| `#[DurableLabels]` | Attach initial durable labels for inspection. | [Durable Waits And Signals](durable-waits-and-signals.md#labels-and-details), [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `#[DurableDetails]` | Attach durable details for inspection. | [Durable Waits And Signals](durable-waits-and-signals.md#labels-and-details), [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `#[MemoryReplay]` | Override the global `swarm.memory.replay_mode` config for a single swarm class. Accepts `ReplayMode::FrozenView` (default — agents re-execute against the memory snapshot frozen at the original invocation, preserving the canonical audit record) or `ReplayMode::FreshExecution` (agents re-execute against live memory; use only when idempotency is guaranteed externally). When absent, the global config default (`frozen_view`) applies. (v0.9.0+) | [Swarm Memory](memory.md#replay-semantics) |
| `#[PropagationPolicy]` | Override the global `swarm.memory.propagation_policy` for a single swarm class — the `MemoryPropagationPolicy` deciding which memory entries a worker agent sees at invocation. Resolved through the container; e.g. `#[PropagationPolicy(ConversationPropagationPolicy::class)]`. When absent, the configured/default policy (Run-scoped view) applies. (v0.10.0+) | [Swarm Memory](memory.md#propagation-policy) |

## Testing Surface

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `fake()` | Intercept swarm execution in application tests. | [Testing](testing.md), [Testing Swarms](../examples/testing-swarms/README.md) |
| `assertPrompted()` / `assertNeverPrompted()` | Assert synchronous calls. | [Testing](testing.md#asserting-basic-interaction) |
| `assertRan()` / `assertNeverRan()` | Assert compatibility `run()` calls. | [Testing](testing.md#asserting-basic-interaction) |
| `assertQueued()` / `assertNeverQueued()` | Assert queued calls and queued stream-broadcast jobs. | [Testing](testing.md#asserting-basic-interaction) |
| `assertStreamed()` / `assertNeverStreamed()` | Assert stream calls after lazy consumption. | [Testing](testing.md#database-backed-durable-execution), [Streaming](streaming.md) |
| `assertDispatchedDurably()` / `assertNeverDispatchedDurably()` | Assert durable dispatch intent. | [Testing](testing.md#database-backed-durable-execution) |
| Durable fake assertions | Assert signals, waits, progress, labels, details, retries, and child swarm intent. | [Testing](testing.md), [Durable topic guides](durable-execution.md) |
| `assertPersisted()` | Assert persisted history records. | [Testing](testing.md#asserting-persisted-runs) |
| `assertEventFired()` | Assert lifecycle events recorded by fakes. | [Testing](testing.md#asserting-lifecycle-events) |
| `BuiltByBerry\LaravelSwarm\Testing\ScriptedAgent` | Abstract base for runnable provider-free agents. Subclasses implement `instructions(): string` and `reply(string $prompt): string`; the shipped `prompt()` wraps the reply in a standard `AgentResponse`. Used by the starter examples (`stubs/examples/`) and scaffolded by `make:swarm:agent`. `stream()`, `queue()`, and broadcast helpers throw with a clear "use a `Promptable` agent + `Agent::fake()`" message — this base is for shape demos and end-to-end smoke tests, not the test-double surface. (v0.8.0+) | [Examples](examples.md), [Generators](generators.md) |

## Durable Manager Operations

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `inspect()` / `find()` | Read durable runtime state. | [Durable Execution](durable-execution.md#durable-operator-surfaces), [Run Inspector](../examples/run-inspector/README.md) |
| `inspectByLabels()` | Find durable runs by labels. | [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `updateLabels()` | Update durable labels used for inspection. | [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `updateDetails()` | Update durable details used for inspection. | [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `wait()` | Create or enter a durable wait. | [Durable Waits And Signals](durable-waits-and-signals.md) |
| `signal()` | Record a signal and release matching waits when possible. | [Durable Waits And Signals](durable-waits-and-signals.md), [Durable Webhooks](durable-webhooks.md) |
| `recordProgress()` | Store latest-state progress. | [Durable Retries And Progress](durable-retries-and-progress.md) |
| `dispatchChildSwarm()` | Start a child durable run and checkpoint the parent. | [Durable Child Swarms](durable-child-swarms.md) |
| `pause()` / `resume()` / `cancel()` | Operator controls for durable runs. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `recover()` | Redispatch recoverable runs, branches, waits, retries, and child reconciliations. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover), [Maintenance](maintenance.md#scheduling) |

## Artisan Commands

| Command | Purpose | Primary documentation |
| --- | --- | --- |
| `swarm:install` | **Recommended first run after `composer require`** — interactive one-command installer. Publishes `config/swarm.php`, seeds `.env` keys, runs migrations (or scaffolds `LaravelSwarm::ignoreMigrations()` for cache-only), warns on `QUEUE_CONNECTION=sync`, and offers to dispatch each sub-installer. Flags: `--persistence=database\|cache`, `--migrate`/`--skip-migrate`, `--with-{durable,audit,pulse,memory,examples}` / `--without-{...}`, `--force`, `--force-env`, `--no-interaction`. (v0.8.0+) | [Getting Started](getting-started.md) |
| `swarm:install:durable` | Wire the durable runtime: inject scheduler entries (`swarm:relay`, `swarm:recover`, `swarm:prune`) into `routes/console.php`, verify persistence/queue, print copy-paste worker snippets. Standalone or dispatched from `swarm:install`. Flags: `--queue=<name>`, `--migrate`/`--skip-migrate`, `--allow-sync-queue`. (v0.8.0+) | [Durable Execution](durable-execution.md#quick-setup) |
| `swarm:install:audit` | Scaffold a `SwarmAuditSink` binding (plus optional `SwarmAuditSigner` / `ActorResolver` / `CapturePolicy` stubs) into `AppServiceProvider`. Standalone or dispatched from `swarm:install`. Flags: `--sink=readable\|noop\|custom`, `--with-signer`, `--with-actor-resolver`, `--with-capture-policy`. (v0.8.0+) | [Audit Evidence Contract](audit-evidence-contract.md#quick-setup) |
| `swarm:install:pulse` | Register the Swarm Pulse recorders + dashboard cards. Refuses gracefully when `laravel/pulse` is not installed. Flags: `--cards=runs,steps,audit-outbox,memory`, `--force`. (v0.8.0+; `memory` card added v0.10.0+) | [Pulse](pulse.md) |
| `swarm:install:memory` | Publish the `swarm_memories` and `swarm_memory_snapshots` migrations, seed `SWARM_MEMORY_REPLAY_MODE` in `.env`, and bind `SwarmMemory` + `SnapshotsMemory` in the container. Standalone or dispatched from `swarm:install --with-memory`. Flags: `--migrate`/`--skip-migrate`, `--replay-mode=frozen_view\|fresh_execution`, `--force-env`. (v0.9.0+) | [Swarm Memory](memory.md#quick-setup) |
| `swarm:install:examples` | Copy curated starter examples from `stubs/examples/` into `app/Ai/Swarms/<Name>/`, rewriting namespace placeholders. Flags: `--example=<name>` (repeatable), `--all`, `--force`. (v0.8.0+) | [Examples](examples.md) |
| `make:swarm:swarm` + `make:swarm:agent` | The v0.8.0 generator pair. Use `make:swarm:swarm <Name>` to scaffold a swarm class (with `--topology=sequential\|parallel\|hierarchical\|static-hierarchical`); use `make:swarm:agent <Name>` to scaffold an agent class extending `ScriptedAgent`. Stub shape matches the runnable starter examples in `stubs/examples/`. (v0.8.0+) | [Generators](generators.md) |
| `make:memory-tool <Name>` | Scaffold a custom memory tool into `app/Ai/Tools/<Name>.php`, extending the shipped `Recall`/`Remember` tools so it inherits their scope-resolution and policy guarantees. Flags: `--scope=run\|conversation\|agent\|swarm`, `--base=recall\|remember`, `--vector` (requires the `laravel-swarm-memory-vector` companion), `--force`. Stub shape matches the shipped tools; stubs (`swarm.memory-tool.stub`, `swarm.memory-tool.vector.stub`) are publishable via `vendor:publish --tag=swarm-stubs`. (v0.11.0+) | [Generators](generators.md#make-memory-tool) |
| `make:swarm` | **Deprecated** since v0.8.0 — alias for `make:swarm:swarm` that prints a migration hint on stderr. Slated for removal in a future major release. | [Generators](generators.md#migration-from-makeswarm) |
| `swarm:health` | Verify configured stores, durable tables, and audit-outbox state. Options: `--durable` (durable infrastructure only), `--audit` (audit-outbox checks only; v0.5.0+). | [Getting Started](getting-started.md), [Maintenance](maintenance.md) |
| `swarm:status` | Inspect a run status from persisted history. | [Persistence And History](persistence-and-history.md#inspecting-run-history-in-the-console) |
| `swarm:history` | List persisted run history. | [Persistence And History](persistence-and-history.md#inspecting-run-history-in-the-console) |
| `swarm:inspect` | Inspect durable runtime detail. | [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `swarm:progress` | Inspect durable progress records. | [Durable Retries And Progress](durable-retries-and-progress.md) |
| `swarm:signal` | Send an operator signal to a durable run. | [Durable Waits And Signals](durable-waits-and-signals.md) |
| `swarm:pause` | Pause a durable run at the next safe boundary. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:resume` | Resume a paused durable run. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:cancel` | Cancel a durable run. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:relay` | Drain the durable outbox and audit outbox in a single pass, dispatching queued step/branch jobs and replaying failed audit evidence. Must be scheduled (`everyMinute()`). Options: `--type=step\|branch\|audit` (v0.5.0 adds the `audit` lane), `--limit=N`, `--drain-until-empty`, `--max-attempts=N`. | [Maintenance](maintenance.md#scheduling) |
| `swarm:recover` | Redispatch recoverable durable work. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover), [Maintenance](maintenance.md#scheduling) |
| `swarm:prune` | Remove expired database persistence rows. | [Maintenance](maintenance.md#pruning-expired-records) |
| `swarm:memory:inspect` | Inspect frozen `MemorySnapshot` rows for a run — the agent-visible memory entries and tool-call input/output pairs recorded at each step. Read-only; routes through `SnapshotsMemory` and surfaces a configuration hint under the cache driver. Dispatches `MemoryInspected` and emits the `command.memory.inspect` audit category. Required `<run-id>`; flags: `--step=N`, `--format=table\|json`, `--scope=run\|conversation\|agent\|swarm`. (v0.10.0+) | [Compliance & Audit](compliance-audit.md#inspect-a-frozen-snapshot) |
| `swarm:memory:dump` | Export the complete memory + snapshot trail for a run (or conversation) as a stable JSON/NDJSON envelope, built for audit packets and DSAR handoff. Read-only; refuses an ambiguous run/conversation id and writes owner-only (0600) exclusive-create files. Dispatches `MemoryDumped` and emits the `command.memory.dump` audit category. Required `<id>`; flags: `--as=run\|conversation`, `--format=json\|ndjson`, `--include-snapshots`, `--output=FILE`, `--reason=<text>`. (v0.10.0+) | [Swarm Memory](memory.md#exporting-a-full-run-with-swarmmemorydump), [Compliance & Audit](compliance-audit.md#exporting-an-audit-packet) |
| `swarm:memory:purge` | Enforce per-scope `swarm_memories` retention windows (`swarm.memory.retention.days` for `run`/`conversation`/`agent`/`swarm`), cascading owned `swarm_memory_snapshots` **and** `swarm_stream_step_checkpoints` (#202) for Run-scoped purges. Dispatches the `MemoryPurged` event (with `snapshots`/`checkpoints` counts) and emits the `command.memory.purge` audit category; honors `swarm.retention.prevent_prune`. Flags: `--dry-run`, `--scope=<value>`, `--keep-snapshots` (retains both the snapshot and checkpoint cascades), `--pause=<ms>`. (v0.10.0+) | [Compliance & Audit](compliance-audit.md#memory-retention) |
| `swarm:audit:status` | Summarize the audit outbox — counts, age distribution, top dead-letter categories, oldest rows, and retention. Option: `--json`. (v0.6.0+) | [Audit Outbox Operator Runbook](operator-runbook-audit-outbox.md) |
| `swarm:audit:reconcile` | Forensic CLI for audit-outbox triage. Defaults to `list`; gains `--show=<id>`, `--requeue=<id>`, and `--dismiss=<id> --reason="..."` for dead-letter rows. Options: `--status`, `--limit`, `--json`, `--force`. (v0.6.0+) | [Audit Outbox Operator Runbook](operator-runbook-audit-outbox.md) |
| `swarm:trace` | Read-only audit-chain reconstruction for a single run — merges history, outbox, and sink-side records (via `ReadableSwarmAuditSink`) into a chronological timeline. Options: `--json`, `--include-payloads`, `--limit=N` (default 1000, sink-side only). Degrades cleanly when the sink does not implement `ReadableSwarmAuditSink` or the outbox is unavailable. Unseals encrypted-at-rest data on output — in regulated environments do not redirect to durable storage; see [Security and retention](audit-evidence-contract.md#security-and-retention). (v0.7.0+) | [Audit Evidence Contract](audit-evidence-contract.md#reading-the-audit-chain) |

## Extension Points

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `HasRoutePlan` | Implement on a `StaticHierarchical` swarm to return a fixed route-plan array. | [Static Hierarchical Topology](static-hierarchical-topology.md) |
| `ConfiguresDurableRetries` | Configure per-swarm and per-agent durable retry policies. | [Durable Retries And Progress](durable-retries-and-progress.md) |
| `RoutesDurableBranches` | Route durable parallel branches to a specific queue connection and queue name. | [Durable Execution](durable-execution.md) |
| `RoutesDurableWaits` | Declare durable waits entered after checkpoints; each wait specifies name, optional timeout, and optional metadata. | [Durable Waits And Signals](durable-waits-and-signals.md) |
| `DispatchesChildSwarms` | Declare durable child swarms dispatched after checkpoints; parent pauses until all children complete. | [Durable Child Swarms](durable-child-swarms.md) |
| `ContextStore` | Store active run context. | [Persistence And History](persistence-and-history.md) |
| `ArtifactRepository` | Store step and run artifacts. | [Persistence And History](persistence-and-history.md) |
| `RunHistoryStore` | Store run and step history. | [Persistence And History](persistence-and-history.md) |
| `StreamEventStore` | Store replayable stream events. | [Streaming](streaming.md), [Persistence And History](persistence-and-history.md#replaying-stream-events) |
| `DurableRunStore` | Durable runtime persistence. | [Durable Runtime Architecture](durable-runtime-architecture.md), [Durable Execution](durable-execution.md) |
| `SwarmInputGuardrail` | Validate task input before any agent runs. | [Guardrails](guardrails.md), [Guardrails Policy](../examples/guardrails-policy/README.md) |
| `SwarmStepGuardrail` | Validate each agent output before the step is recorded. | [Guardrails](guardrails.md), [Guardrails Policy](../examples/guardrails-policy/README.md) |
| `SwarmOutputGuardrail` | Validate final output before completion is persisted. | [Guardrails](guardrails.md), [Guardrails Policy](../examples/guardrails-policy/README.md) |
| `DefinesGuardrails` | Declare per-swarm guardrails resolved from the container. | [Guardrails](guardrails.md), [Guardrails Policy](../examples/guardrails-policy/README.md) |
| `SwarmTelemetrySink` | Export operational telemetry payloads. | [Observability Correlation Contract](observability-correlation-contract.md) |
| `SwarmAuditSink` | Export append-only audit evidence payloads. | [Audit Evidence Contract](audit-evidence-contract.md) |
| `SwarmWebhooks::routes()` | Register authenticated durable webhook ingress. | [Durable Webhooks](durable-webhooks.md) |
| `LaravelSwarm::ignoreMigrations()` | Disable automatic package migration loading. | [README: Installation](../README.md#installation) |

## Audit Extension Points

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `ActorResolver` | Resolve the acting principal recorded on audit evidence. | [Audit Evidence Contract](audit-evidence-contract.md#actor-binding) |
| `Actor` | Value object describing the resolved actor identity. | [Audit Evidence Contract](audit-evidence-contract.md#actor-binding) |
| `RunContext::withActor()` | Bind an explicit actor to a run before dispatch. | [Audit Evidence Contract](audit-evidence-contract.md#actor-binding) |
| `MissingActorException` | Thrown when actor resolution is required but unavailable. | [Audit Evidence Contract](audit-evidence-contract.md#actor-binding) |
| `CapturePolicy` | Decide whether each evidence payload (inputs, outputs, artifacts, active context) is captured, redacted, or omitted. Returns `CaptureDecision`. Default `BooleanCapturePolicy` reads `swarm.capture.*` and returns only `Full`/`Redact`. | [Audit Evidence Contract](audit-evidence-contract.md#capture-policy) |
| `CaptureDecision` | Enum of capture outcomes: `Full` (as-is), `Redact` (scalars → `[redacted]`, keys preserved), `Skip` (**true omission on the evidence surfaces as of v0.12.0** — key absent / evidence column `NULL` / `error.message` dropped; the operational active-context input is retained for durable resume; behaved like `Redact` through v0.4–v0.11). | [Audit Evidence Contract](audit-evidence-contract.md#capture-policy) |
| `SwarmAuditSigner` | Sign audit envelopes for tamper-evident chains. | [Audit Evidence Contract](audit-evidence-contract.md#audit-signing) |
| `SinkFailureHandler` | Decide how to react when an audit sink throws. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `SinkFailureDecision` | Enum of sink failure outcomes (`Swallow`, `RetryInline`, `Halt`, `Queue`, `DeadLetter`). `Queue` and `DeadLetter` added in v0.5.0 alongside the audit outbox. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `AuditOutbox` | Persisted retry surface for audit evidence that failed to emit. Drained by `swarm:relay --type=audit`. (v0.5.0+) | [Audit Evidence Contract](audit-evidence-contract.md#audit-outbox) |
| `ReadableSwarmAuditSink` | Optional extension of `SwarmAuditSink` that adds `forRun(string $runId): iterable` so the sink can participate in `swarm:trace`. Opt-in; the default `NoOpSwarmAuditSink` does not implement it and existing custom sinks remain valid. (v0.7.0+) | [Audit Evidence Contract](audit-evidence-contract.md#reading-the-audit-chain) |
| `LogChannelSwarmAuditSink` | Concrete `SwarmAuditSink` implementation that writes every audit record as a structured log entry (`swarm.audit.<category>`) to the configured Laravel log channel (defaults to `audit`, falls back to the default channel when `audit` is not configured). Dev/staging-friendly zero-config sink; production deployments should ship a bounded backend. Bound by `swarm:install:audit --sink=readable`. Does not implement `ReadableSwarmAuditSink` (log channels are not queryable); `swarm:trace` degrades gracefully when this sink is bound. (v0.8.0+) | [Audit Evidence Contract](audit-evidence-contract.md#quick-setup) |
| `HaltsSwarmExecution` | Marker interface for sink failure exceptions that must halt the run. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `AuditSinkHaltedException` | Raised when a sink failure handler halts execution. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |

## Memory (v0.9.0+)

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `SwarmMemory` | Primary contract for reading and writing scoped memory entries. Methods: `put(scope, scopeId, key, value, metadata)`, `get(scope, scopeId, key)`, `entry(scope, scopeId, key)`, `forget(scope, scopeId, key)`, `all(scope, scopeId)`. Resolved from the container via `app(SwarmMemory::class)`. Custom drivers and test doubles implement this contract. (v0.9.0+) | [Swarm Memory](memory.md#reading-and-writing) |
| `MemoryEntry` | Immutable value object returned by `put()`, `entry()`, and `all()`. Properties: `scope` (`MemoryScope`), `scopeId` (string), `key` (string), `value` (mixed — plain data only), `metadata` (array), `createdAt` (`?CarbonImmutable`), `updatedAt` (`?CarbonImmutable`). (v0.9.0+) | [Swarm Memory](memory.md#memoryentry) |
| `MemoryScope` | Enum addressing the four memory scopes. Cases: `Run` (bounded to a single run), `Conversation` (shared across runs in a thread), `Agent` (per-agent-class persistent state), `Swarm` (shared across all agents in a swarm class). (v0.9.0+) | [Swarm Memory](memory.md#scope-hierarchy) |
| `MemorySnapshot` | Immutable value object representing the frozen memory view captured before each agent invocation. Properties: `runId`, `stepIndex`, `entries` (the agent-visible entries the `MemoryPropagationPolicy` surfaced — Run-scoped by default, may span scopes under a custom policy; each row carries its own `scope`/`scope_id`), `toolCalls` (input/output pairs for the invocation), `frozen` (true on snapshots loaded from persistence). (v0.9.0+) | [Swarm Memory](memory.md#snapshot-mechanism) |
| `StreamStepCheckpoint` | Immutable value object returned by `StreamStepCheckpointStore::find()` — a completed non-final streamed step's recorded output + usage, for idempotent multi-step resume (#202). Properties: `runId`, `stepIndex`, `output` (the raw value fed into the next step's prompt), `usage`, `recordedAt`, `updatedAt`; `fromPersisted()` factory. (v0.12.0+) | [Streaming — Crash-Replay Durability](streaming.md#crash-replay-durability) |
| `SnapshotsMemory` | Contract for the snapshot recorder. Methods: `snapshot(runId, stepIndex, ?entries = null)` — capture and persist a snapshot; the optional third `entries` argument carries the propagation-policy-filtered view, and **classes implementing the contract must add this parameter** (required signature change, v0.10.0); `allForRun(runId)` — return every persisted snapshot for a run ordered by step index (**new required method, v0.10.0**); `appendToolCall(snapshot, call)`; `resetToolCalls(snapshot)`; `find(runId, stepIndex)`. Used by all four runners, by `MemoryReplayCoordinator`, and by `swarm:memory:inspect`/`swarm:memory:dump`. (v0.9.0+; signature change v0.10.0 — see [UPGRADING](../UPGRADING.md#upgrading-to-v0100)) | [Swarm Memory](memory.md#snapshot-mechanism) |
| `StreamStepCheckpointStore` | Contract recording a completed non-final streamed step's raw output + usage so an abandoned `stream()` run resumes idempotently (skips the step's agent invocation, rehydrates its output). Methods: `record(runId, stepIndex, output, usage)` — upsert the completed checkpoint; `find(runId, stepIndex)` — return the completed `StreamStepCheckpoint` or `null` (a step that crashed before completion reads as absent). Bound in lockstep with `SnapshotsMemory` (database driver → `DatabaseStreamStepCheckpointStore`; cache driver → no-op `NullStreamStepCheckpointStore`). The non-durable analogue of the durable runtime's per-node output store. Used by `SequentialRunner` on the streamed path. (v0.12.0+) | [Streaming — Crash-Replay Durability](streaming.md#crash-replay-durability) |
| `MemoryPropagationPolicy` | Contract deciding which memory entries a worker agent sees at invocation — `scopes()` declares which scopes to gather, `present()` filters/orders them. The runners freeze the policy's view into each snapshot. Bind globally via `swarm.memory.propagation_policy` or per swarm with `#[PropagationPolicy]`. Bundled: `DefaultPropagationPolicy` (Run-scoped view, preserves pre-v0.10 behavior) and `ConversationPropagationPolicy` (step-output transcript). (v0.10.0+) | [Swarm Memory](memory.md#propagation-policy) |
| `MemoryCapturePolicy` | Contract deciding, at the write boundary, whether each memory entry is persisted as-is, redacted, or dropped (`CaptureDecision::Full\|Redact\|Skip`) — the write-side counterpart to the audit `CapturePolicy`. Enforced by the `RedactingMemoryStore` decorator. Bind via `swarm.memory.capture_policy`. Bundled `DefaultMemoryCapturePolicy` returns `Full` for every write (preserves pre-v0.10 behavior). (v0.10.0+) | [Swarm Memory](memory.md#capture-policy-write-time-redaction), [Compliance & Audit](compliance-audit.md#memory-capture-policy) |
| `ConversationRunResolver` | Contract resolving a conversation id to its constituent run ids, used by `swarm:memory:dump` to expand a conversation export. Bundled `NullConversationRunResolver` resolves to none (`runs_expanded: false`); applications bind their own to light up expansion with no breaking change. Stable as of v0.12.0 (promoted from `@experimental`; the `string -> list<string>` signature is fixed). (v0.10.0+) | [Swarm Memory](memory.md#exporting-a-full-run-with-swarmmemorydump) |
| `Concerns\RemembersRunContext` | Opt-in trait for a `laravel/ai` agent that also implements `Laravel\Ai\Contracts\Conversational`. Implements `messages()` to render the active run's propagation-policy memory view as `Message[]`. Overridable hooks: `runContextMessageRole(): MessageRole` and `mergeRunContextMessages(array): iterable`. No-op outside a swarm run. (`ActiveRunContext` and `RunContextMemoryReader` that back it are `@internal`.) (v0.10.0+) | [Swarm Memory](memory.md#reading-run-memory-inside-an-agent-with-remembersruncontext) |
| `Tools\Recall` | `laravel/ai` `Tool` an agent uses mid-prompt to read run memory. Args: `key` (single value), `prefix` (key prefix), `scope` (default `run`). Reads through the active swarm's `MemoryPropagationPolicy`, so it can only surface what the policy permits; degrades to a graceful message outside a run. Subclass to override `description()` or bind a specific agent. (v0.11.0+) | [Swarm Memory](memory.md#recall-and-remember-tools) |
| `Tools\Remember` | `laravel/ai` `Tool` an agent uses mid-prompt to write run memory. Args: `key`, `value`, `scope` (default `run`). Writes through `SwarmMemory::put()`, so the `MemoryCapturePolicy` redacts/drops at the boundary; scope id is resolved from the active run (never the model) and reserved `swarm:` keys are rejected. Graceful no-op outside a run. (v0.11.0+) | [Swarm Memory](memory.md#recall-and-remember-tools) |
| `Concerns\HasSwarmMemoryTools` | Opt-in trait exposing `swarmMemoryTools(): array` for an agent's `tools()`. Returns the `Recall`/`Remember` tools only when `swarm.memory.tools.enabled` is true, honouring the per-tool `recall`/`remember` toggles; resolves the tool classes from the container so a bound subclass is used. The "optional default-on registration" surface. (v0.11.0+) | [Swarm Memory](memory.md#recall-and-remember-tools) |
