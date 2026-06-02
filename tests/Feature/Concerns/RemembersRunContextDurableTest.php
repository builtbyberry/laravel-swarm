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
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingParallelSwarm;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Messages\Message;

/**
 * The durable branch advancer runs an agent in a job worker. It must publish
 * the active run there too, so a RemembersRunContext agent sees run memory on a
 * durable (crash-resumable) execution path.
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

    RememberingWriter::resetCaptured();
    RememberingWriter::fake(['writer-out']);
});

afterEach(function () {
    ActiveRunContext::exit();
});

test('the durable branch advancer exposes run memory to the agent', function () {
    $runId = RememberingParallelSwarm::make()->dispatchDurable('go')->runId;
    $manager = app(DurableSwarmManager::class);

    // Seed Run-scoped memory the worker should see (persists in the DB store).
    app(SwarmMemory::class)->put(MemoryScope::Run, $runId, 'brief', 'durable secret');

    (new AdvanceDurableSwarm($runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }

    expect(RememberingWriter::$capturedMessages)->not->toBeEmpty();
    $contents = array_map(static fn (Message $m): ?string => $m->content, RememberingWriter::$capturedMessages[0]);
    expect($contents)->toContain('brief: durable secret');
    expect(ActiveRunContext::current())->toBeNull();
});
