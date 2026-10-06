<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlanner;
use BuiltByBerry\LaravelSwarm\Routing\HierarchicalWorkerNode;
use BuiltByBerry\LaravelSwarm\Routing\RoutePlanSchema;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ParallelBranchesRoutePlanCoordinator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Laravel\Ai\ObjectSchema;

/**
 * @return array{start_at: string, nodes: array<string, array<string, mixed>>}
 */
function helperConformantParallelPlan(?string $branchNext): array
{
    return [
        'start_at' => 'fan',
        'nodes' => [
            'fan' => [
                'type' => 'parallel',
                'branches' => ['research', 'draft'],
                'next' => 'join',
            ],
            'research' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Research the topic.',
                'with_outputs' => [],
                'next' => $branchNext,
            ],
            'draft' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'Draft the answer.',
                'with_outputs' => [],
                'next' => $branchNext,
            ],
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Combine the branch outputs.',
                'with_outputs' => ['research', 'draft'],
                'next' => 'done',
            ],
            'done' => [
                'type' => 'finish',
                'output_from' => 'join',
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $slot
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function matchingParallelBranchesVariant(array $slot, array $payload): array
{
    if (! isset($slot['anyOf'])) {
        return $slot;
    }

    $variant = collect($slot['anyOf'])->first(function (array $variant) use ($payload): bool {
        $types = $variant['properties']['type']['enum'] ?? [];

        return in_array($payload['type'] ?? null, $types, true)
            && ($variant['required'] ?? []) === array_keys($payload);
    });

    expect($variant)->toBeArray();

    return $variant;
}

/**
 * @param  array<string, mixed>  $schema
 */
function assertParallelBranchesPayloadConformsToDispatchSchema(array $schema, array $payload): void
{
    foreach ($payload['nodes'] as $nodeId => $nodePayload) {
        $slot = matchingParallelBranchesVariant(
            $schema['properties']['nodes']['properties'][$nodeId],
            $nodePayload,
        );

        expect(array_keys($nodePayload))->toBe($slot['required']);

        if (! array_key_exists('next', $nodePayload)) {
            continue;
        }

        $declared = $slot['properties']['next'];
        $types = isset($declared['anyOf'])
            ? array_column($declared['anyOf'], 'type')
            : (array) $declared['type'];
        $jsonType = $nodePayload['next'] === null ? 'null' : get_debug_type($nodePayload['next']);

        expect($types)->toContain($jsonType);
    }
}

/**
 * @return array<int, object>
 */
function parallelBranchesWorkers(): array
{
    return [new FakeWriter, new FakeEditor, new FakeResearcher];
}

test('helper-conformant parallel fan-out validates through coordinator output', function (?string $branchNext) {
    $coordinator = new ParallelBranchesRoutePlanCoordinator;
    $payload = helperConformantParallelPlan($branchNext);
    $dispatchSchema = (new ObjectSchema($coordinator->schema(new JsonSchemaTypeFactory)))->toSchema();

    assertParallelBranchesPayloadConformsToDispatchSchema($dispatchSchema, $payload);

    $plan = (new HierarchicalRoutePlanner)->fromCoordinatorOutput(
        $coordinator,
        parallelBranchesWorkers(),
        json_encode($payload, JSON_THROW_ON_ERROR),
        'Tests\\ParallelBranchesSwarm',
    );

    expect($plan->node('research'))->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($plan->node('research')->next)->toBeNull()
        ->and($plan->node('draft'))->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($plan->node('draft')->next)->toBeNull();
})->with([
    'explicit null branch successors' => null,
    'redundant join branch successors' => 'join',
]);

test('helper-conformant terminal worker with explicit null next validates', function () {
    $coordinator = new ParallelBranchesRoutePlanCoordinator;
    $payload = [
        'start_at' => 'join',
        'nodes' => [
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Return the final answer.',
                'with_outputs' => [],
                'next' => null,
            ],
        ],
    ];
    $dispatchSchema = (new ObjectSchema($coordinator->schema(new JsonSchemaTypeFactory)))->toSchema();

    assertParallelBranchesPayloadConformsToDispatchSchema($dispatchSchema, $payload);

    $plan = (new HierarchicalRoutePlanner)->fromCoordinatorOutput(
        $coordinator,
        parallelBranchesWorkers(),
        json_encode($payload, JSON_THROW_ON_ERROR),
        'Tests\\ParallelBranchesSwarm',
    );

    expect($plan->node('join'))->toBeInstanceOf(HierarchicalWorkerNode::class)
        ->and($plan->node('join')->next)->toBeNull();
});

