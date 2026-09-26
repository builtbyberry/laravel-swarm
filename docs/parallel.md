# Parallel Topology

Use the Parallel topology when every agent should process the same original task independently and you want all results collected. Unlike a sequential (chain) swarm where each agent's output feeds the next, Parallel is fan-out: the same input goes to every agent at the same time, and Laravel Swarm waits for all of them to finish before returning a combined result. No agent sees what the others produced.

## Mental Model

Think of a research swarm where three specialists independently analyze the same one-page brief: a market analyst, a technical analyst, and a risk analyst. Each receives the same brief. Each runs simultaneously in its own process. When all three finish, their outputs are collected and returned together. No analyst waits on or reads the work of the others.

That is exactly what `TopologyEnum::Parallel` does. The `agents()` array defines the specialists; the task is the brief; and `prompt()` is the button you press to fan it out.

## Declaration

Generate a parallel swarm with:

```bash
php artisan make:swarm:swarm ResearchSwarm --topology=parallel
```

The generated class uses the `#[Topology(TopologyEnum::Parallel)]` attribute and the `Runnable` trait:

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\MarketAnalystAgent;
use App\Ai\Agents\RiskAnalystAgent;
use App\Ai\Agents\TechnicalAnalystAgent;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
class ResearchSwarm implements Swarm
{
    use Runnable;

    /**
     * Parallel agents run concurrently on the original task.
     * Each agent must be stateless and resolvable from the container.
     */
    public function agents(): array
    {
        return [
            new MarketAnalystAgent,
            new TechnicalAnalystAgent,
            new RiskAnalystAgent,
        ];
    }
}
```

## The Stateless + Container-Resolvable Constraint

This is the most important thing to understand about Parallel swarms before you write one.

**Why it exists:** Laravel Swarm runs parallel agents through Laravel's `ConcurrencyManager`, which dispatches work to separate PHP worker processes. The only information a worker receives is a serialized closure. PHP's serializer cannot capture arbitrary runtime state — objects instantiated outside the closure, references to service instances, or class properties that hold database connections, HTTP clients, or closures will either serialize incorrectly or fail to unserialize in the worker process.

Laravel Swarm's `ParallelRunner` re-resolves an authored swarm inside each worker
and selects the same stable agent slot. If the parent selected a different agent
class for that slot, that class is resolved directly from the container. Ad-hoc
parallel builders always resolve each agent class directly. This means:

1. Each agent **must be resolvable by class name** from the service container in the worker process.
2. Runtime state must be declared by the authored swarm or through
   `RunContext::withAgentConfiguration()`. Ad-hoc instance mutations are not a
   transport and are rejected while native-settings admission is enabled.
3. Constructor dependencies **must be bindable through the container** (interfaces need normal `AppServiceProvider` bindings; concrete classes work by default).

**What does not work:**

```php
// BAD: runtime-constructed dependency captured in a property
public function agents(): array
{
    $client = new SomeApiClient(config('services.someapi.key')); // resolved at swarm construction time

    $agent = new MarketAnalystAgent;
    $agent->client = $client; // this property will be silently dropped in the worker

    return [$agent];
}

// BAD: agent that accepts constructor arguments the container cannot satisfy
public function agents(): array
{
    return [
        new MarketAnalystAgent($this->reportId), // $this->reportId is not serializable context
    ];
}
```

**What works:**

```php
// GOOD: plain construction — the container re-resolves fresh instances in workers
public function agents(): array
{
    return [
        new MarketAnalystAgent,
        new TechnicalAnalystAgent,
        new RiskAnalystAgent,
    ];
}

// GOOD: interface dependency bound in a service provider
// AppServiceProvider::register():
//   $this->app->bind(DataSourceInterface::class, LiveDataSource::class);
//
// Agent constructor:
//   public function __construct(protected DataSourceInterface $source) {}
//
// Then in agents():
public function agents(): array
{
    return [new MarketAnalystAgent]; // container resolves DataSourceInterface automatically
}
```

Laravel Swarm validates container-resolvability before dispatching. If an agent cannot be resolved, the swarm throws a `SwarmException` with the agent class name and the reason, before any work begins.

**Passing per-run data to agents:** Use structured task input (`prompt(['key' => 'value'])`) or `RunContext`. Agents receive the original task via their `prompt()` call inside the worker. Do not try to carry runtime identifiers through agent constructor arguments.

## Execution Semantics

Parallel execution is true concurrency via `ConcurrencyManager`. All agents run simultaneously in separate PHP processes, not sequentially.

A few things follow from this:

- **Result order is not guaranteed.** `SwarmResponse->steps` is assembled in the index order of `agents()`, but the underlying concurrent execution may complete in any order. Do not write code that expects `steps[0]` to contain the output of the fastest agent.
- **Each agent receives the original task.** No accumulated state flows between agents. If you call `prompt('Analyze Acme Payroll for Q1 risk exposure')`, that exact string (or structured input) is what each of the three agents receives.
- **Agent outputs are concatenated.** The `SwarmResponse->output` string joins individual outputs with double newlines. Inspect `SwarmResponse->steps` if you need each agent's result separately.

## Collecting Results

After `prompt()`, iterate `$response->steps` to access each agent's output individually:

```php
use App\Ai\Swarms\ResearchSwarm;

$response = ResearchSwarm::make()->prompt([
    'company' => 'Acme Payroll',
    'market' => 'US mid-market payroll',
    'quarter' => 'Q1 2026',
]);

foreach ($response->steps as $step) {
    echo $step->agentClass.': '.$step->output.PHP_EOL;
}

