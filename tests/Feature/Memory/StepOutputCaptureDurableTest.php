<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingParallelSwarm;
use Illuminate\Support\Facades\Artisan;

/**
 * The durable branch advancer records steps in a job worker via the same
 * SwarmStepRecorder::completed() seam as the in-process runners, so per-step
 * output capture must hold on the crash-resumable path too.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    config()->set('swarm.memory.capture_step_output', true);

    RememberingWriter::resetCaptured();
    RememberingWriter::fake(['writer-out']);
});

afterEach(function () {
    ActiveRunContext::exit();
});

test('the durable branch advancer captures step output to raw Run memory', function () {
    $runId = RememberingParallelSwarm::make()->dispatchDurable('go')->runId;
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    /** @var array<int, MemoryEntry> $entries */
    $entries = app(SwarmMemory::class)->all(MemoryScope::Run, $runId);
    $stepKeys = array_values(array_filter(
        array_map(static fn (MemoryEntry $entry): string => $entry->key, $entries),
        SwarmMemoryKeys::isStepOutput(...),
    ));

    expect($stepKeys)->not->toBeEmpty();
});
