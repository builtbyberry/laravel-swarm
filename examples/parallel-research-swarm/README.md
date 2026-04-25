# Parallel Research Swarm

Shows independent research agents running at the same time through Laravel
Concurrency.

Use this pattern when agents do not depend on each other's output.

This example teaches:

- each parallel agent receives the original task input;
- simple concrete agents are container-resolvable without manual binding;
- agents with interface dependencies need normal Laravel container bindings.

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

$response = ResearchSwarm::make()->run([
    'company' => 'Acme Payroll',
    'market' => 'US mid-market payroll',
]);

foreach ($response->steps as $step) {
    logger()->info('Research step completed', [
        'agent' => $step->agentClass,
        'duration_ms' => $step->durationMs,
    ]);
}
```