test('redundant branch join successors persist like omitted PHP-authored successors', function () {
    // The branches carry their own with_outputs and metadata so a normalised
    // copy that dropped either would no longer match the PHP-authored plan.
    $withBranchDetail = function (?string $branchNext): array {
        $plan = helperConformantParallelPlan($branchNext);
        $plan['start_at'] = 'intro';
        $plan['nodes'] = [
            'intro' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Frame the topic.',
                'next' => 'fan',
            ],
            ...$plan['nodes'],
        ];
        $plan['nodes']['research']['with_outputs'] = ['intro'];
        $plan['nodes']['research']['metadata'] = ['stage' => 'research'];
        $plan['nodes']['draft']['with_outputs'] = ['brief' => 'intro'];

        return $plan;
    };

    $coordinatorPlan = (new HierarchicalRoutePlanner)->fromCoordinatorOutput(
        new ParallelBranchesRoutePlanCoordinator,
        parallelBranchesWorkers(),
        json_encode($withBranchDetail('join'), JSON_THROW_ON_ERROR),
        'Tests\\ParallelBranchesSwarm',
    );
    $phpPlan = $withBranchDetail(null);
    unset($phpPlan['nodes']['research']['next'], $phpPlan['nodes']['draft']['next']);

    $staticPlan = (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $phpPlan,
        'Tests\\ParallelBranchesSwarm',
    );

    expect($coordinatorPlan->toArray())->toBe($staticPlan->toArray())
        ->and($coordinatorPlan->node('research')->withOutputs)->toBe(['intro' => 'intro'])
        ->and($coordinatorPlan->node('research')->metadata)->toBe(['stage' => 'research'])
        ->and($coordinatorPlan->node('draft')->withOutputs)->toBe(['brief' => 'intro'])
        ->and(HierarchicalRoutePlan::fromArray($coordinatorPlan->toArray())->toArray())
        ->toBe($coordinatorPlan->toArray());
});

test('parallel branch next naming a node other than its join remains rejected', function () {
    $payload = helperConformantParallelPlan(null);
    $payload['nodes']['research']['next'] = 'done';

    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [research] cannot define [next] when used as a parallel branch.',
    );
});

test('parallel branch owned by groups with different joins remains rejected', function () {
    $payload = [
        'start_at' => 'first_fan',
        'nodes' => [
            'first_fan' => [
                'type' => 'parallel',
                'branches' => ['shared', 'first_only'],
                'next' => 'second_fan',
            ],
            'second_fan' => [
                'type' => 'parallel',
                'branches' => ['shared', 'second_only'],
                'next' => 'join',
            ],
            'shared' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Shared branch.',
                'next' => 'second_fan',
            ],
            'first_only' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'First branch.',
            ],
            'second_only' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'Second branch.',
            ],
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Join.',
            ],
        ],
    ];

    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [shared] cannot define [next] when used as a parallel branch.',
    );
});

test('dual-role parallel branch carrying next remains rejected', function (string $role) {
    $payload = [
        'start_at' => $role === 'start_at' ? 'branch' : 'fan',
        'nodes' => [
            'fan' => [
                'type' => 'parallel',
                'branches' => ['branch'],
                'next' => 'join',
            ],
            'branch' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Branch.',
                'next' => 'join',
            ],
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Join.',
            ],
        ],
    ];

    if ($role === 'incoming_next') {
        $payload['nodes']['incoming'] = [
            'type' => 'worker',
            'agent' => FakeEditor::class,
            'prompt' => 'Incoming.',
            'next' => 'branch',
        ];
    }

    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [branch] cannot define [next] when used as a parallel branch.',
    );
})->with(['start_at', 'incoming_next']);

test('looped parallel branch carrying next remains rejected', function () {
    // The loop targets an earlier node rather than the branch itself, so the
    // branch has no ordinary incoming path and only its own loop keeps it out
    // of redundant-edge normalization.
    $payload = [
        'start_at' => 'intro',
        'nodes' => [
            'intro' => [
                'type' => 'worker',
                'agent' => FakeEditor::class,
                'prompt' => 'Intro.',
                'next' => 'fan',
            ],
            'fan' => [
                'type' => 'parallel',
                'branches' => ['branch'],
                'next' => 'join',
            ],
            'branch' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Branch.',
                'next' => 'join',
                'loop' => ['to' => 'intro', 'max_iterations' => 2],
            ],
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Join.',
            ],
        ],
    ];

    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [branch] cannot define [next] when used as a parallel branch.',
    );
});

