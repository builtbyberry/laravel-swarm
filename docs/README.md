# Laravel Swarm Documentation

Laravel Swarm documentation is organized around the path most applications take:
install the package, create a swarm, run it, choose the right execution mode,
then add persistence, operations, testing, and observability as the workflow
becomes production-critical.

If you are new to the package, start with the README and the first example.
Return to this page when you need the deeper guide for a specific feature.

## Getting Started

- [Package README](../README.md): installation, first swarm, execution modes, and production checklist.
- [Sequential Content Pipeline](../examples/sequential-content-pipeline/README.md): the first copy-paste workflow.
- [Structured Input](structured-input.md): strings, arrays, `RunContext`, labels, details, metadata, and queue-safe payloads.
- [Public Surface Coverage](public-surface.md): where each public method, command, attribute, and extension point is documented.

## Execution Modes

- [Prompting a swarm](../README.md#running-a-swarm): synchronous `prompt()` / `run()` usage.
- [Queueing a swarm](../README.md#queueing-a-swarm): background execution and queue-safe payloads.
- [Streaming](streaming.md): sequential stream events, SSE, broadcast helpers, replay, and failure behavior.
- [Durable Execution](durable-execution.md): checkpointed execution, recovery, branch jobs, and operator controls.

## Topologies

- [Sequential topology](../README.md#sequential): fixed agent order and output handoff.
- [Parallel topology](../examples/parallel-research-swarm/README.md): concurrent independent agents and container-resolution rules.
- [Hierarchical Routing](hierarchical-routing.md): coordinator route plans, DAG validation, parallel nodes, queued coordination, and durable joins.

## Persistence And History

- [Persistence And History](persistence-and-history.md): context, artifacts, history, replay rows, drivers, encryption, capture, limits, and custom tables.
- [Run Inspector example](../examples/run-inspector/README.md): composing a status endpoint around `run_id`.
- [Maintenance](maintenance.md): pruning, migrations, scheduling, storage growth, and production release checks.

## Durable Operations

- [Durable Execution](durable-execution.md): durable runtime model and operational state.
- [Durable Waits And Signals](durable-waits-and-signals.md): pausing at external or operator boundaries.
- [Durable Retries And Progress](durable-retries-and-progress.md): retry policies, progress records, and inspection.
- [Durable Child Swarms](durable-child-swarms.md): parent-child lineage and reconciliation.
- [Durable Webhooks](durable-webhooks.md): authenticated webhook start and signal ingress.
- [Durable Runtime Architecture](durable-runtime-architecture.md): PHP collaborator graph for testing and extension.

## Observability And Audit

- [Observability: Logging And Tracing](observability-logging-tracing.md): lifecycle events, queue context, logs, and tracing.
- [Observability Correlation Contract](observability-correlation-contract.md): telemetry sink payloads and category contract.
- [Audit Evidence Contract](audit-evidence-contract.md): append-only evidence payloads for regulated environments.
- [Pulse](pulse.md): aggregate Pulse cards and recorder setup.
- [Operations Dashboard example](../examples/operations-dashboard/README.md): application-owned events and dashboard projections.

## Testing

- [Testing](testing.md): fakes, assertions, persisted history assertions, lifecycle events, and durable feature-test guidance.
- [Testing Swarms example](../examples/testing-swarms/README.md): application-level test examples.

## Examples

Read [examples/README.md](../examples/README.md) for the recommended order and feature coverage table.

The examples are copy-paste starting points for Laravel applications. They are
not a demo application and are not autoloaded by the package.

## Production Reading Path

For a workflow that will run in production, read these in order:

1. [Package README](../README.md)
2. [Structured Input](structured-input.md)
3. [Persistence And History](persistence-and-history.md)
4. [Testing](testing.md)
5. [Maintenance](maintenance.md)
6. [Observability: Logging And Tracing](observability-logging-tracing.md)
7. [Durable Execution](durable-execution.md), if the workflow needs checkpointing or operator controls.
