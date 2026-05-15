# Static Hierarchical Topology

The `static_hierarchical` topology runs a fixed route plan defined in PHP — no
coordinator LLM call is made at runtime. Use it when the graph of agents is
always the same and the only variable is the content of each agent's output.

The `ReadinessAnalysisSwarm` pattern is the canonical example: five parallel
layer agents always run, one synthesis agent always joins, and the result is
always the same shape. A dynamic coordinator would spend tokens and latency
just to produce the same plan every time.

## Mental Model

1. You define a route plan as a PHP array in `plan()`.
2. Laravel Swarm validates it as a DAG (same rules as hierarchical).
3. Worker nodes execute in the validated order — in parallel or sequentially.
4. No coordinator agent runs; the first `$nextIndex` is `0`.

## Implementing a Static Hierarchical Swarm

Your swarm class must:

1. Carry `#[Topology(Topology::StaticHierarchical)]`.
2. Implement `HasRoutePlan` and return a valid plan from `plan()`.
3. Return all worker agents from `agents()` — same as hierarchical.

Scaffold a new swarm with:

```bash
php artisan make:swarm MySwarm --topology=static-hierarchical
```

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\Editor;
use App\Ai\Agents\Researcher;
use App\Ai\Agents\Writer;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\HasRoutePlan;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::StaticHierarchical)]
class ContentSwarm implements Swarm, HasRoutePlan
{
    use Runnable;

    public function agents(): array
    {
        return [
            new Researcher,
            new Writer,
            new Editor,
        ];
    }

    public function plan(): array
    {
        return [
            'start_at' => 'gather',
            'nodes' => [
                'gather' => [
                    'type' => 'parallel',
                    'branches' => ['researcher_node', 'writer_node'],
                    'next' => 'editor_node',
                ],
                'researcher_node' => [
                    'type' => 'worker',
                    'agent' => Researcher::class,
                    'prompt' => 'Research the topic thoroughly.',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => Writer::class,
                    'prompt' => 'Draft an initial outline.',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => Editor::class,
                    'prompt' => 'Synthesize the research and draft into a final article.',
                    'with_outputs' => [
                        'research' => 'researcher_node',
                        'draft' => 'writer_node',
                    ],
                    'next' => 'finish',
                ],
                'finish' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ],
        ];
    }
}
```

## Plan Array Format

The plan must have a `start_at` key and a `nodes` map. Each node has a `type`
and type-specific keys.

### Worker node

```php
'node_id' => [
    'type'         => 'worker',
    'agent'        => AgentClass::class,   // must be returned from agents()
    'prompt'       => 'Task instruction',
    'next'         => 'next_node_id',      // optional; omit if last before finish
    'with_outputs' => [                    // optional; inject prior outputs
        'alias' => 'source_node_id',
    ],
    'metadata'     => [...],              // optional; merged into step metadata
],
```

When `with_outputs` is present the prompt is extended:

```
Task instruction

Named outputs:
[alias]
<output of source_node_id>
```

### Parallel node

```php
'node_id' => [
    'type'     => 'parallel',
    'branches' => ['branch_a', 'branch_b'],  // worker node IDs
    'next'     => 'join_node_id',            // required in v1
],
```

Parallel branches may not depend on each other via `with_outputs`. They may
depend on nodes that executed before the parallel group.

### Finish node

```php
'finish' => [
    'type'        => 'finish',
    'output_from' => 'worker_node_id',   // use the output of this worker
    // OR
    'output'      => 'Literal string',   // return a fixed string
],
```

Exactly one of `output_from` or `output` must be present.

## Execution Modes

| Mode | Supported |
| --- | --- |
| `prompt()` / `run()` | Yes |
| `queue()` | Yes — in-process execution (`in_process` mode) |
| `stream()` | Yes — see below |
| `broadcast()` / `broadcastNow()` | Yes — wraps `stream()` identically to sequential |
| `broadcastOnQueue()` | Yes — dispatches a `BroadcastSwarm` job |
| `dispatchDurable()` | **Not supported in v1** |

## Streaming

Call `stream()` exactly as you would for a sequential swarm. Sequential worker
nodes always stream live text deltas. Parallel groups have two modes:

### `concurrent` (default)

Branches run via `ConcurrencyManager` (no live text from individual branches).
Each branch emits a `SwarmStepEnd` event when the group finishes. The
sequential node after the join streams normally.

```
swarm_stream_start
swarm_step_end      ← branch 1
swarm_step_end      ← branch 2
swarm_step_start    ← synthesis node
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_stream_end
```

### `sequential`

Branches stream one at a time in declaration order. Apply
`#[StreamParallelBranches('sequential')]` to the swarm class to opt in.

