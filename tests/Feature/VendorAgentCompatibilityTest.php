<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Agent as SwarmAgent;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Laravel\Ai\Contracts\Agent as LaravelAiAgent;

/**
 * Regression coverage for the drop-in compatibility contract: a plain
 * `laravel/ai` agent must run through Swarm unchanged.
 *
 * Before v0.23.0 every public entry point and runner gate type-hinted the
 * swarm-owned marker interface. Because interface inheritance runs one way, a
 * class implementing only the vendor contract was not an instance of that
 * marker, so Swarm rejected it outright — contradicting the documented
 * "drop in unchanged" promise. Nothing asserted the boundary in either
 * direction, which is why it went unnoticed.
 */
beforeEach(function () {
    VendorOnlyResearcher::fake(['vendor-research-out']);
    VendorOnlyWriter::fake(['vendor-writer-out']);
    FakeWriter::fake(['writer-out']);
    VendorOnlyCoordinator::fake([
        HierarchicalTestPlan::make('writer_node', [
            'writer_node' => [
                'type' => 'worker',
                'agent' => VendorOnlyWriter::class,
                'prompt' => 'writer-task',
            ],
        ]),
    ]);
});

it('confirms a vendor-only agent is NOT an instance of the swarm marker', function () {
    $agent = new VendorOnlyResearcher;

    // The asymmetry that caused the bug: the swarm marker extends the vendor
    // contract, so this direction holds...
    expect($agent)->toBeInstanceOf(LaravelAiAgent::class);

    // ...but the reverse never did, and cannot. Swarm must therefore type-hint
    // the vendor contract, not the marker. If this assertion ever flips, the
    // marker has been changed in a way that invalidates the whole fix.
    expect($agent)->not->toBeInstanceOf(SwarmAgent::class);
});

it('accepts a vendor-only agent at the single-agent entry point', function () {
    $response = app(SwarmRunner::class)
        ->agent(new VendorOnlyResearcher)
        ->prompt('task');

    expect((string) $response)->toBe('vendor-research-out');
});

it('runs vendor-only agents through the parallel runner', function () {
    $response = (new VendorOnlyParallelSwarm)->prompt('task');

    expect($response->steps)->toHaveCount(2)
        ->and((string) $response)->toContain('vendor-research-out')
        ->and((string) $response)->toContain('vendor-writer-out');
});

it('runs a vendor-only coordinator and worker through the hierarchical runner', function () {
    $response = (new VendorOnlyHierarchicalSwarm)->prompt('task');

    expect((string) $response)->toBe('vendor-writer-out');
});

it('still accepts agents implementing the deprecated swarm marker', function () {
    // The marker remains a valid (deprecated) alias — widening the boundary to
    // the vendor contract must not break the classes written against it since
    // v0.5.0.
    $agent = new FakeWriter;

    expect($agent)->toBeInstanceOf(SwarmAgent::class)
        ->and($agent)->toBeInstanceOf(LaravelAiAgent::class);

    $response = app(SwarmRunner::class)->agent($agent)->prompt('task');

    expect((string) $response)->toBe('writer-out');
});

it('mixes vendor-only and marker agents in one swarm', function () {
    $response = app(SwarmRunner::class)
        ->sequential([new VendorOnlyResearcher, new FakeWriter])
        ->prompt('task');

    expect((string) $response)->toBe('writer-out');
});
