<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Concerns\RemembersRunContext;
use BuiltByBerry\LaravelSwarm\Contracts\Agent as SwarmAgent;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyRememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\VendorOnlyWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlyParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\VendorOnlySequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use BuiltByBerry\LaravelSwarm\Tests\Support\VendorAgentRecordingPropagationPolicy;
use Illuminate\Support\Facades\Artisan;
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
 * Covered here: the single-agent entry point, sequential, parallel and
 * hierarchical runners, durable execution, streaming, the
 * {@see RemembersRunContext} trait, the
 * memory propagation policy (the one breaking change in this release), and the
 * marker-still-works path.
 *
 * Still uncovered: {@see StaticHierarchicalStreamRunner} — the vendor-only
 * combination of hierarchical *and* streaming. If you touch that gate, add
 * coverage here rather than trusting this file to have caught it.
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

it('streams a vendor-only swarm', function () {
    $events = iterator_to_array(VendorOnlySequentialSwarm::make()->stream('stream-task'));

    expect($events)->not->toBeEmpty();
});

it('runs a vendor-only swarm durably', function () {
    // Durable is the widest gate the widening touched and the worst place for a
    // regression: it fails on a queue worker, asynchronously, in production.
    // Durable execution requires database-backed persistence, so configure it
    // here rather than in beforeEach -- the other tests in this file are
    // deliberately driver-agnostic.
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class,
        DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    VendorOnlyResearcher::fake(['vendor-research-out']);
    VendorOnlyWriter::fake(['vendor-writer-out']);

    $runId = VendorOnlySequentialSwarm::make()->dispatchDurable('durable-task')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, 1))->handle($manager);

    expect($manager->find($runId)['status'])->toBe('completed');
});

it('passes a vendor-only agent to the policy through RemembersRunContext', function () {
    // The ONLY silent failure mode in this change. `RemembersRunContext` decides
    // what to hand the policy with `$this instanceof Agent ? $this : null` —
    // pre-fix a vendor-only agent hit the null branch, so per-agent memory
    // filtering switched itself off with no error at all. The existing trait
    // tests use RememberingWriter, which implements the swarm marker and so can
    // never reach this branch.
    VendorAgentRecordingPropagationPolicy::reset();
    config()->set('swarm.memory.propagation_policy', VendorAgentRecordingPropagationPolicy::class);
    app()->forgetInstance(MemoryPropagationPolicy::class);

    VendorOnlyRememberingWriter::fake(['remembering-out']);

    app(SwarmRunner::class)->agent(new VendorOnlyRememberingWriter)->prompt('task');

    expect(VendorAgentRecordingPropagationPolicy::$seenAgents)->not->toBeEmpty()
        ->and(VendorAgentRecordingPropagationPolicy::$seenAgents)
        ->not->toContain(null);

    expect(VendorAgentRecordingPropagationPolicy::$seenAgents[0])
        ->toBeInstanceOf(VendorOnlyRememberingWriter::class);
});
