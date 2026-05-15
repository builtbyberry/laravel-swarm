# Sequential Topology

Sequential is the default topology and the right starting point for most multi-agent workflows. Use it when each step in your AI pipeline depends on the output of the previous one — like a production line where each station transforms the work before passing it on. If you need to write a blog post that requires research, drafting, and editing, each of those steps builds directly on what came before, and Sequential is exactly the right model.

## Mental Model

Picture a content pipeline. Agent 1 — a researcher — receives your original task and returns a set of findings. Agent 2 — a writer — receives those findings and produces a draft. Agent 3 — an editor — receives the draft and returns the polished final version.

Each agent receives the **full text output** of the agent before it as its prompt. No agent sees the original task directly unless it is the first agent. The final agent's output becomes the `output` on the returned `SwarmResponse`.

## Declaration

Scaffold a new swarm with:

```bash
php artisan make:swarm ContentPipelineSwarm
```

The generated class uses `#[Topology(TopologyEnum::Sequential)]` by default:

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\EditorAgent;
use App\Ai\Agents\ResearchAgent;
use App\Ai\Agents\WriterAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ContentPipelineSwarm implements Swarm
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
}
```

The `agents()` method returns an ordered array of Laravel AI `Agent` instances. The order is the execution order — first to last, no exceptions.

Each agent is a standard Laravel AI agent:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ResearchAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Research the given topic thoroughly. Return key facts, data points, and relevant context.';
    }
}
```

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class WriterAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Write a clear, engaging draft article based on the research provided.';
    }
}
```

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class EditorAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Edit the draft for clarity, correctness, and tone. Return the final polished version.';
    }
}
```

## Input Flow

When you call `prompt()` (or any execution mode), here is what happens step by step:

1. Your task — a string, array, or `RunContext` — becomes the prompt for the first agent (`ResearchAgent`).
2. `ResearchAgent` runs and returns a text response. That text becomes the prompt for `WriterAgent`.
3. `WriterAgent` runs and returns its response. That text becomes the prompt for `EditorAgent`.
4. `EditorAgent` runs and returns the final text.
5. Laravel Swarm collects all three steps and returns a `SwarmResponse`.

Array and structured tasks are serialized to text before being passed to the first agent. If you pass `['topic' => 'Laravel queues', 'audience' => 'developers']`, the first agent receives a text representation of that payload.

From step 2 onward, each agent receives the previous agent's raw text output — not the original task. Design your agent instructions to work with whatever the previous agent is expected to produce.

## SwarmResponse

`prompt()` and `run()` return a `SwarmResponse`:

```php
$response = ContentPipelineSwarm::make()->prompt([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
]);

// The final agent's output
$response->output;

// Cast directly to string
(string) $response;

// All steps, one per agent
$response->steps; // array<int, SwarmStep>

// Accumulated usage across all agents
$response->usage;

// The RunContext carrying input, data, and metadata
$response->context;
```

Each `SwarmStep` records the agent class, the input it received, and the output it produced:

```php
foreach ($response->steps as $step) {
    $step->agentClass; // e.g. App\Ai\Agents\ResearchAgent
    $step->input;      // what this agent received
    $step->output;     // what this agent returned
}
```

For a three-agent pipeline, `$response->steps` has three entries. `$response->output` is equal to the `output` of the last step.

## Execution Modes

The same `ContentPipelineSwarm` class can be invoked in five different ways depending on where and how you need the result.

### `prompt()` — synchronous

Runs the full pipeline in the current PHP process and returns a `SwarmResponse` when all agents have finished.

```php
$response = ContentPipelineSwarm::make()->prompt([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
]);

return response()->json(['article' => $response->output]);
```

Use this when the caller can wait for the result and the total pipeline latency fits within your HTTP timeout.

### `run()` — alias for `prompt()`

`run()` is retained as a compatibility alias and behaves identically to `prompt()`.

```php
$response = ContentPipelineSwarm::make()->run('Write a guide on Laravel queues.');
```

### `queue()` — background dispatch

Dispatches the pipeline as a Laravel queue job. The call returns immediately with a `QueuedSwarmResponse` carrying the run ID; no `SwarmResponse` is returned at dispatch time.

```php
$queued = ContentPipelineSwarm::make()->queue([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
]);

// $queued->runId — use this to look up the result later
```

Queue the task when the pipeline is too slow for a synchronous HTTP request, or when you want to run it from a scheduled command. Listen to `SwarmCompleted` and `SwarmFailed` events to react when the job finishes.

### `stream()` — real-time token streaming

Returns a lazy `StreamableSwarmResponse` that yields typed stream events as each agent runs. Use this to push live progress to a browser or SSE client.

```php
return ContentPipelineSwarm::make()->stream([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
]);
```

Or iterate the events manually:

```php
foreach (ContentPipelineSwarm::make()->stream(['topic' => 'Laravel queues']) as $event) {
    if ($event->type() === 'swarm_text_delta') {
        echo $event->delta;
    }
}
```

Streaming is supported for **sequential swarms only**. See [Streaming](streaming.md) for the full event type reference, SSE integration, and persisted replay.

### `dispatchDurable()` — durable background execution

Dispatches the pipeline with database-backed checkpointing. Each agent step is persisted so the run can survive worker restarts and be inspected or recovered at any point.

```php
$durable = ContentPipelineSwarm::make()->dispatchDurable([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
]);

// $durable->runId — track and inspect the run
```

