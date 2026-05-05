# Parallel Research Swarm

Shows independent research agents running at the same time through Laravel
Concurrency.

Use this pattern when agents do not depend on each other's output.

This example teaches:

- each parallel agent receives the original task input;
- simple concrete agents are container-resolvable without manual binding;
- agents with interface dependencies need normal Laravel container bindings.

## Prerequisites

- Laravel AI is configured in your application.
- Parallel agents are stateless and container-resolvable by class.
- Laravel concurrency can run in the current environment.
- Durable parallel usage also requires database persistence,
  `SWARM_CAPTURE_ACTIVE_CONTEXT=true`, a queue worker, and scheduled recovery.

## Swarm

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\CompetitorResearcher;
use App\Ai\Agents\CustomerResearcher;
use App\Ai\Agents\MarketResearcher;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
class ResearchSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new MarketResearcher,
            new CompetitorResearcher,
            new CustomerResearcher,
        ];
    }
}
```

## Container Contract

No manual binding is needed for simple concrete agents such as
`MarketResearcher`. Laravel can resolve those classes by default.

If an agent depends on an interface, bind that dependency in an application
service provider:

```php
use App\Contracts\MarketData;
use App\Services\ApiMarketData;

public function register(): void
{
    $this->app->bind(MarketData::class, ApiMarketData::class);
}
```

Keep parallel agents stateless. Do not rely on runtime-mutated agent instances
or sibling agent output. If a workflow needs configured duplicate instances or
step-to-step dependencies, use sequential or hierarchical orchestration.

## Usage

```php
use App\Ai\Swarms\ResearchSwarm;

$response = ResearchSwarm::make()->prompt([
    'company' => 'Acme Payroll',
    'market' => 'US mid-market payroll',
]);

foreach ($response->steps as $step) {
    logger()->info('Research step completed', [
        'agent' => $step->agentClass,
        'index' => $step->metadata['index'] ?? null,
        'usage' => $step->metadata['usage'] ?? [],
    ]);
}
```

## Durable Parallel Usage

Top-level parallel swarms can also run durably. Each agent becomes an
independent durable branch job, and the parent run joins those branch outputs
before completing.

Use `#[DurableParallelFailurePolicy]` when the workflow needs a different
branch-failure contract from the default `collect_failures` behavior:

```php
use App\Ai\Agents\CompetitorResearcher;
use App\Ai\Agents\CustomerResearcher;
use App\Ai\Agents\MarketResearcher;
use BuiltByBerry\LaravelSwarm\Attributes\DurableParallelFailurePolicy;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\DurableParallelFailurePolicy as FailurePolicy;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Parallel)]
#[DurableParallelFailurePolicy(FailurePolicy::PartialSuccess)]
class ResearchSwarm implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new MarketResearcher,
            new CompetitorResearcher,
            new CustomerResearcher,
        ];
    }
}
```

```php
$response = ResearchSwarm::make()
    ->dispatchDurable([
        'company' => 'Acme Payroll',
        'market' => 'US mid-market payroll',
    ])
    ->onQueue('swarm-durable');

$response->runId;
```

Durable parallel execution requires database-backed persistence, a queue
worker, and scheduled `swarm:recover`.

## What Happened

In the synchronous parallel run, every agent received the original task and
Laravel concurrency executed them independently. In the durable parallel run,
Laravel Swarm created branch jobs with independent leases, recorded each branch
terminal state, and joined the branch outputs before completing the run.