```php
use BuiltByBerry\LaravelSwarm\Attributes\StreamParallelBranches;

#[Topology(TopologyEnum::StaticHierarchical)]
#[StreamParallelBranches('sequential')]
class ContentSwarm implements Swarm, HasRoutePlan { ... }
```

```
swarm_stream_start
swarm_step_start    ← branch 1
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← branch 2
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_step_start    ← synthesis node
swarm_text_delta
swarm_text_end
swarm_step_end
swarm_stream_end
```

### Config fallback

When no attribute is present the mode is read from config:

```php
// config/swarm.php
'static_hierarchical' => [
    'stream_parallel_branches' => env('SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES', 'concurrent'),
],
```

Valid values: `concurrent` | `sequential`.

## Step Limits and `MaxAgentSteps`

`#[MaxAgentSteps]` counts **worker nodes only** — there is no coordinator step.
A plan with 5 parallel branches + 1 synthesis node requires 6 executions.
Parallel and finish nodes are control nodes and do not count by themselves.

```php
#[MaxAgentSteps(6)]
#[Topology(TopologyEnum::StaticHierarchical)]
class ReadinessAnalysisSwarm implements Swarm, HasRoutePlan { ... }
```

If the plan requires more executions than the budget allows, Laravel Swarm
throws before any agent runs.

## Metadata Shape

The `SwarmResponse` and `SwarmCompleted` metadata include:

| Key | Value |
| --- | --- |
| `topology` | `'static_hierarchical'` |
| `route_plan_start` | First node ID |
| `executed_node_ids` | All traversed node IDs |
| `executed_agent_classes` | All executed agent classes |
| `parallel_groups` | `[{node_id, branches}]` per parallel group |
| `executed_steps` | Count of worker steps |
| `execution_mode` | `'run'` / `'queue'` / `'stream'` |

`coordinator_agent_class` is intentionally absent.

## Choosing StaticHierarchical vs Hierarchical

Use `StaticHierarchical` when:

- The agent graph is always the same regardless of input.
- You want to eliminate the coordinator token cost and round-trip latency.
- You want streaming support with full control over parallel branch behaviour.

Use `Hierarchical` when:

- The set of workers to run, or their order, depends on the task input.
- A coordinator must read the request and decide the routing at runtime.

## Implementation Notes

### Why `StaticHierarchicalStreamRunner` extends `SequentialStreamRunner`

The stream runner inherits from `SequentialStreamRunner` rather than
`StaticHierarchicalRunner` to reuse the sequential streaming infrastructure
(`normalizeCompletionResponse()`, telemetry-recording helpers, and
`ConcurrencyManager` wiring). The sync execution path (`run()`) lives on
`StaticHierarchicalRunner`; the stream path is an independent specialisation.

### `$nextIndex` starts at 0

Hierarchical runs consume step index 0 for the coordinator agent. Static
hierarchical has no coordinator call, so `$nextIndex` starts at `0` and the
first worker node takes index 0. `MaxAgentSteps` enforcement counts worker
nodes only — there is no coordinator step to add.

### `dispatchDurable()` guard ordering

The `StaticHierarchical` durable guard fires before
`ensureDatabaseDurableInfrastructure()`. This means the
topology-not-supported error is returned immediately without a database check,
matching the behaviour of other topology-specific durable guards.

### Tradeoffs

| Chosen | Rejected | Rationale |
| --- | --- | --- |
| `broadcast()` / `broadcastNow()` / `broadcastOnQueue()` route through the same `StaticHierarchicalStreamRunner` as `stream()` | Separate broadcast runner | No divergence in event shape; broadcast wraps the stream generator identically to sequential |
| `ensureStreamableTopology()` accepts an allowlist of topologies | Per-topology boolean methods | Single guard point; adding a new streamable topology requires one line change |
| `make:swarm --topology` string option | `--static-hierarchical` boolean flag | Extensible to future topologies (`parallel`, `hierarchical`) without adding new flags |
