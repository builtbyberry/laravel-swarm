<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * A hierarchical coordinator built from the vendor contracts only.
 *
 * Implements `Laravel\Ai\Contracts\Agent` + `Laravel\Ai\Contracts\HasStructuredOutput`
 * and NOT the swarm markers, so it exercises the coordinator path in
 * {@see HierarchicalRoutePlanner} and
 * {@see HierarchicalRunner} as an outside
 * consumer's coordinator would.
 */
class VendorOnlyCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a routing coordinator.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object()->required(),
        ];
    }
}
