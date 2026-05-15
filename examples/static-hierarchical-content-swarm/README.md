# Static Hierarchical Content Swarm

Shows a content production pipeline — Researcher, Writer, Editor — running
through the `StaticHierarchical` topology.

Use this pattern when the execution graph is fixed at development time and you
want deterministic, token-efficient orchestration without a coordinator LLM
call.

---

## When to Use StaticHierarchical

**Use `StaticHierarchical` when:**

- The set of agents and their order never changes regardless of input.
- You want to eliminate the coordinator round-trip (one fewer LLM call per run,
  lower latency, lower token cost).
- You need a predictable pipeline — no runtime surprises about which agents ran
  or in what order.
- You want streaming with full control over parallel branch behavior.

**Use `Hierarchical` when:**

- The coordinator must read the request and decide at runtime which agents to
  call or in what order.
- Routing logic depends on the content of the task, not just its structure.

The rule of thumb: if you could write the route plan in PHP today and it would
be correct for every request, use `StaticHierarchical`. If you cannot, use
`Hierarchical`.

---

## The Linear Pipeline

The simplest form is a linear chain: one agent feeds the next. Researcher
gathers information, Writer drafts the article, Editor polishes the final copy.

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\EditorAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\WriterAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class ContentProductionSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ResearchAgent,
            new WriterAgent,
            new EditorAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'research',
            'nodes' => [
                'research' => [
                    'type'   => 'worker',
                    'agent'  => ResearchAgent::class,
                    'prompt' => 'Research the topic thoroughly. Return factual notes, key statistics, and credible sources.',
                    'next'   => 'write',
                ],
                'write' => [
                    'type'         => 'worker',
                    'agent'        => WriterAgent::class,
                    'prompt'       => 'Write a clear, engaging article draft based on the research below.',
                    'with_outputs' => [
                        'research' => 'research',
                    ],
                    'next' => 'edit',
                ],
                'edit' => [
                    'type'         => 'worker',
                    'agent'        => EditorAgent::class,
                    'prompt'       => 'Edit the draft for clarity, accuracy, and tone. Return the final polished article.',
                    'with_outputs' => [
                        'draft' => 'write',
                    ],
                    'next' => 'finish',
                ],
                'finish' => [
                    'type'        => 'finish',
                    'output_from' => 'edit',
                ],
            ],
        ];
    }
}
```

### How the plan works

- `start_at` names the first node to execute.
- Each `worker` node runs one agent. `with_outputs` injects the text output of
  earlier nodes into the current agent's prompt under a named label.
- The `finish` node signals the end of the run and designates which worker's
  output becomes the `SwarmResponse` content.
- No coordinator LLM call happens — Laravel Swarm reads the plan and executes
  it directly.

---

## The Parallel Variant

When independent work can be done at the same time, declare a `parallel` node.
Here a `FactCheckerAgent` runs alongside the researcher so that both complete
before the writer starts.

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\EditorAgent;
use App\Ai\Agents\FactCheckerAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\WriterAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class ContentWithFactCheckSwarm implements HasRoutePlan, Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ResearchAgent,
            new FactCheckerAgent,
            new WriterAgent,
            new EditorAgent,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'gather',
            'nodes' => [
                'gather' => [
                    'type'     => 'parallel',
                    'branches' => ['research', 'fact_check'],
                    'next'     => 'write',
                ],
                'research' => [
                    'type'   => 'worker',
                    'agent'  => ResearchAgent::class,
                    'prompt' => 'Research the topic thoroughly. Return factual notes, key statistics, and credible sources.',
                ],
                'fact_check' => [
                    'type'   => 'worker',
                    'agent'  => FactCheckerAgent::class,
                    'prompt' => 'Identify common misconceptions and known false claims about this topic.',
                ],
                'write' => [
                    'type'         => 'worker',
                    'agent'        => WriterAgent::class,
                    'prompt'       => 'Write a clear, engaging article draft. Use the research notes and avoid the identified misconceptions.',
                    'with_outputs' => [
                        'research'    => 'research',
                        'fact_checks' => 'fact_check',
                    ],
                    'next' => 'edit',
                ],
                'edit' => [
                    'type'         => 'worker',
                    'agent'        => EditorAgent::class,
                    'prompt'       => 'Edit the draft for clarity, accuracy, and tone. Return the final polished article.',
                    'with_outputs' => [
                        'draft' => 'write',
                    ],
                    'next' => 'finish',
                ],
                'finish' => [
                    'type'        => 'finish',
                    'output_from' => 'edit',
                ],
            ],
        ];
    }
}
```

