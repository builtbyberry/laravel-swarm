# Laravel Swarm Examples

These examples are copy-paste starting points for Laravel applications. They
are not autoloaded by the package and are not a runnable demo app.

Examples assume your app has Laravel AI configured. When an example does not
show `#[Provider]` and `#[Model]`, the agent uses your Laravel AI defaults. The
first sequential example shows the explicit provider/model attribute pattern.

## Start Here

Read the examples in this order:

1. [Sequential Content Pipeline](sequential-content-pipeline/README.md): build
   the basic mental model. One swarm, several agents, output passed forward.
2. [Queued Workflow Events](queued-workflow-events/README.md): run the same kind
   of workflow in the background and react with events.
3. [Streaming Progress](streaming-progress/README.md): expose step/token
   progress from a request.
4. [Testing Swarms](testing-swarms/README.md): fake swarms for app tests and
   assert persisted runs when history matters.
5. [Run Inspector](run-inspector/README.md): build the status endpoint your UI
   uses after a queued, streamed, synchronous, or durable run starts.
6. [Parallel Research Swarm](parallel-research-swarm/README.md): run independent
   container-resolvable agents at the same time.
7. [Hierarchical Support Triage](hierarchical-support-triage/README.md): let a
   coordinator return a validated route plan for specialist workers.
8. [Durable Compliance Review](durable-compliance-review/README.md): checkpoint
   a workflow one durable step per job.
9. [Operations Dashboard](operations-dashboard/README.md): record lifecycle
   events, broadcast app-owned updates, and pair them with Pulse metrics.
10. [Privacy Capture](privacy-capture/README.md): configure capture flags for
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

## Contracts To Keep

- Use plain-data task input only: strings, integers, floats, booleans, null,
  and arrays containing those values.
- Parallel swarm agents must be resolvable from Laravel's container by class.
- Hierarchical worker classes must be unique after the coordinator.
- Durable execution requires database persistence and supports sequential,
  parallel, and hierarchical topology.
- Prefer lifecycle events over queued `then()` / `catch()` callbacks.
- For queued or durable swarms, size queue `retry_after` and worker timeouts for
  your provider calls and total swarm duration.
