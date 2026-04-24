# Hierarchical Routing

Hierarchical swarms use a coordinator-owned routing plan.

The first agent returned by `agents()` is the coordinator. It must implement
Laravel AI structured output and return the full route plan. Laravel Swarm then
normalizes, validates, and executes that plan directly.

There is no `route()` callback anymore. The coordinator is the single source of
truth for what should run next.

## Mental Model

Think about a hierarchical swarm in four phases:

1. the coordinator reads the task
2. the coordinator returns a structured route plan
3. Laravel Swarm validates that plan as a DAG
4. worker nodes execute in the validated order

Use a hierarchical swarm when routing is part of the workflow itself. If every
agent should always run, sequential or parallel is usually simpler.

## Coordinator Schema

The coordinator must implement `HasStructuredOutput` and declare the top-level
route-plan shape:

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class TriageCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Plan the next workers for this support request.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }
}
```

The normalized payload contract is:

- `start_at`: the node id where execution begins
- `nodes`: an object keyed by node id

Node definitions use a `type` discriminator:

- `worker`
- `parallel`
- `finish`

## Node Types

### Worker Nodes

Worker nodes execute one of the swarm's non-coordinator agents.

```json
{
  "type": "worker",
  "agent": "App\\Ai\\Agents\\BillingResponder",
  "prompt": "Draft the billing response.",
  "with_outputs": {
    "classification": "classify_node"
  },
  "metadata": {
    "stage": "response"
  },
  "next": "finish_node"
}
```

Fields:

- `agent`: a worker agent class returned from `agents()`
- `prompt`: the literal base prompt for that worker
- `with_outputs`: optional alias-to-node-id map
- `metadata`: optional step metadata
- `next`: optional next node id

### Parallel Nodes

Parallel nodes reference worker-node ids and fan out execution.

```json
{
  "type": "parallel",
  "branches": ["billing_node", "policy_node"],
  "next": "draft_node"
}
```

Rules:

- `branches` may only reference worker nodes
- `next` is required in v1; every parallel group must join into a subsequent node before the workflow can finish
- worker nodes used as branches may not define their own `next`
- branch workers cannot depend on sibling branch outputs
- in `run()`, branches execute concurrently
- in `queue()`, branches execute sequentially in declaration order in v1

### Finish Nodes

Finish nodes stop execution and define the final swarm output.

Literal finish:

```json
{
  "type": "finish",
  "output": "No follow-up is needed."
}
```

Finish from a prior node:

```json
{
  "type": "finish",
  "output_from": "draft_node"
}
```

A finish node must define exactly one of `output` or `output_from`.

## Named Outputs

Downstream worker nodes can pull prior node outputs explicitly with
`with_outputs`.

Example plan shape:

```json
{
  "type": "worker",
  "agent": "App\\Ai\\Agents\\DraftAgent",
  "prompt": "Write the customer reply.",
  "with_outputs": {
    "classification": "classify_node",
    "policy_notes": "policy_node"
  }
}
```

Laravel Swarm does not do template interpolation. Instead, it appends a
deterministic `Named outputs:` block to the worker prompt:

```text
Write the customer reply.

Named outputs:
[classification]
{output from classify_node}

[policy_notes]
{output from policy_node}
```

This keeps routing explicit and avoids a mini template language in the plan.

`with_outputs` may only reference nodes that are guaranteed to have completed
before the worker runs. A worker after a parallel group may reference any branch
output from that completed group. A branch inside a parallel group may not
reference another branch from the same group, even in `queue()` where v1
executes those branches sequentially.

## Example Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\BillingResponder;
use App\Ai\Agents\PolicyResearcher;
use App\Ai\Agents\ReplyEditor;
use App\Ai\Agents\SupportCoordinator;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Hierarchical)]
class SupportRoutingSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new SupportCoordinator,
            new BillingResponder,
            new PolicyResearcher,
            new ReplyEditor,
        ];
    }
}
```

Example coordinator output:

```json
{
  "start_at": "parallel_lookup",
  "nodes": {
    "parallel_lookup": {
      "type": "parallel",
      "branches": ["billing_node", "policy_node"],
      "next": "editor_node"
    },
    "billing_node": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\BillingResponder",
      "prompt": "Answer the billing part of this request."
    },
    "policy_node": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\PolicyResearcher",
      "prompt": "Find the governing policy for this request."
    },
    "editor_node": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\ReplyEditor",
      "prompt": "Write the final reply.",
      "with_outputs": {
        "billing_notes": "billing_node",
        "policy_notes": "policy_node"
      },
      "next": "finish_node"
    },
    "finish_node": {
      "type": "finish",
      "output_from": "editor_node"
    }
  }
}
```

## Validation Rules

Laravel Swarm validates the route plan before any worker executes.

The plan must satisfy all of these:

- `start_at` must exist in `nodes`
- every referenced `next`, `branch`, and `output_from` node must exist
- every worker `agent` must belong to the swarm's worker set
- worker agent classes returned after the coordinator must be unique
- the coordinator cannot route to itself as a worker
- the graph must be acyclic
- unreachable nodes are rejected
- finish nodes may not define `next`
- parallel branches may only reference worker nodes
- parallel nodes must define `next` in v1
- worker nodes used as parallel branches may not define `next`
- named outputs may only reference previously completed nodes
- finish `output_from` may only reference a previously completed node

Loops are intentionally unsupported in this release.

## Execution Modes

### `run()`

- coordinator executes first
- worker chains execute normally
- parallel groups run concurrently
- finish nodes stop execution immediately

### `queue()`

- the same validated plan is used
- parallel groups execute sequentially in branch declaration order in v1
- branch metadata and history still record the plan as a parallel group so the
  runtime can evolve later without changing the plan contract
- the plan is still validated with the same parallel-safe dependency rules as
  `run()`

### `dispatchDurable()`

Durable execution remains sequential-only in this release. Hierarchical durable
execution is intentionally deferred.

## History And Metadata

Hierarchical runs persist graph-aware metadata such as:

- `coordinator_agent_class`
- `route_plan_start`
- `executed_node_ids`
- `executed_agent_classes`
- `parallel_groups`
- `execution_mode`

Each actual agent execution still becomes its own persisted step. Parallel
branches include:

- `node_id`
- `parent_parallel_node_id`
- any branch metadata from the plan

## Step Limits

`#[MaxAgentSteps]` counts the coordinator plus each reachable worker node.
Parallel and finish nodes are control nodes and do not count by themselves.

If a route plan requires more agent executions than the swarm allows, Laravel
Swarm fails before any worker node executes. Increase `#[MaxAgentSteps]` or
reduce the plan's worker nodes.

## Choosing Hierarchical Carefully

Hierarchical routing is the right fit when the planner's decision is part of
the business logic. If your real workflow is simply "run these agents in order"
or "run these agents all at once," sequential or parallel stays easier to
reason about.
