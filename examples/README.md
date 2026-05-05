# Laravel Swarm Examples

These examples are copy-paste starting points for Laravel applications. They
are not autoloaded by the package and are not a runnable demo app.

Examples assume your app has Laravel AI configured. When an example does not
show `#[Provider]` and `#[Model]`, the agent uses your Laravel AI defaults. The
first sequential example shows the explicit provider/model attribute pattern.

## Before You Copy An Example

Every example assumes:

- a Laravel 13 application with Laravel AI configured;
- this package installed and migrated, unless the example explicitly uses only
  cache persistence;
- generated or manually created agent classes under `app/Ai/Agents`;
- plain task payloads made from strings, numbers, booleans, null, and arrays.

Examples that use `queue()`, `broadcastOnQueue()`, or `dispatchDurable()` also
need a running queue worker. Examples that use durable execution need database
persistence and `SWARM_CAPTURE_ACTIVE_CONTEXT=true`.

## Start Here

Read the examples in this order:

1. [Sequential Content Pipeline](sequential-content-pipeline/README.md): build
   the basic mental model. One swarm, several agents, output passed forward.
2. [Queued Workflow Events](queued-workflow-events/README.md): run the same kind
   of workflow in the background and react with events.
3. [Streaming Progress](streaming-progress/README.md): expose step/token
   progress from a request (see also [`docs/streaming.md`](../docs/streaming.md)).
4. [Streaming Broadcasts](streaming-broadcasts/README.md): broadcast typed stream
   events through Laravel broadcasting.
5. [Testing Swarms](testing-swarms/README.md): fake swarms for app tests and
   assert persisted runs when history matters.
6. [Run Inspector](run-inspector/README.md): build the status endpoint your UI
   uses after a queued, streamed, synchronous, or durable run starts.
7. [Parallel Research Swarm](parallel-research-swarm/README.md): run independent
   container-resolvable agents at the same time, including durable parallel
   branch execution.
8. [Hierarchical Support Triage](hierarchical-support-triage/README.md): let a
   coordinator return a validated route plan for specialist workers.
9. [Durable Compliance Review](durable-compliance-review/README.md): checkpoint
   a workflow one durable step per job.
10. [Durable Hierarchical Approval](durable-hierarchical-approval/README.md):
   combine coordinator routing with durable branch fan-out and join.
11. [Durable Waits And Signals](durable-waits-signals/README.md): pause durable
   work at an approval boundary and continue it with a signal.
12. [Durable Retries, Progress, And Child Swarms](durable-retries-progress-child-swarms/README.md):
   add retry policy, progress inspection, and child durable workflows.
13. [Durable Webhook Ingress](durable-webhook-ingress/README.md): expose
   authenticated start and signal routes for trusted integrations.
14. [Operations Dashboard](operations-dashboard/README.md): record lifecycle
   events, broadcast app-owned updates, and pair them with Pulse metrics.
15. [Privacy Capture](privacy-capture/README.md): configure capture flags for
   sensitive prompts and outputs.

## Topology Decision Table

| Need | Use | Key Contract |
| --- | --- | --- |
| Every agent should run in a fixed order | Sequential | Each agent receives the previous agent's output. |
| Independent agents should work at the same time | Parallel | Agents receive the original input and must be container-resolvable by class. |
| A coordinator should decide which specialists run | Hierarchical | The first agent returns a route plan; worker classes after it must be unique. |
| A long workflow should survive retries without replaying everything | Durable | Sequential, parallel, and hierarchical swarms; durable parallel branches use independent branch jobs and join before continuing. |
| A browser needs a status page after dispatch | Run inspector | Use `run_id` to compose history, context, artifacts, durable state, and pending records. |
| Operators need live run visibility | Operations dashboard | Store lifecycle event previews and broadcast your own application event. |

## Feature Coverage

| Feature | Start With |
| --- | --- |
| Sequential topology | [Sequential Content Pipeline](sequential-content-pipeline/README.md) |
| Parallel topology | [Parallel Research Swarm](parallel-research-swarm/README.md) |
| Hierarchical topology | [Hierarchical Support Triage](hierarchical-support-triage/README.md) |
| `prompt()` | [Sequential Content Pipeline](sequential-content-pipeline/README.md) |
| `queue()` | [Queued Workflow Events](queued-workflow-events/README.md) |
| `stream()` | [Streaming Progress](streaming-progress/README.md), [`docs/streaming.md`](../docs/streaming.md) |
| `broadcast()`, `broadcastNow()`, `broadcastOnQueue()` | [Streaming Broadcasts](streaming-broadcasts/README.md) |
| `dispatchDurable()` | [Durable Compliance Review](durable-compliance-review/README.md) |
| `#[Timeout]` | [Durable Waits And Signals](durable-waits-signals/README.md) |
| `#[MaxAgentSteps]` | [Durable Retries, Progress, And Child Swarms](durable-retries-progress-child-swarms/README.md) |
| Durable sequential execution | [Durable Compliance Review](durable-compliance-review/README.md) |
| Durable top-level parallel execution | [Parallel Research Swarm](parallel-research-swarm/README.md#durable-parallel-usage) |
| Durable hierarchical parallel execution | [Durable Hierarchical Approval](durable-hierarchical-approval/README.md) |
| `#[DurableWait]`, `DurableSwarmManager::signal()`, `swarm:signal` | [Durable Waits And Signals](durable-waits-signals/README.md) |
| `#[DurableLabels]`, `#[DurableDetails]` | [Durable Waits And Signals](durable-waits-signals/README.md) |
| `#[DurableRetry]` | [Durable Retries, Progress, And Child Swarms](durable-retries-progress-child-swarms/README.md) |
| `DurableSwarmManager::recordProgress()` | [Durable Retries, Progress, And Child Swarms](durable-retries-progress-child-swarms/README.md) |
| `DispatchesChildSwarms` | [Durable Retries, Progress, And Child Swarms](durable-retries-progress-child-swarms/README.md) |
| `SwarmWebhooks::routes()` | [Durable Webhook Ingress](durable-webhook-ingress/README.md) |
| Persistence and history | [Run Inspector](run-inspector/README.md) and [Persistence And History](../docs/persistence-and-history.md) |
| Artifacts and structured context | [Structured Input](../docs/structured-input.md) |
| Capture and privacy | [Privacy Capture](privacy-capture/README.md) |
| Pulse metrics | [Operations Dashboard](operations-dashboard/README.md) and [Pulse](../docs/pulse.md) |
| Testing | [Testing Swarms](testing-swarms/README.md) |
| Run inspection | [Run Inspector](run-inspector/README.md) |
| Operations, pruning, and recovery | [Durable Compliance Review](durable-compliance-review/README.md) and [Maintenance](../docs/maintenance.md) |

## Contracts To Keep

- Use plain-data task input only: strings, integers, floats, booleans, null,
  and arrays containing those values.
- Parallel swarm agents must be resolvable from Laravel's container by class.
- Hierarchical worker classes must be unique after the coordinator.
- Durable execution requires database persistence and supports sequential,
  parallel, and hierarchical topology.
- Durable parallel failure behavior can be configured globally or with
  `#[DurableParallelFailurePolicy]`.
- Prefer lifecycle events over queued `then()` / `catch()` callbacks.
- For queued or durable swarms, size queue `retry_after` and worker timeouts for
  your provider calls and total swarm duration.
