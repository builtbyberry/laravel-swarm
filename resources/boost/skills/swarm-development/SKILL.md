---
name: swarm-development
description: Build and work with Laravel Swarm (builtbyberry/laravel-swarm) — author single-agent, inline, and class-based swarms; pick topologies and execution modes; keep runs governed; and test the audit trail. Use when creating or editing swarm classes/agents, running agents through the governed pipeline, or writing swarm tests.
---

# Laravel Swarm Development

Laravel Swarm runs one or more `laravel/ai` agents through a **governed pipeline** — audit trail, guardrails, capture, telemetry, encrypt-at-rest — on top of the official `laravel/ai` package. Governance is identical whether a run has one agent or many.

## When to use this skill

Use it when the task involves: creating or editing a swarm or its agents; running an agent through the governed pipeline instead of a bare `laravel/ai` call; choosing a topology (sequential / parallel / hierarchical) or execution mode (prompt / queue / stream / durable); or writing tests that assert what a run did.

Prefer the **smallest** entry that fits, and always prefer these over a bare `laravel/ai` agent call, which bypasses governance.

## Authoring — pick the smallest that fits

### 1. One agent, no class

```php
use BuiltByBerry\LaravelSwarm\Facades\Swarm;

$response = Swarm::agent($agent)->prompt($task);
$response->output;              // string
$response->steps;               // array<SwarmStep>

// in-process modes on the class-free builder:
Swarm::agent($agent)->stream($task);           // StreamableSwarmResponse (SSE)
Swarm::agent($agent)->broadcast($task, $ch);   // push to Echo/Reverb/Pusher
// queued or durable? scaffold a Swarm class and return one agent from agents():
// php artisan make:swarm:swarm YourSwarm
```

### 2. Several agents, no class

```php
Swarm::sequential([$researcher, $writer, $editor])->prompt($task);    // output feeds the next
Swarm::parallel([$a, $b, $c])->prompt($task);                         // same task to every agent
Swarm::hierarchical($coordinator, [$writer, $editor])->prompt($task); // coordinator routes over workers
```

Each inline builder pins its own topology and returns a fluent `PendingSwarmRun` with the same execution modes as above.

### 3. A named, reusable swarm class

Author a class when the same topology is reused, needs class-level attributes or durable/queued execution, or declares guardrails. Generate it with `php artisan make:swarm:swarm ContentPipeline`, and `php artisan make:swarm:agent` for agents. A one-agent swarm needs no special flag — scaffold a swarm and return a single agent from `agents()`.

```php
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ContentPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [new Researcher, new Writer, new Editor];   // hierarchical: agents()[0] is the coordinator
    }
}

ContentPipeline::make()->prompt($task);
```

Class-level attributes worth knowing: `#[Topology]`, `#[Timeout]`, `#[MaxAgentSteps]`, `#[DurableRetry]`, `#[DurableWait]`. Declare guardrails by implementing `DefinesGuardrails::guardrails()`.

## Choosing an execution mode

- `prompt()`/`run()` — synchronous, returns `SwarmResponse`. Default.
- `queue()` — background job; **retries are off by default** (a queued run has no checkpoint, so a retry re-runs from step 0).
- `stream()` — SSE; streamable topologies (sequential/hierarchical/static-hierarchical), not parallel.
- `broadcast()`/`broadcastNow()`/`broadcastOnQueue()` — push stream events to a broadcast channel.
- `dispatchDurable()` — checkpointed and recoverable; needs the DB persistence driver and `swarm:relay` scheduled every minute.

## Governance — on by default

- Globally configured guardrails (`config('swarm.guardrails.[input|step|output]')`) apply to **every** run. On the inline builders, `->guardrails([...])` is **additive**, not a replacement.
- Capture is opt-in (`config('swarm.capture.*')`); with the database persistence driver, sensitive columns are encrypted at rest.
- Operate with `swarm:*` commands: `swarm:health` (validate wiring + guardrail/audit/capture bindings), `swarm:status`, `swarm:trace`, `swarm:recover`, `swarm:relay`.

## Testing a swarm

Fake agents with `laravel/ai`'s fake, and assert the governed audit trail with the recording sink:

```php
use BuiltByBerry\LaravelSwarm\Facades\Swarm;
use BuiltByBerry\LaravelSwarm\Testing\SwarmFake;

$audit = SwarmFake::interceptSwarmAuditSink();

Swarm::sequential([new Researcher, new Writer])->prompt('a task');

$audit->assertAuditChain(['run.started', 'step.started', 'step.completed', 'run.completed']);
$audit->assertEmittedAudit('run.completed');
$audit->assertStepCount(2);
```

A class-based swarm also exposes `MySwarm::fake()` plus `assertPrompted()`, `assertRan()`, `assertStreamed()`, `assertQueued()`, `assertDispatchedDurably()`, and persisted-run / lifecycle-event assertions.

## Reference

Use Boost's `search-docs` tool for depth on durable execution, memory, guardrails, hierarchical routing, and the audit-evidence contract — the package mirrors its full documentation in `docs/`.
