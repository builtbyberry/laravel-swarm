<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\PreliminaryDurableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\PreliminaryParallelDurableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\PreliminaryRetryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.partial-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'partial-test');
    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class, SnapshotsMemory::class, StreamEventStore::class] as $service) {
        app()->forgetInstance($service);
    }
    PreliminaryRetryAgent::$attempts = 0;
    PreliminaryResultAgent::$calls = 0;
});

it('recovers partial streams with current final snapshots and retains committed sibling work', function (bool $parallel) {
    $swarm = $parallel ? PreliminaryParallelDurableSwarm::make() : PreliminaryDurableSwarm::make();
    $runId = $swarm->dispatchDurable('ordinary')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    if ($parallel) {
        (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);
        (new AdvanceDurableBranch($runId, 'parallel:1'))->handle($manager);
    }
    expect(PreliminaryRetryAgent::$attempts)->toBe(1);
    $this->travel(61)->seconds();
    Artisan::call('swarm:recover');
    if ($parallel) {
        (new AdvanceDurableBranch($runId, 'parallel:0'))->handle($manager);
        (new AdvanceDurableSwarm($runId, 2))->handle($manager);
    } else {
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    }
    expect($manager->find($runId)['status'])->toBe('completed')
        ->and(PreliminaryRetryAgent::$attempts)->toBe(2)
        ->and(PreliminaryResultAgent::$calls)->toBe($parallel ? 3 : 2);
    foreach (range(0, $parallel ? 1 : 0) as $index) {
        $snapshot = app(SnapshotsMemory::class)->find($runId, $index);
        expect($snapshot->toolCalls)->toHaveCount(2)
            ->and(array_column($snapshot->toolCalls, 'result', 'id'))->toBe(['b' => 'complete-secret-b', 'a' => 'complete-secret-a']);
    }
    $events = collect(CausalLogView::forRun(app(StreamEventStore::class), $runId)->fold())->whereInstanceOf(SwarmToolResult::class);
    expect($events)->toHaveCount($parallel ? 22 : 11);
    foreach ($events->groupBy('nodeId') as $node => $results) {
        expect($results->pluck('attemptEpoch')->unique()->values()->all())->toBe([$node === 'parallel:0' ? 2 : 1])
            ->and($results->where('preliminary', true))->toHaveCount(8)
            ->and($results->where('preliminary', false))->toHaveCount(3);
    }
})->with([false, true]);