// Access the combined output directly
echo $response->output;
```

Each `$step` has:

- `agentClass` — fully qualified class name of the agent that produced the step
- `output` — the agent's text response
- `metadata` — includes `index`, `usage`, and `duration_ms`
- `artifacts` — any artifacts the agent attached

## Live Streaming

Parallel `stream()`, `broadcast()`, `broadcastNow()`, and `broadcastOnQueue()`
are available behind `SWARM_PARALLEL_STREAMING_ENABLED=true` when Laravel's
concurrency driver is `process`. The flag defaults off.

Events are truly interleaved while branch processes run. Each branch event has
`branch_id`, `attempt_id`, and a strictly increasing `branch_sequence`; native
event/invocation IDs pass through unchanged and may repeat across branches.
There is no global branch order. Render each branch independently, then use the
authored step order for the completed response. See [Parallel live
multiplexing](streaming.md#parallel-live-multiplexing) for backpressure, bounds,
failure, disconnect, replay, broadcast, and rollout behavior.

If the process transport is unavailable, the call fails before invoking an
agent. Use `prompt()` for buffered completion. Swarm never labels buffered
completion as a live stream.

## Timeout

The `#[Timeout]` attribute sets a best-effort orchestration deadline in seconds that covers the full parallel group:

```php
use BuiltByBerry\LaravelSwarm\Attributes\Timeout;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
#[Timeout(60)]
class ResearchSwarm implements Swarm
{
    // ...
}
```

For `prompt()` and `queue()`, the timeout is checked before the parallel group
starts and again after it completes. It does not hard-cancel an in-flight
provider call. The opt-in process-backed `stream()` path additionally enforces
the absolute deadline while multiplexing and terminates/reaps its local branch
processes. That local process termination cannot guarantee cancellation of a
remote provider effect the provider already accepted.

## Execution Modes

| Mode | Supported | Notes |
|---|---|---|
| `prompt()` | Yes | Blocks until all agents complete, then returns `SwarmResponse`. |
| `queue()` | Yes | Dispatches a single background job that runs the parallel group. |
| `stream()` | Opt-in | Live process-backed multiplexing; default off. See [Live Streaming](#live-streaming). |
| `broadcast()` / `broadcastNow()` / `broadcastOnQueue()` | Opt-in | Same live branch stream and identity contract; default off. |
| `dispatchDurable()` | Yes | Each agent becomes an independent durable branch job. See below. |

## Durable Parallel Failure Policy

When you use `dispatchDurable()` with a Parallel swarm, each agent runs as an independent durable branch job. You can control what happens when one or more branches fail using the `#[DurableParallelFailurePolicy]` attribute:

```php
use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy as FailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
#[DurableParallelFailurePolicy(FailurePolicy::PartialSuccess)]
class ResearchSwarm implements Swarm
{
    // ...
}
```

The three options from `DurableParallelFailurePolicy` enum:

- **`CollectFailures`** (`collect_failures`) — Default. Collect all results including failures. The run completes and the failed branch outputs are included in the steps collection.
- **`FailRun`** (`fail_run`) — Fail the whole run on first branch failure. Use when every agent's output is required.
- **`PartialSuccess`** (`partial_success`) — Succeed with whatever completed. Branches that failed are omitted from the steps collection; the run itself is not marked failed.

Without this attribute, durable parallel runs default to `CollectFailures`.

Durable parallel execution requires database-backed persistence (`SWARM_PERSISTENCE_DRIVER=database`), a queue worker, and scheduled `swarm:recover`. See [Durable Execution](durable-execution.md).

## Testing

For most application tests, use `SwarmFake`. It records that the swarm was called with the correct task without running any agents or Laravel concurrency.

```php
use App\Ai\Swarms\ResearchSwarm;

it('dispatches a research swarm for the given company', function () {
    ResearchSwarm::fake(['Market analysis complete.']);

    ResearchSwarm::make()->prompt([
        'company' => 'Acme Payroll',
        'market' => 'US mid-market payroll',
    ]);

    ResearchSwarm::assertPrompted(['company' => 'Acme Payroll']);
});
```

The fake verifies that your application code invokes the swarm correctly. It does not execute `ParallelRunner`, ConcurrencyManager, or any agent.

**Testing real concurrency:** To verify that agents actually run concurrently and produce real outputs, write a feature test with the `process` concurrency driver enabled. Run that lane with:

```bash
composer test:process-concurrency
```

Before enabling top-level parallel live streaming in a serving environment, run
`php artisan swarm:health --parallel-streaming` and require the `Parallel live
streaming` row to report `ok`. Its `max_branches` setting limits branch processes
per stream, not application-wide processes or raw file descriptors. Budget
aggregate capacity as concurrent live streams times `max_branches`, allow several
descriptors per branch, and enforce that bound in the application's serving or
queue concurrency controls.

Process workers do not inherit request-local tenant globals. Carry tenant
identity in `RunContext` (and Laravel `Context` when your child bootstrap reads
it); the child enters the reconstructed active run context before resolving its
agent. Application tenancy bindings must initialize from that explicit identity.

See [Testing](testing.md) for the full testing guide, including lifecycle event assertions and persisted run assertions.

## Related

- [examples/parallel-research-swarm](../examples/parallel-research-swarm/README.md) — working example with market, competitor, and customer researcher agents
- [Durable Execution](durable-execution.md) — checkpointed background execution including durable parallel branches
- [Streaming](streaming.md) — includes the default-off, process-backed top-level parallel live contract
- [Testing](testing.md) — fakes, assertions, and process-concurrency test lane
