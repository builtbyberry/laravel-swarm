<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SoloDurableStreamingSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * F2 — the live stream() loop and the durable per-node stream both fold provider
 * events through the single shared StreamEventMapper, so they must emit a
 * byte-identical body event sequence for the same agent. This locks the extraction:
 * if a future edit makes the two paths diverge, this fails.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('live stream() and durable per-node streaming emit an identical body event sequence (F2)', function () {
    $bodyTypes = ['swarm_text_delta', 'swarm_text_end'];

    // Durable path: dispatch + advance, then read the body events the per-node sink
    // appended to the causal log (node_id/epoch stamps and the seal barrier ignored).
    $runId = SoloDurableStreamingSwarm::make()->dispatchDurable('determinism-task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));

    $durableBody = DB::table('swarm_stream_events')
        ->where('run_id', $runId)
        ->whereIn('event_type', $bodyTypes)
        ->orderBy('id')
        ->get()
        ->map(fn ($row): array => [$row->event_type, $row->event_uuid])
        ->all();

    // Live path: the same swarm streamed in-process; keep only the per-step body
    // events (drop the SwarmStepStart/SwarmStepEnd lifecycle frames).
    $liveBody = collect(iterator_to_array(SoloDurableStreamingSwarm::make()->stream('determinism-task')))
        ->filter(fn ($event): bool => in_array($event->type(), ['swarm_text_delta', 'swarm_text_end'], true))
        ->map(fn ($event): array => [$event->type(), $event->id])
        ->values()
        ->all();

    expect($liveBody)->not->toBeEmpty()
        ->and($durableBody)->toBe($liveBody);
});