The `parallel` node names its `branches` (worker node IDs) and a `next` node
that joins after all branches complete. Branches may not reference each other
via `with_outputs` — they run independently — but they may inject the output of
any node that ran before the parallel group.

---

## Running the Swarm

### Synchronous

```php
use App\Ai\Swarms\ContentProductionSwarm;

$response = ContentProductionSwarm::make()
    ->prompt('Write an article about the rise of quantum computing');

echo $response->content; // final edited article
```

### Background queue

```php
ContentProductionSwarm::make()
    ->queue('Write an article about the rise of quantum computing');
```

The job runs in your default queue using in-process execution. Listen for the
`SwarmCompleted` event to receive the result.

---

## Reading Results

The `SwarmResponse` gives you the final output and every intermediate step.

```php
$response = ContentProductionSwarm::make()
    ->prompt('Write an article about the rise of quantum computing');

// Final article from the editor
echo $response->content;

// Inspect each step
foreach ($response->steps as $step) {
    echo sprintf(
        "[%s] %s\n",
        $step->agentClass,
        substr($step->content, 0, 120),
    );
}

// Topology metadata
$meta = $response->metadata;
// $meta['topology']              => 'static_hierarchical'
// $meta['executed_node_ids']     => ['research', 'write', 'edit', 'finish']
// $meta['executed_agent_classes'] => [ResearchAgent::class, WriterAgent::class, EditorAgent::class]
// $meta['executed_steps']        => 3
// $meta['execution_mode']        => 'run'
```

To find a specific step by agent:

```php
$researchStep = collect($response->steps)
    ->first(fn ($step) => $step->agentClass === ResearchAgent::class);

echo $researchStep->content;
```

---

## Streaming Parallel Branches

When you call `stream()` on a swarm with parallel nodes, the default behavior
runs branches concurrently via `ConcurrencyManager`. Individual branch text
does not stream live — each branch emits a `SwarmStepEnd` event when the group
completes, then the sequential node after the join streams normally.

```
swarm_stream_start
swarm_step_end      ← research branch
swarm_step_end      ← fact_check branch
swarm_step_start    ← write node (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← edit node (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_stream_end
```

### Sequential branch streaming

Apply `#[StreamParallelBranches('sequential')]` to stream branches one at a
time in declaration order. This trades parallelism for live text from each
branch, and is useful when you are at a rate limit or want the UI to show
progress through each branch individually.

```php
use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;

#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('sequential')]
class ContentWithFactCheckSwarm implements HasRoutePlan, Swarm
{
    // ...
}
```

The event sequence becomes:

```
swarm_stream_start
swarm_step_start    ← research branch (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← fact_check branch (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← write node (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← edit node (streams live)
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_stream_end
```

When no attribute is present, the mode falls back to `config/swarm.php`:

```php
'static_hierarchical' => [
    'stream_parallel_branches' => env('SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES', 'concurrent'),
],
```

Valid values: `concurrent` | `sequential`.

---

## Key Takeaways

- **No coordinator LLM call.** The `plan()` method handles all routing in PHP.
  Every token goes to actual work, not orchestration decisions.
- **Deterministic execution.** The graph is fixed — the same agents run in the
  same order on every request. No surprises in production logs.
- **Full compatibility with all non-streaming execution modes.** `prompt()`,
  `run()`, `queue()`, `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()`
  all work out of the box. (`dispatchDurable()` is not supported in v1.)

---

## Next Steps

- [Static Hierarchical Topology reference](../../docs/static-hierarchical-topology.md) — plan format, step limits, metadata shape
- [Execution Modes](../../docs/execution-modes.md) — all run modes and when to use each
- [Sequential topology](../../docs/sequential.md) — comparison with a simpler linear pipeline
- [Parallel Research Swarm example](../parallel-research-swarm/) — independent agents with no step dependencies
