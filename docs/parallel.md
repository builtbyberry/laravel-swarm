# Parallel Topology

Use the Parallel topology when every agent should process the same original task independently and you want all results collected. Unlike a sequential (chain) swarm where each agent's output feeds the next, Parallel is fan-out: the same input goes to every agent at the same time, and Laravel Swarm waits for all of them to finish before returning a combined result. No agent sees what the others produced.

## Mental Model

Think of a research swarm where three specialists independently analyze the same one-page brief: a market analyst, a technical analyst, and a risk analyst. Each receives the same brief. Each runs simultaneously in its own process. When all three finish, their outputs are collected and returned together. No analyst waits on or reads the work of the others.

That is exactly what `TopologyEnum::Parallel` does. The `agents()` array defines the specialists; the task is the brief; and `prompt()` is the button you press to fan it out.

## Declaration

Generate a parallel swarm with:

```bash
php artisan make:swarm ResearchSwarm --topology=parallel
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

Laravel Swarm's `ParallelRunner` solves this by extracting only the agent's class name from each instance you return in `agents()`, then re-resolving a fresh instance from the container inside each worker. This means:

1. Each agent **must be resolvable by class name** from the service container in the worker process.
2. The agent **must be stateless** — any state you attach to the agent instance in `agents()` will be discarded; the worker creates a new instance.
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

## Streaming Limitation

Parallel swarms do **not** support `stream()`, `broadcast()`, `broadcastNow()`, or `broadcastOnQueue()`.

These methods assume a sequential event stream: text delta, then tool call, then next agent starts. With concurrent fan-out, there is no meaningful ordering of tokens across simultaneous agent runs. Emitting interleaved deltas from three agents at once would produce an incoherent stream with no way to demarcate which tokens belong to which agent.

If you need live progress while parallel work runs, listen to lifecycle events (`SwarmStarted`, `SwarmStepCompleted`, `SwarmCompleted`) from a separate broadcasting layer rather than using the stream methods.

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

The timeout is checked before the parallel group starts and again after it completes. If the deadline has passed at either check, a `SwarmTimeoutException` is thrown and the run fails. The timeout does not hard-cancel an in-flight provider call — it is an orchestration deadline, not a process kill signal.

## Execution Modes

| Mode | Supported | Notes |
|---|---|---|
| `prompt()` | Yes | Blocks until all agents complete, then returns `SwarmResponse`. |
| `queue()` | Yes | Dispatches a single background job that runs the parallel group. |
| `stream()` | No | Not supported. See [Streaming Limitation](#streaming-limitation). |
| `broadcast()` / `broadcastNow()` / `broadcastOnQueue()` | No | Not supported. Sequential-only stream helpers. |
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

See [Testing](testing.md) for the full testing guide, including lifecycle event assertions and persisted run assertions.

## Related

- [examples/parallel-research-swarm](../examples/parallel-research-swarm/README.md) — working example with market, competitor, and customer researcher agents
- [Durable Execution](durable-execution.md) — checkpointed background execution including durable parallel branches
- [Streaming](streaming.md) — sequential-only; not available for Parallel swarms
- [Testing](testing.md) — fakes, assertions, and process-concurrency test lane
