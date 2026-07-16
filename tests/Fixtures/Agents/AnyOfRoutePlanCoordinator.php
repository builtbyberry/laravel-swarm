<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * A hierarchical coordinator whose route-plan schema uses {@see RoutePlanSchema}
 * `anyOf` node unions — mirroring the shipped hierarchical-support-triage example
 * stub. Exists so the anyOf enrichment is exercised against a real
 * container-resolvable agent (the stub itself carries a `{{ rootNamespace }}`
 * placeholder and is not autoloadable).
 */
class AnyOfRoutePlanCoordinator implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Route the request to exactly one handler, then finish.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_at' => $schema->string()
                ->description('Id of the first node to run.')
                ->required(),
            'nodes' => $schema->object([
                'respond' => RoutePlanSchema::node($schema),
                'done' => RoutePlanSchema::finish($schema),
            ])->required(),
        ];
    }
}
