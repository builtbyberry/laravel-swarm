# Laravel Swarm Documentation

Laravel Swarm is a Laravel-native multi-agent orchestration package that lets you compose AI agents into typed, testable, production-grade workflows.

→ New to Laravel Swarm? Start with [Introduction](introduction.md).

---

## Getting Started

The recommended reading path for new users.

- [Introduction](introduction.md) — what Swarm is, the three core concepts, and a decision tree for where to start
- [Sequential Topology](sequential.md) — the default topology; start here to build your first swarm
- [Choosing an Execution Mode](execution-modes.md) — prompt, queue, stream, or durable: when to use each
- [Structured Input](structured-input.md)
- [Testing](testing.md)

---

## Topologies

- [Sequential](sequential.md) — chain of agents, each receives the previous output
- [Parallel](parallel.md) — all agents receive the same task and run concurrently
- [Hierarchical Routing](hierarchical-routing.md) — a coordinator agent routes to workers at runtime
- [Static Hierarchical](static-hierarchical-topology.md) — a developer-defined execution graph, no coordinator LLM call

---

## Execution Modes

- [Choosing an Execution Mode](execution-modes.md) — comparison table and decision tree
- [Streaming](streaming.md) — real-time token streaming
- [Durable Execution](durable-execution.md) — checkpointed, recoverable, long-running workflows

---

## Data & Context

- [RunContext](run-context.md) — the envelope that carries input, identity, and carry-forward data through a run
- [Artifacts](artifacts.md) — named content attached to runs and steps
- [Structured Input](structured-input.md) — passing arrays and typed input to swarms
- [Persistence & History](persistence-and-history.md) — how run history is stored and queried

---

## Reliability & Safety

- [Guardrails](guardrails.md) — input, step, and output validation; child inheritance
- [Error Handling](error-handling.md) — exception taxonomy, failure behavior per mode, and recovery

---

## Durable Operations

- [Durable Execution](durable-execution.md) — checkpointing, the relay, recovery, and operator controls
- [Durable Runtime Architecture](durable-runtime-architecture.md)
- [Waits & Signals](durable-waits-and-signals.md)
- [Retries & Progress](durable-retries-and-progress.md)
- [Child Swarms](durable-child-swarms.md)
- [Webhooks](durable-webhooks.md)

---

## Observability

- [Lifecycle Events](events.md) — every event class, its properties, and firing guarantees
- [Logging & Tracing](observability-logging-tracing.md) — telemetry sink, structured logs, OTEL
- [Correlation Contract](observability-correlation-contract.md)
- [Audit Evidence](audit-evidence-contract.md)
- [Pulse](pulse.md) — Laravel Pulse cards for run counts and step latencies

---

## Reference

- [Configuration](configuration.md) — every config key, grouped and searchable
- [Public Surface](public-surface.md)
- [Maintenance](maintenance.md)

---

## Examples

Read [examples/README.md](../examples/README.md) for the recommended order and feature coverage table.

The examples are copy-paste starting points for Laravel applications. They are not a demo application and are not autoloaded by the package.

- [sequential-content-pipeline](../examples/sequential-content-pipeline/README.md) — the first copy-paste workflow; a simple sequential swarm
- [parallel-research-swarm](../examples/parallel-research-swarm/README.md) — concurrent independent agents
- [hierarchical-support-triage](../examples/hierarchical-support-triage/README.md) — coordinator-routed workers
- [static-hierarchical-content-swarm](../examples/static-hierarchical-content-swarm/README.md) — developer-defined execution graph
- [guardrails-policy](../examples/guardrails-policy/README.md) — input, step, and output validation
- [queued-workflow-events](../examples/queued-workflow-events/README.md) — background execution and lifecycle events
- [streaming-broadcasts](../examples/streaming-broadcasts/README.md) — real-time token streaming over broadcast
- [streaming-progress](../examples/streaming-progress/README.md) — streaming with progress tracking
- [run-inspector](../examples/run-inspector/README.md) — composing a status endpoint around `run_id`
- [operations-dashboard](../examples/operations-dashboard/README.md) — application-owned events and dashboard projections
- [testing-swarms](../examples/testing-swarms/README.md) — application-level test examples
- [privacy-capture](../examples/privacy-capture/README.md) — redaction and capture in regulated workflows
- [human-in-the-loop-support](../examples/human-in-the-loop-support/README.md) — pausing for human review or approval
- [durable-waits-signals](../examples/durable-waits-signals/README.md) — durable waits and external signal ingress
- [durable-retries-progress-child-swarms](../examples/durable-retries-progress-child-swarms/README.md) — retry policies and parent-child lineage
- [durable-webhook-ingress](../examples/durable-webhook-ingress/README.md) — authenticated webhook start and signal ingress
- [durable-hierarchical-approval](../examples/durable-hierarchical-approval/README.md) — durable hierarchical workflow with operator approval
- [durable-compliance-review](../examples/durable-compliance-review/README.md) — checkpointed compliance review with audit evidence

---

## For Production Teams

Laravel Swarm is designed for production-grade AI workflows with operator controls, auditability, and durable execution. A recommended reading path for architects and engineering leads:

1. [Introduction](introduction.md) — understand the topology and execution mode model
2. [Choosing an Execution Mode](execution-modes.md) — pick the right mode for each workflow
3. [Durable Execution](durable-execution.md) — understand checkpointing, recovery, and operational overhead
4. [Audit Evidence](audit-evidence-contract.md) — compliance and auditability guarantees
5. [Maintenance](maintenance.md) — retention, pruning, and long-term operational hygiene
