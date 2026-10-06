<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\WithOutputsRoutePlanCoordinator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;

/**
 * @param  array<string, mixed>  $schema
 */
function assertEveryRoutePlanObjectIsClosed(array $schema): void
{
    if (($schema['type'] ?? null) === 'object') {
        expect($schema['additionalProperties'] ?? null)->toBeFalse();
    }

    foreach (['properties', 'anyOf'] as $childrenKey) {
        foreach ($schema[$childrenKey] ?? [] as $child) {
            if (is_array($child)) {
                assertEveryRoutePlanObjectIsClosed($child);
            }
        }
    }

    if (is_array($schema['items'] ?? null)) {
        assertEveryRoutePlanObjectIsClosed($schema['items']);
    }
}

/**
 * @param  array<string, mixed>  $schema
 */
function assertWithOutputsWireShape(array $schema, bool $requiresOne): void
{
    expect($schema['properties']['with_outputs'])
        ->toMatchArray([
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]);

    expect($schema['required'])->toBe(array_keys($schema['properties']))
        ->and($schema['required'])->toContain('with_outputs');

    if ($requiresOne) {
        expect($schema['properties']['with_outputs']['minItems'] ?? null)->toBe(1);
    } else {
        expect($schema['properties']['with_outputs'])->not->toHaveKey('minItems');
    }
}

/**
 * @return array<string, mixed>
 */
function withOutputsRoutePayload(): array
{
    return [
        'start_at' => 'respond',
        'nodes' => [
            'respond' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Draft the response.',
                'with_outputs' => [],
                'next' => 'review',
            ],
            'review' => [
                'type' => 'rollup',
                'agent' => FakeEditor::class,
                'prompt' => 'Review the response.',
                'with_outputs' => ['respond'],
                'next' => 'done',
            ],
            'done' => [
                'type' => 'finish',
                'output_from' => 'review',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function staticWithOutputsPlan(mixed $withOutputs): array
{
    return [
        'start_at' => 'target',
        'nodes' => [
            'target' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Target prompt.',
                'with_outputs' => $withOutputs,
            ],
        ],
    ];
}

test('a schema-conformant worker and rollup payload validates through the route planner', function () {
    $coordinator = new WithOutputsRoutePlanCoordinator;
    $factory = new JsonSchemaTypeFactory;
    $payload = withOutputsRoutePayload();
    $dispatchSchema = (new ObjectSchema($coordinator->schema($factory)))->toSchema();

    foreach ($payload['nodes'] as $nodeId => $nodePayload) {
        $slot = $dispatchSchema['properties']['nodes']['properties'][$nodeId];

        if (isset($slot['anyOf'])) {
            $slot = collect($slot['anyOf'])
                ->first(fn (array $variant): bool => $variant['required'] === array_keys($nodePayload));
        }

        expect($slot)->toBeArray()
            ->and(array_keys($nodePayload))->toBe($slot['required']);
    }

    foreach (['respond', 'review'] as $nodeId) {
        expect($dispatchSchema['properties']['nodes']['properties'][$nodeId]['properties']['with_outputs'])
            ->toMatchArray([
                'type' => 'array',
                'items' => ['type' => 'string'],
            ]);
    }

    $plan = (new HierarchicalRoutePlanner)->fromCoordinatorOutput(
        $coordinator,
        [new FakeWriter, new FakeEditor],
        json_encode($payload, JSON_THROW_ON_ERROR),
        'Tests\\WithOutputsSwarm',
    );

    expect($plan->node('review'))->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($plan->node('review')->withOutputs)->toBe(['respond' => 'respond']);
});

test('worker with_outputs lists normalize node ids as aliases and collapse duplicates', function () {
    $plan = (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        [
            'start_at' => 'source',
            'nodes' => [
                'source' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Source prompt.',
                    'next' => 'target',
                ],
                'target' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Target prompt.',
                    'with_outputs' => ['source', 'source'],
                ],
            ],
        ],
        'Tests\\WithOutputsSwarm',
    );

    expect($plan->node('target'))->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($plan->node('target')->withOutputs)->toBe(['source' => 'source']);
});

