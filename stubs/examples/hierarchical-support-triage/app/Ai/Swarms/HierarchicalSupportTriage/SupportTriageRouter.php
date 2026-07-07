<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Ai\Swarms\HierarchicalSupportTriage;

use {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage\BillingResponder;
use {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage\GeneralResponder;
use {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage\RequestClassifier;
use {{ rootNamespace }}\Ai\Agents\HierarchicalSupportTriage\TechnicalResponder;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

/**
 * hierarchical-support-triage — classify a request, then route it to a handler.
 *
 * Topology: Hierarchical. The FIRST agent is the coordinator: it reads the task
 * and returns a structured route plan. The remaining agents are workers the
 * coordinator routes to at runtime. Laravel Swarm validates the plan as a DAG
 * and executes only the routed nodes, so a billing request runs the billing
 * handler and never touches the technical one.
 *
 * Demonstrates: Hierarchical topology, a coordinator-owned route plan (no
 * `route()` callback), classify-then-dispatch routing, and a `finish` node that
 * returns the chosen handler's output as the swarm result.
 *
 * Next step: docs/hierarchical-routing.md
 */
#[Topology(TopologyEnum::Hierarchical)]
class SupportTriageRouter implements Swarm
{
    use Runnable;

    /**
     * @return array<int, \BuiltByBerry\LaravelSwarm\Contracts\Agent>
     */
    public function agents(): array
    {
        return [
            new RequestClassifier,
            new BillingResponder,
            new TechnicalResponder,
            new GeneralResponder,
        ];
    }
}
