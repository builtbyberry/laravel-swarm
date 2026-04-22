# Laravel Swarm — Package Context

## What This Is

`builtbyberry/laravel-swarm` is a Laravel package that adds multi-agent swarm orchestration on top of the official `laravel/ai` package. Laravel AI handles single-agent LLM interactions. Laravel Swarm coordinates multiple Laravel AI agents into pipelines with sequential, parallel, and hierarchical topologies.

## Package Identity

- **Packagist:** `builtbyberry/laravel-swarm`
- **Namespace:** `BuiltByBerry\LaravelSwarm`
- **GitHub:** `https://github.com/builtbyberry/laravel-swarm`
- **Author:** Daniel Berry (J Street Digital)
- **Location:** `~/Code/laravel-swarm`

## Core Design Principle — Laravel Native Feel

Every decision must follow existing Laravel and Laravel AI conventions exactly. A developer who knows Laravel AI should look at a swarm class and understand it immediately. No invented patterns.

- Laravel AI uses `make:agent` — this package uses `make:swarm`
- Laravel AI agents use `Promptable` trait — swarms use `Runnable` trait
- Laravel AI uses PHP attributes (`#[Provider]`, `#[Model]`) — swarms use `#[Topology]`, `#[MaxAgentSteps]`, `#[Timeout]`
- Laravel AI agents use `->prompt()`, `->queue()`, `->stream()` — swarms use `->run()`, `->queue()`
- Laravel AI fakes with `Agent::fake()` — swarms fake with `Swarm::fake()`
- Config lives in `config/swarm.php` — not merged into `config/ai.php`
- Swarm classes live in `app/Ai/Swarms/` — extending the `app/Ai/` namespace Laravel AI establishes

## Tech Stack

- PHP ^8.5
- Laravel ^13.0
- `laravel/ai` ^0.6 (current Packagist line — no stable 1.0 yet)
- `orchestra/testbench` ^11
- `pestphp/pest` ^4.4 + `pest-plugin-laravel` ^4.1
- `laravel/pint` ^1.0
- Scripts: `composer test` runs pest, `composer format` runs pint

## Package Structure

```
src/
  Attributes/
    MaxAgentSteps.php       — #[MaxAgentSteps(10)]
    Timeout.php             — #[Timeout(300)]
    Topology.php            — #[Topology(TopologyEnum::Sequential)]
  Commands/
    MakeSwarmCommand.php    — php artisan make:swarm
  Concerns/
    Runnable.php            — trait giving swarms run(), queue(), fake(), assertions
  Contracts/
    Swarm.php               — interface requiring agents(): array
  Enums/
    Topology.php            — Sequential, Parallel, Hierarchical
  Exceptions/
    SwarmException.php
    SwarmTimeoutException.php
  Facades/
    Swarm.php               — backed by SwarmRunner
  Responses/
    SwarmResponse.php       — output, steps[], usage[]. Implements __toString()
    QueuedSwarmResponse.php — then(), catch() like Laravel AI
    SwarmStep.php           — agentClass, input, output per step
  Runners/
    SwarmRunner.php         — main entry, reads attributes, delegates to topology runners
    SequentialRunner.php    — agents run in order, each output becomes next input
    ParallelRunner.php      — all agents run concurrently via Concurrency facade
    HierarchicalRunner.php  — first agent coordinates, delegates to others (@todo real routing)
  Testing/
    SwarmFake.php           — intercepts run/queue, records calls, provides assertions
  SwarmServiceProvider.php
stubs/
  swarm.stub
config/
  swarm.php
tests/
  Feature/
  Unit/
  Fixtures/
    Agents/   — FakeResearcher, FakeWriter, FakeEditor
    Swarms/   — FakeSequentialSwarm, FakeParallelSwarm
```

## Key Architecture Decisions

- No facades used inside orchestration classes — dependency injection only
- `ParallelRunner` uses `ConcurrencyManager` from container, not the facade directly
- `SwarmRunner` uses `Config` and `Cache` contracts
- `Runnable::make()` returns `mixed` so a bound `SwarmFake` is valid on PHP 8.4+
- `SwarmFake::queue()` returns `QueuedSwarmResponse` backed by Laravel AI's `FakePendingDispatch` to avoid `PendingDispatch::__destruct()` issues
- Topology resolved via `ReflectionClass` reading `#[Topology]` attribute, falls back to `config('swarm.topology')`

## Current State

- 24 passing Pest tests
- All three topology runners implemented (hierarchical delegates to sequential pending real coordinator logic)
- `make:swarm` Artisan command working
- Full fake/assertion system working
- Config publishing working

## Known Gaps / Next Work

- `HierarchicalRunner` needs real coordinator routing — the first agent should return structured output that the runner uses to decide which downstream agents to invoke
- Streaming support at the swarm level — `SwarmRunner` should support emitting progress events between agent steps so controllers can pipe them to SSE
- Step events — emit `{"event": "step", "agent": "Writer"}` between agent runs so UIs can show real pipeline progress
- Commercial dashboard layer (separate repo, future work)

## Testing

```bash
cd ~/Code/laravel-swarm
composer install
composer test
composer format
```
