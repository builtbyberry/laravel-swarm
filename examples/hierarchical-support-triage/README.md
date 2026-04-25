# Hierarchical Support Triage

Shows a coordinator selecting specialist workers through a structured route
plan.

Use this pattern when routing is part of the workflow and not every worker
should always run.

This example teaches:

- the first agent is the coordinator;
- the coordinator returns structured output;
- Laravel Swarm validates the route plan before workers run;
- worker classes after the coordinator must be unique.

## Swarm

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
class SupportTriageSwarm implements Swarm
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

## Coordinator

The coordinator prompt should name the route-plan contract explicitly. Do not
just ask the model to "decide what to do."

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class SupportCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Return a Laravel Swarm route plan for the support request.

Allowed worker agents:
- App\Ai\Agents\BillingResponder
- App\Ai\Agents\PolicyResearcher
- App\Ai\Agents\ReplyEditor

Allowed node types:
- worker: runs one allowed worker agent.
- parallel: runs independent worker nodes, then joins at its next node.
- finish: ends the workflow with either literal output or output from a prior node.

Rules:
- start_at must reference a node id in nodes.
- worker.agent must be one of the allowed worker agent classes.
- parallel.branches may only reference worker nodes.
- parallel.next is required.
- branch workers must not define next.
- with_outputs may only reference nodes that have already completed.
- finish nodes must define exactly one of output or output_from.
INSTRUCTIONS;
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

## Why This Is Reliable

The coordinator does not directly call workers. It returns structured output.
Laravel Swarm normalizes and validates that route plan first. If the plan is
invalid, the swarm fails before specialist workers execute.

## Example Route Plan

This plan runs billing and policy research in parallel, then gives both outputs
to the editor.

```json
{
  "start_at": "lookup",
  "nodes": {
    "lookup": {
      "type": "parallel",
      "branches": ["billing", "policy"],
      "next": "edit"
    },
    "billing": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\BillingResponder",
      "prompt": "Answer the billing portion of this request."
    },
    "policy": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\PolicyResearcher",
      "prompt": "Find the policy that governs this request."
    },
    "edit": {
      "type": "worker",
      "agent": "App\\Ai\\Agents\\ReplyEditor",
      "prompt": "Write a final customer-safe reply.",
      "with_outputs": {
        "billing_notes": "billing",
        "policy_notes": "policy"
      },
      "next": "finish"
    },
    "finish": {
      "type": "finish",
      "output_from": "edit"
    }
  }
}
```

See `docs/hierarchical-routing.md` for the full route-plan contract.
