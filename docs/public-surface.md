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
| `RunContext` | Explicit run input, run ID, data, metadata, artifacts, labels, and details. | [Structured Input](structured-input.md), [Persistence And History](persistence-and-history.md) |
| `SwarmHistory` | Query persisted history and replay stored stream events. | [Persistence And History](persistence-and-history.md), [Run Inspector](../examples/run-inspector/README.md) |

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
| `make:swarm` | Generate a swarm class. | [README: Your First Swarm](../README.md#your-first-swarm) |
| `swarm:health` | Verify configured stores and durable tables. | [README: Installation](../README.md#installation), [Maintenance](maintenance.md) |
| `swarm:status` | Inspect a run status from persisted history. | [Persistence And History](persistence-and-history.md#inspecting-run-history-in-the-console) |
| `swarm:history` | List persisted run history. | [Persistence And History](persistence-and-history.md#inspecting-run-history-in-the-console) |
| `swarm:inspect` | Inspect durable runtime detail. | [Durable Execution](durable-execution.md#durable-operator-surfaces) |
| `swarm:progress` | Inspect durable progress records. | [Durable Retries And Progress](durable-retries-and-progress.md) |
| `swarm:signal` | Send an operator signal to a durable run. | [Durable Waits And Signals](durable-waits-and-signals.md) |
| `swarm:pause` | Pause a durable run at the next safe boundary. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:resume` | Resume a paused durable run. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:cancel` | Cancel a durable run. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover) |
| `swarm:relay` | Drain the durable outbox and dispatch queued step/branch jobs. Must be scheduled (`everyMinute()`). Options: `--type=step\|branch`, `--limit=N`, `--drain-until-empty`, `--max-attempts=N`. | [Maintenance](maintenance.md#scheduling) |
| `swarm:recover` | Redispatch recoverable durable work. | [Durable Execution](durable-execution.md#pause-resume-cancel-and-recover), [Maintenance](maintenance.md#scheduling) |
| `swarm:prune` | Remove expired database persistence rows. | [Maintenance](maintenance.md#pruning-expired-records) |

## Extension Points

| Surface | Purpose | Primary documentation |
| --- | --- | --- |
| `HasRoutePlan` | Implement on a `StaticHierarchical` swarm to return a fixed route-plan array. | [Static Hierarchical Topology](static-hierarchical-topology.md) |
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
| `CapturePolicy` | Decide whether each evidence payload is captured, redacted, or skipped. | [Audit Evidence Contract](audit-evidence-contract.md#capture-policy) |
| `CaptureDecision` | Enum of capture outcomes (`Full`, `Redact`, `Skip`). | [Audit Evidence Contract](audit-evidence-contract.md#capture-policy) |
| `SwarmAuditSigner` | Sign audit envelopes for tamper-evident chains. | [Audit Evidence Contract](audit-evidence-contract.md#audit-signing) |
| `SinkFailureHandler` | Decide how to react when an audit sink throws. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `SinkFailureDecision` | Enum of sink failure outcomes (`Swallow`, `RetryInline`, `Halt`). | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `HaltsSwarmExecution` | Marker interface for sink failure exceptions that must halt the run. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
| `AuditSinkHaltedException` | Raised when a sink failure handler halts execution. | [Audit Evidence Contract](audit-evidence-contract.md#sink-failure-handler) |
