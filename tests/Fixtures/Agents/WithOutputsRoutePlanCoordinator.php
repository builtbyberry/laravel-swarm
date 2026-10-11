<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class WithOutputsRoutePlanCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Draft a response, review it, then finish.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()->required(),
            'nodes' => $schema->object([
                'respond' => RoutePlanSchema::worker($schema),
                'review' => RoutePlanSchema::rollup($schema),
                'done' => RoutePlanSchema::finish($schema),
            ])->required(),
        ];
    }
}