Use `dispatchDurable()` when pipeline failures need recovery guarantees or when you need an auditable record of every step. See [Durable Execution](durable-execution.md) for setup, recovery, and lifecycle details.

## Performance Characteristics

Sequential execution is intentionally serial. Total wall-clock time is approximately the **sum** of each agent's individual latency:

```
total ≈ ResearchAgent latency + WriterAgent latency + EditorAgent latency
```

If your agents are independent — if each one only needs the original task, not the previous agent's output — consider the [Parallel topology](parallel.md) instead. Parallel fans out all agents simultaneously and the total latency is closer to the slowest single agent.

Sequential makes sense when each transformation is genuinely dependent on the one before it. If you are thinking of routing or conditional branching at runtime, consider the [Hierarchical topology](hierarchical-routing.md).

**Timeouts are best-effort.** The `#[Timeout]` deadline is checked before the first agent runs and **between** each agent step. It does not interrupt a provider call that is already in progress. If an agent is generating a long response, the timeout fires at the next step boundary, not mid-generation.

## Attributes

### `#[Timeout]`

Sets an orchestration deadline in seconds. Checked before and between steps.

```php
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;

#[Topology(TopologyEnum::Sequential)]
#[Timeout(seconds: 60)]
class ContentPipelineSwarm implements Swarm
{
    // ...
}
```

The value must be a positive integer. If the deadline is exceeded between steps, the swarm fails with a timeout exception rather than starting the next agent.

### `#[MaxAgentSteps]`

Caps the total number of agent executions allowed in a single run. Useful as a safety guard when the agent list might change or grow.

```php
use BuiltByBerry\LaravelSwarm\Attributes\MaxAgentSteps;

#[Topology(TopologyEnum::Sequential)]
#[MaxAgentSteps(5)]
class ContentPipelineSwarm implements Swarm
{
    // ...
}
```

The limit is checked before each agent step. If adding the next step would exceed the cap, the swarm fails before that agent runs.

Both attributes can be used together:

```php
#[Topology(TopologyEnum::Sequential)]
#[Timeout(seconds: 120)]
#[MaxAgentSteps(10)]
class ContentPipelineSwarm implements Swarm
{
    // ...
}
```

## When Sequential Is the Right Choice

- Each agent's output is the direct input for the next agent.
- Steps are logically ordered and each step refines or transforms the previous result.
- You need a guaranteed execution order with no branching.
- You want the simplest mental model — agents run one after another, full stop.
- You need `stream()` support (streaming is sequential-only).

## When To Consider Another Topology

**Parallel** — when your agents are independent and each one needs the same original task rather than a transformed version. A research swarm that has three specialists all analyzing the same document can fan out in parallel and rejoin. See [Parallel](parallel.md).

**Hierarchical** — when you need a coordinator LLM to decide at runtime which agents to call and in what order. A support triage swarm that routes to different specialists based on content is a good candidate. See [Hierarchical Routing](hierarchical-routing.md).

**Static Hierarchical** — when you want a fixed graph of sequential and parallel nodes defined in PHP, without spending tokens on a coordinator LLM call at runtime. The graph is always the same; only the content varies. See [Static Hierarchical Topology](static-hierarchical-topology.md).

## Testing

Use `SwarmFake` to intercept execution in tests. The fake records dispatch intent and returns controlled responses without running any agents.

```php
use App\Ai\Swarms\ContentPipelineSwarm;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;

it('queues the content pipeline for the given topic', function () {
    ContentPipelineSwarm::fake();

    ContentPipelineSwarm::make()->queue([
        'topic' => 'Laravel queue visibility timeouts',
        'audience' => 'intermediate Laravel developers',
    ]);

    ContentPipelineSwarm::assertQueued(['topic' => 'Laravel queue visibility timeouts']);
});
```

Supply canned responses when you need to assert on the output:

```php
it('returns the final edited article', function () {
    ContentPipelineSwarm::fake([
        'Research findings about Laravel queues...',
        'Draft article about Laravel queues...',
        'Polished final article about Laravel queue visibility timeouts.',
    ]);

    $response = ContentPipelineSwarm::make()->prompt([
        'topic' => 'Laravel queue visibility timeouts',
    ]);

    expect((string) $response)->toBe('Polished final article about Laravel queue visibility timeouts.');

    ContentPipelineSwarm::assertPrompted(['topic' => 'Laravel queue visibility timeouts']);
});
```

Use a callable assertion when you need more control over what you check:

```php
it('prompts the pipeline with the correct topic', function () {
    ContentPipelineSwarm::fake();

    ContentPipelineSwarm::make()->prompt([
        'topic' => 'Laravel queue visibility timeouts',
        'audience' => 'intermediate Laravel developers',
    ]);

    ContentPipelineSwarm::assertPrompted(function ($task) {
        return is_array($task) && str_contains($task['topic'] ?? '', 'Laravel queue');
    });
});
```

Assert the swarm was never invoked when testing a code path that should skip the pipeline:

```php
it('does not run the pipeline when the topic is empty', function () {
    ContentPipelineSwarm::fake();

    // ...code under test that skips the swarm...

    ContentPipelineSwarm::assertNeverQueued();
});
```

For full testing API details — including persisted run assertions, lifecycle event assertions, and streaming fakes — see [Testing](testing.md).

## Related

- [Sequential Content Pipeline example](../examples/sequential-content-pipeline/README.md)
- [Parallel topology](parallel.md)
- [Execution modes](execution-modes.md)
- [Testing](testing.md)
- [Streaming](streaming.md)
