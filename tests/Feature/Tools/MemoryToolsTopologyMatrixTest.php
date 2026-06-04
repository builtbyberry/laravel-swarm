<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalSingleWorkerSwarm;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Laravel\Ai\Tools\Request;

/**
 * The Recall and Remember tools resolve their scope id from the ambient
 * ActiveRunContext, which every topology's runner enters the same way. This
 * matrix proves a remember-then-recall round trip works identically under each
 * of the four Swarm topologies.
 */
afterEach(function () {
    ActiveRunContext::flush();
});

dataset('topologies', [
    'sequential' => FakeSequentialSwarm::class,
    'parallel' => FakeParallelSwarm::class,
    'hierarchical' => FakeHierarchicalFullSwarm::class,
    'static-hierarchical' => FakeStaticHierarchicalSingleWorkerSwarm::class,
]);

test('remember then recall round-trips under each topology', function (string $swarmClass) {
    $runId = 'run-'.md5($swarmClass);

    ActiveRunContext::enter($runId, $swarmClass, RunContext::fake(['run_id' => $runId, 'input' => 'go']));

    $stored = app(Remember::class)->handle(new Request(['key' => 'finding', 'value' => 'the answer']));
    expect($stored)->toBe('Stored [finding] in run memory.');

    // The value landed under the active run's scope id, not a guessed one.
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'finding'))->toBe('the answer');

    $recalled = app(Recall::class)->handle(new Request(['key' => 'finding']));
    expect($recalled)->toBe('finding: the answer');
})->with('topologies');

test('nested runs address their own scope id under each topology', function (string $swarmClass) {
    $outer = 'outer-'.md5($swarmClass);
    $inner = 'inner-'.md5($swarmClass);

    ActiveRunContext::enter($outer, $swarmClass, RunContext::fake(['run_id' => $outer, 'input' => 'go']));
    app(Remember::class)->handle(new Request(['key' => 'note', 'value' => 'outer-value']));

    // A nested run (an agent driving a sub-swarm) pushes its own frame.
    ActiveRunContext::enter($inner, $swarmClass, RunContext::fake(['run_id' => $inner, 'input' => 'go']));
    app(Remember::class)->handle(new Request(['key' => 'note', 'value' => 'inner-value']));

    expect(app(Recall::class)->handle(new Request(['key' => 'note'])))->toBe('note: inner-value');

    ActiveRunContext::exit();

    // Back in the outer frame the outer value is visible again.
    expect(app(Recall::class)->handle(new Request(['key' => 'note'])))->toBe('note: outer-value');
})->with('topologies');
