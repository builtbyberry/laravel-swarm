<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ParallelBranchesRoutePlanCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Run research and drafting in parallel, then join their outputs.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object([
                'fan' => RoutePlanSchema::parallel($schema)->required(),
                'research' => RoutePlanSchema::worker($schema)->required(),
                'draft' => RoutePlanSchema::node($schema)->required(),
                'join' => RoutePlanSchema::worker($schema)->required(),
                'done' => RoutePlanSchema::finish($schema)->required(),
            ])->required(),
        ];
    }
}
