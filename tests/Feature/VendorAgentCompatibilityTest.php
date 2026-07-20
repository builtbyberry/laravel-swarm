<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Agent as SwarmAgent;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchAdvancer;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\VendorAgentRecordingPropagationPolicy;
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
 *
 * **Not exhaustive — read this before assuming a surface is covered.** These
 * tests exercise the single-agent entry point, the parallel and hierarchical
 * runners, the marker-still-works path, a mixed marker/vendor swarm, and the
 * memory propagation policy (the one breaking change). They do NOT drive a
 * vendor-only agent through the durable path
 * ({@see DurableBranchAdvancer})
 * or the streaming runners
 * ({@see StaticHierarchicalStreamRunner}),
 * whose gates were widened by the same change but need a real provider or a
 * heavier harness to fake. If you touch those gates, add coverage here rather
 * than trusting this file to have caught it.
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

it('hands a vendor-only agent to a custom memory propagation policy', function () {
    // Regression coverage for the one breaking change in this release. The
    // policy contract had to widen its agent parameter to the vendor contract,
    // because a vendor-only agent could not otherwise reach a policy at all.
    // A policy narrowing it back to the swarm marker breaks vendor agents at
    // the memory chokepoint — this test fails if that happens, rather than
    // letting it ship green.
    VendorAgentRecordingPropagationPolicy::reset();
    config()->set('swarm.memory.propagation_policy', VendorAgentRecordingPropagationPolicy::class);
    app()->forgetInstance(MemoryPropagationPolicy::class);

    app(SwarmRunner::class)->agent(new VendorOnlyResearcher)->prompt('task');

    expect(VendorAgentRecordingPropagationPolicy::$seenAgents)->not->toBeEmpty();

    // The concrete agent must arrive — not null. Passing null is how the
    // pre-fix code degraded silently instead of erroring.
    expect(VendorAgentRecordingPropagationPolicy::$seenAgents[0])
        ->toBeInstanceOf(VendorOnlyResearcher::class);
});