test('worker with_outputs alias maps retain multiple aliases for one node', function () {
    $plan = (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        [
            'start_at' => 'source',
            'nodes' => [
                'source' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Source prompt.',
                    'next' => 'target',
                ],
                'target' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Target prompt.',
                    'with_outputs' => [
                        'draft' => 'source',
                        'draft_copy' => 'source',
                    ],
                ],
            ],
        ],
        'Tests\\WithOutputsSwarm',
    );

    expect($plan->node('target')->withOutputs)->toBe([
        'draft' => 'source',
        'draft_copy' => 'source',
    ]);
});

test('worker with_outputs lists reject non-string node ids with the list message', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        staticWithOutputsPlan([42]),
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [target] must define [with_outputs] list entries as non-empty node ids.',
    );
});

test('worker with_outputs lists reject empty node ids with the list message', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        staticWithOutputsPlan(['']),
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [target] must define [with_outputs] list entries as non-empty node ids.',
    );
});

test('worker with_outputs mixed maps retain the alias validation message', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        staticWithOutputsPlan([0 => 'source', 2 => 'source']),
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [target] output aliases must be non-empty strings.',
    );
});

test('worker with_outputs scalars describe both accepted shapes', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        staticWithOutputsPlan('source'),
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [target] must define [with_outputs] as an object keyed by alias or a list of node ids.',
    );
});

test('worker with_outputs lists still reject unknown node ids', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        staticWithOutputsPlan(['missing']),
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [target] maps output alias [missing] from unknown node [missing].',
    );
});

test('worker with_outputs lists still reject future node ids', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeWriter],
        [
            'start_at' => 'first',
            'nodes' => [
                'first' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'First prompt.',
                    'with_outputs' => ['future'],
                    'next' => 'future',
                ],
                'future' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'Future prompt.',
                ],
            ],
        ],
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [first] cannot map output alias [future] from [future] before that node has completed.',
    );
});

test('rollup with_outputs lists still reject the rollup node itself', function () {
    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        [new FakeEditor],
        [
            'start_at' => 'rollup',
            'nodes' => [
                'rollup' => [
                    'type' => 'rollup',
                    'agent' => FakeEditor::class,
                    'prompt' => 'Roll up.',
                    'with_outputs' => ['rollup'],
                ],
            ],
        ],
        'Tests\\WithOutputsSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [rollup] cannot map output alias [rollup] from [rollup] before that node has completed.',
    );
});

test('dispatch serialization preserves strict worker and rollup with_outputs wire shapes', function () {
    $factory = new JsonSchemaTypeFactory;
    $coordinator = new WithOutputsRoutePlanCoordinator;
    $standalone = (new ObjectSchema([
        'worker' => RoutePlanSchema::worker($factory),
        'rollup' => RoutePlanSchema::rollup($factory),
        'node' => RoutePlanSchema::node($factory),
    ]))->toSchema();
    $coordinatorSchema = (new ObjectSchema($coordinator->schema($factory)))->toSchema();

    foreach ([$standalone, AnthropicSchemaSanitizer::sanitize($standalone)] as $schema) {
        $worker = $schema['properties']['worker'];
        $rollup = $schema['properties']['rollup'];
        $nodeVariants = $schema['properties']['node']['anyOf'];
        $workerVariant = collect($nodeVariants)->first(
            fn (array $variant): bool => ($variant['properties']['type']['enum'] ?? null) === ['worker'],
        );
        $rollupVariant = collect($nodeVariants)->first(
            fn (array $variant): bool => ($variant['properties']['type']['enum'] ?? null) === ['rollup'],
        );

        assertWithOutputsWireShape($worker, false);
        assertWithOutputsWireShape($rollup, true);
        assertWithOutputsWireShape($workerVariant, false);
        assertWithOutputsWireShape($rollupVariant, true);
        assertEveryRoutePlanObjectIsClosed($schema);
    }

    foreach ([$coordinatorSchema, AnthropicSchemaSanitizer::sanitize($coordinatorSchema)] as $schema) {
        assertWithOutputsWireShape($schema['properties']['nodes']['properties']['respond'], false);
        assertWithOutputsWireShape($schema['properties']['nodes']['properties']['review'], true);
        assertEveryRoutePlanObjectIsClosed($schema);
    }
});
