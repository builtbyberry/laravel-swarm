# Hierarchical Routing

Hierarchical swarms use a coordinator / worker model.

The first agent acts as the coordinator. It looks at the task, decides what should happen next, and routes work to the remaining agents.

This is useful when one agent should plan and other agents should execute.

## The Mental Model

Think about a hierarchical swarm in two stages:

1. the coordinator decides what work should happen
2. the workers carry out that work

That is different from a sequential swarm, where every agent always runs in order, and from a parallel swarm, where every agent always receives the original task.

## When To Use A Hierarchical Swarm

Choose a hierarchical swarm when:

- one agent should inspect or classify the task first
- only some downstream agents should run
- routing decisions are part of the workflow itself

If every agent should always run, sequential or parallel is usually a better fit.

## A Real Example

A support triage swarm is a natural fit for hierarchical routing. The coordinator reads the incoming request and decides whether it needs a billing specialist, a technical specialist, or a general response. Not every request needs every agent.

The key design decision is making the coordinator use structured output rather than returning prose. Routing on free-form text is fragile — a coordinator that says "This looks like a billing issue with some technical aspects" is hard to parse reliably. Use `HasStructuredOutput` to enforce a predictable response shape instead:

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class TriageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a support request classifier. Read the incoming 
                request and classify it as billing, technical, or general.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->enum(['billing', 'technical', 'general'])
                ->required(),
        ];
    }
}
```

The schema enforces that the coordinator always returns a `category` field with one of the three allowed values. No prompt engineering required to make the output parseable.

With a structured coordinator in place, the swarm and its routing logic stay clean:

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\BillingAgent;
use App\Ai\Agents\GeneralResponseAgent;
use App\Ai\Agents\TechnicalAgent;
use App\Ai\Agents\TriageAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

#[Topology(TopologyEnum::Hierarchical)]
class SupportTriageSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new TriageAgent,
            new BillingAgent,
            new TechnicalAgent,
            new GeneralResponseAgent,
        ];
    }

    public function route(string $coordinatorOutput, array $agents, RunContext $context): array
    {
        $result = json_decode($coordinatorOutput, true);

        $agentClass = match ($result['category']) {
            'billing'   => BillingAgent::class,
            'technical' => TechnicalAgent::class,
            default     => GeneralResponseAgent::class,
        };

        return [
            [
                'agent_class' => $agentClass,
                'input'       => $context->input,
            ],
        ];
    }
}
```

The coordinator returns a structured category. The `route()` method reads that output and sends work to exactly one specialist. `BillingAgent` and `TechnicalAgent` never run for a general request.

## Structured Output In Coordinators

The coordinator output passed to `route()` is a plain string — the serialized response from the coordinator agent. When your coordinator implements `HasStructuredOutput`, that string will be a JSON-encoded object matching your schema.

Use `json_decode` to work with it in `route()`:

```php
$result = json_decode($coordinatorOutput, true);
```

The `HasStructuredOutput` contract guarantees the shape matches your schema, so you can rely on the fields being present without defensive fallbacks.

If your coordinator does not use structured output, `route()` will receive whatever prose string the agent returned. Routing reliably on prose is difficult. Structured output is the recommended approach for any coordinator whose output drives branching logic.

## Defining Routes

`route()` returns an array of worker instructions. Each instruction can contain:

- `agent` or `agent_class`
- `input`
- optional `metadata`

Return multiple instructions to route to more than one worker sequentially:

```php
return [
    [
        'agent_class' => ResearchAgent::class,
        'input'       => 'Research this topic: ' . $context->input,
        'metadata'    => ['stage' => 'research'],
    ],
    [
        'agent_class' => WriterAgent::class,
        'input'       => 'Write a draft based on the research.',
        'metadata'    => ['stage' => 'draft'],
    ],
];
```

## Routing Behavior

Laravel Swarm applies these rules:

- the first agent returned by `agents()` is the coordinator
- workers are selected from the remaining agents
- routed workers execute sequentially in the order returned by `route()`
- an empty route completes successfully with the coordinator output
- a missing `route()` method fails fast for hierarchical swarms
- unknown routed classes throw an explicit exception naming the missing class

## Choosing Hierarchical Carefully

Hierarchical routing adds more workflow semantics than sequential or parallel execution.

Use it when the routing decision is part of the business logic — not simply when the workflow has multiple steps. If every agent should always run, sequential or parallel is a simpler and more predictable choice.
