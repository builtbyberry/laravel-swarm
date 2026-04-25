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
5. [Parallel Research Swarm](parallel-research-swarm/README.md): run independent
   container-resolvable agents at the same time.
6. [Hierarchical Support Triage](hierarchical-support-triage/README.md): let a
   coordinator return a validated route plan for specialist workers.
7. [Durable Compliance Review](durable-compliance-review/README.md): checkpoint
   a sequential workflow one agent step per job.
8. [Privacy Capture](privacy-capture/README.md): configure capture flags for
   sensitive prompts and outputs.

## Topology Decision Table

| Need | Use | Key Contract |
| --- | --- | --- |
| Every agent should run in a fixed order | Sequential | Each agent receives the previous agent's output. |
| Independent agents should work at the same time | Parallel | Agents receive the original input and must be container-resolvable by class. |
| A coordinator should decide which specialists run | Hierarchical | The first agent returns a route plan; worker classes after it must be unique. |
| A long workflow should survive retries without replaying everything | Durable | Sequential only; one agent step is checkpointed per queued job. |

## Contracts To Keep

- Use plain-data task input only: strings, integers, floats, booleans, null,
  and arrays containing those values.
- Parallel swarm agents must be resolvable from Laravel's container by class.
- Hierarchical worker classes must be unique after the coordinator.
- Durable execution requires database persistence and sequential topology.
- Prefer lifecycle events over queued `then()` / `catch()` callbacks.