test('rollup parallel branch carrying next remains rejected', function () {
    $payload = [
        'start_at' => 'source',
        'nodes' => [
            'source' => [
                'type' => 'worker',
                'agent' => FakeWriter::class,
                'prompt' => 'Source.',
                'next' => 'fan',
            ],
            'fan' => [
                'type' => 'parallel',
                'branches' => ['branch'],
                'next' => 'join',
            ],
            'branch' => [
                'type' => 'rollup',
                'agent' => FakeEditor::class,
                'prompt' => 'Roll up.',
                'with_outputs' => ['source'],
                'next' => 'join',
            ],
            'join' => [
                'type' => 'worker',
                'agent' => FakeResearcher::class,
                'prompt' => 'Join.',
            ],
        ],
    ];

    expect(fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    ))->toThrow(
        SwarmException::class,
        'Hierarchical worker node [branch] cannot define [next] when used as a parallel branch.',
    );
});

test('PHP-authored parallel branch successor compatibility is unchanged', function (?string $branchNext, bool $valid) {
    $payload = helperConformantParallelPlan($branchNext);

    if ($branchNext === null) {
        unset($payload['nodes']['research']['next'], $payload['nodes']['draft']['next']);
    }

    $build = fn () => (new HierarchicalRoutePlanner)->fromStaticPlan(
        parallelBranchesWorkers(),
        $payload,
        'Tests\\ParallelBranchesSwarm',
    );

    if ($valid) {
        expect($build()->node('research')->next)->toBeNull();

        return;
    }

    expect($build)->toThrow(
        SwarmException::class,
        'Hierarchical worker node [research] cannot define [next] when used as a parallel branch.',
    );
})->with([
    'omitted successor' => [null, true],
    'redundant join successor' => ['join', true],
    'different successor' => ['done', false],
]);

test('dispatch serialization preserves strict nullable worker next wire shape', function () {
    $factory = new JsonSchemaTypeFactory;
    $schema = (new ObjectSchema([
        'worker' => RoutePlanSchema::worker($factory),
        'rollup' => RoutePlanSchema::rollup($factory),
        'parallel' => RoutePlanSchema::parallel($factory),
        'node' => RoutePlanSchema::node($factory),
    ]))->toSchema();

    foreach ([$schema, AnthropicSchemaSanitizer::sanitize($schema)] as $index => $wireSchema) {
        $worker = $wireSchema['properties']['worker'];
        $variants = $wireSchema['properties']['node']['anyOf'];
        expect($variants)->toHaveCount(5);

        $workerVariant = collect($variants)->first(
            fn (array $variant): bool => ($variant['properties']['type']['enum'] ?? null) === ['worker'],
        );

        $objectSchemas = [
            $wireSchema,
            $worker,
            $wireSchema['properties']['rollup'],
            $wireSchema['properties']['parallel'],
            ...$variants,
        ];

        foreach ($objectSchemas as $objectSchema) {
            expect($objectSchema['additionalProperties'])->toBeFalse();
        }

        foreach ([$worker, $wireSchema['properties']['rollup'], $wireSchema['properties']['parallel'], ...$variants] as $nodeSchema) {
            expect($nodeSchema['required'])->toBe(array_keys($nodeSchema['properties']));
        }

        foreach ([$worker, $workerVariant] as $workerSchema) {
            expect($workerSchema['required'])->toBe(array_keys($workerSchema['properties']))
                ->and($workerSchema['required'])->toContain('next');

            if ($index === 0) {
                expect($workerSchema['properties']['next']['type'])
                    ->toEqualCanonicalizing(['string', 'null']);
            } else {
                expect($workerSchema['properties']['next']['anyOf'])
                    ->toBe([
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ]);
            }
        }

        foreach (['rollup', 'parallel'] as $nodeType) {
            expect($wireSchema['properties'][$nodeType]['properties']['next']['type'])->toBe('string')
                ->and($wireSchema['properties'][$nodeType]['required'])
                ->toBe(array_keys($wireSchema['properties'][$nodeType]['properties']));
        }
    }
});
