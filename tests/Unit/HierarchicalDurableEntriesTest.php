<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Routing\HierarchicalRoutePlan;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;

/**
 * Reflectively invoke the protected durableEntries() builder on a bare runner
 * instance — it depends only on the plan, not on injected collaborators.
 *
 * @return array<int, array{type: string, node_id: string}>
 */
function durableEntriesFor(HierarchicalRoutePlan $plan): array
{
    $reflection = new ReflectionClass(HierarchicalRunner::class);
    $runner = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('durableEntries');

    /** @var array<int, array{type: string, node_id: string}> */
    return $method->invoke($runner, $plan);
}

test('a diamond-join node appears exactly once in the durable entry spine', function () {
    $plan = HierarchicalRoutePlan::fromArray([
        'start_at' => 'fan_out',
        'nodes' => [
            'fan_out' => ['type' => 'parallel', 'branches' => ['b1', 'b2'], 'next' => 'synth'],
            'b1' => ['type' => 'worker', 'agent' => 'B1', 'prompt' => 'b1'],
            'b2' => ['type' => 'worker', 'agent' => 'B2', 'prompt' => 'b2'],
            'synth' => ['type' => 'worker', 'agent' => 'S', 'prompt' => 's', 'next' => 'review'],
            'review' => ['type' => 'worker', 'agent' => 'R', 'prompt' => 'r', 'next' => 'finish', 'loop' => ['to' => 'synth', 'max_iterations' => 3]],
            'finish' => ['type' => 'finish', 'output_from' => 'review'],
        ],
    ]);

    $entries = durableEntriesFor($plan);
    $nodeIds = array_map(static fn (array $entry): string => $entry['node_id'], $entries);

    // The loop target / diamond-join node must be present exactly once so a
    // bounded loop rewinds to a single, unambiguous offset.
    expect(array_count_values($nodeIds)['synth'])->toBe(1)
        ->and($nodeIds)->toBe(['fan_out', 'b1', 'b2', 'synth', 'review', 'finish']);
});

test('parallel branch entries are retained inline alongside the deduped spine', function () {
    $plan = HierarchicalRoutePlan::fromArray([
        'start_at' => 'fan_out',
        'nodes' => [
            'fan_out' => ['type' => 'parallel', 'branches' => ['b1', 'b2'], 'next' => 'synth'],
            'b1' => ['type' => 'worker', 'agent' => 'B1', 'prompt' => 'b1'],
            'b2' => ['type' => 'worker', 'agent' => 'B2', 'prompt' => 'b2'],
            'synth' => ['type' => 'worker', 'agent' => 'S', 'prompt' => 's', 'next' => 'finish'],
            'finish' => ['type' => 'finish', 'output_from' => 'synth'],
        ],
    ]);

    $entries = durableEntriesFor($plan);
    $branchEntries = array_values(array_filter(
        $entries,
        static fn (array $entry): bool => ($entry['parent_parallel_node_id'] ?? null) === 'fan_out',
    ));

    expect($branchEntries)->toHaveCount(2)
        ->and(array_map(static fn (array $e): string => $e['node_id'], $branchEntries))->toBe(['b1', 'b2']);
});
