<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderDurableSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ProviderAgent::$calls = ProviderAgent::$failures = 0;
});

function providerObservation(string $run, string $id = 'same-native'): SwarmProviderToolEvent
{
    return new SwarmProviderToolEvent($id, $run, 0, 'Agent', 'reused-item', 'search_call', 'searching', 'fixture', 42,
        ProviderToolData::capture(['secret-key' => 'secret-canary']));
}

function seedProviderRun(string $run): void
{
    app(RunHistoryStore::class)->start($run, 'Swarm', 'sequential', RunContext::from('task', $run), [], 3600);
}

function effectiveProviderEvents(string $run): array
{
    return array_values(array_filter(CausalLogView::forRun(app(StreamEventStore::class), $run)->fold(), fn ($e) => $e instanceof SwarmProviderToolEvent));
}

it('recovers an actual interrupted provider stream with only the fresh attempt effective', function () {
    ProviderAgent::$failures = 1;
    $run = (new ProviderDurableSwarm)->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($run, 0))->handle($manager);
    expect($manager->find($run)['status'])->toBe('pending')->and(ProviderAgent::$calls)->toBe(1);
    $this->travel(2)->seconds();
    Artisan::call('swarm:recover');
    (new AdvanceDurableSwarm($run, 0))->handle($manager);
    expect($manager->find($run)['status'])->toBe('completed')->and(ProviderAgent::$calls)->toBe(2);
    $effective = effectiveProviderEvents($run);
    expect($effective)->toHaveCount(2)->and($effective[0]->attemptEpoch)->toBe(1)
        ->and($effective[0]->invocationId)->toBe('invocation-2');
    $audit = CausalLogView::forRun(app(StreamEventStore::class), $run)->fold(supersession: ViewSupersession::Everything);
    $voided = array_values(array_filter($audit, fn ($e) => $e instanceof VoidedEvent && $e->event instanceof SwarmProviderToolEvent));
    expect($voided)->toHaveCount(1)->and($voided[0]->event->attemptEpoch)->toBe(0)
        ->and($voided[0]->voidType)->toBe(CausalVoidEdgeType::NodeReexecuted);
});

it('invalidates late first events after recovery and seal without harming sibling scope', function (bool $emissionEnabled) {
    seedProviderRun('late');
    $recorder = app(DurableNodeStreamRecorder::class);
    $old = $recorder->sinkFor('late', 'node', 0);
    $fresh = $recorder->sinkFor('late', 'node', 1);
    $sibling = $recorder->sinkFor('late', 'sibling', 0);
    config()->set('swarm.durable.streaming_enabled', $emissionEnabled);
    $recorder->voidPriorAttempt('late', 'node', 1, true); // No prior rows yet.
    $recorder->voidPriorAttempt('late', 'node', 1, true); // Repeat is harmless.
    $fresh(providerObservation('late'));
    $recorder->sealNodeBoundary('late', true);
    $old(providerObservation('late'));
    $old(providerObservation('late')); // Repeated delivery stays an audit observation.
    $sibling(providerObservation('late'));
    $effective = effectiveProviderEvents('late');
    expect($effective)->toHaveCount(2)->and(array_map(fn ($e) => $e->nodeId, $effective))->toBe(['node', 'sibling']);
    $audit = CausalLogView::forRun(app(StreamEventStore::class), 'late')->fold(supersession: ViewSupersession::Everything);
    expect(array_filter($audit, fn ($e) => $e instanceof VoidedEvent))->toHaveCount(2);
})->with([false, true]);

it('preserves ordinary invalidation anchors and provider attempts across cold reclamation and split seams', function (bool $split) {
    seedProviderRun('cold');
    $recorder = app(DurableNodeStreamRecorder::class);
    $old = $recorder->sinkFor('cold', 'node', 0);
    $old(new SwarmTextDelta('text-anchor', 'cold', 0, 'Agent', 'text', 1));
    $old(providerObservation('cold', 'old-provider'));
    // Simulate a rolling-deploy hot anchor that predates the storage epoch envelope.
    $row = DB::table('swarm_stream_events')->where('event_uuid', 'text-anchor')->first();
    $payload = json_decode($row->payload, true);
    unset($payload['attempt_epoch']);
    DB::table('swarm_stream_events')->where('id', $row->id)->update(['payload' => json_encode($payload)]);
    app(CausalLogStore::class)->voidNodeAttempt('cold', 'node', 0, 'retry', 3600);
    $recorder->sinkFor('cold', 'node', 1)(providerObservation('cold', 'fresh-provider'));
    $before = effectiveProviderEvents('cold');
    expect(array_map(fn ($e) => $e->id, $before))->toBe(['fresh-provider']);
    $cold = app(DatabaseColdArchiveDriver::class);
    $boundary = $split ? $row->id + 1 : DB::table('swarm_stream_events')->max('id') + 1;
    $snapshot = app(SwarmPersistenceCipher::class)->seal(json_encode(CausalLogView::forRun(app(StreamEventStore::class), 'cold')->snapshot()));
    expect($cold->graduate('cold', 0, $boundary, $snapshot))->toBeTrue();
    $cold->reclaim('cold', $boundary);
    expect(DB::table('swarm_stream_events')->where('id', '<', $boundary)->count())->toBe(0);
    $after = effectiveProviderEvents('cold');
    expect(array_map(fn ($e) => $e->toArray(), $after))->toBe(array_map(fn ($e) => $e->toArray(), $before));
    $raw = DB::table('swarm_cold_archives')->pluck('payload')->implode('');
    expect($raw)->not->toContain('secret-canary', 'secret-key');
    $restored = array_map(fn ($e) => SwarmStreamEvent::fromArray($e), $cold->readSnapshotStrict('cold', app(SwarmPersistenceCipher::class))['events']);
    expect(array_values(array_filter((new CausalLogView($restored))->fold(), fn ($e) => $e instanceof SwarmProviderToolEvent)))->toHaveCount(1);
})->with([false, true]);

it('keeps invalidation watermarks effective after they cross the cold seam', function () {
    seedProviderRun('watermark');
    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->voidPriorAttempt('watermark', 'node', 2, true);
    $cold = app(DatabaseColdArchiveDriver::class);
    $boundary = DB::table('swarm_stream_events')->max('id') + 1;
    $cold->graduate('watermark', 0, $boundary, '{}');
    $cold->reclaim('watermark', $boundary);
    $recorder->sinkFor('watermark', 'node', 1)(providerObservation('watermark'));
    $recorder->sinkFor('watermark', 'node', 2)(providerObservation('watermark'));
    expect(effectiveProviderEvents('watermark'))->toHaveCount(1)
        ->and(effectiveProviderEvents('watermark')[0]->attemptEpoch)->toBe(2);
});

it('addresses direct voids by scoped causal identity while retaining native IDs and duplicates', function () {
    seedProviderRun('scope');
    $recorder = app(DurableNodeStreamRecorder::class);
    $one = providerObservation('scope');
    $two = providerObservation('scope');
    $recorder->sinkFor('scope', 'one', 0)($one);
    $recorder->sinkFor('scope', 'two', 0)($two);
    $recorder->sinkFor('scope', 'two', 0)(providerObservation('scope'));
    app(CausalLogStore::class)->appendVoidEdge('scope', CausalVoidEdgeType::Supersedes, $one->causalId(), 'superseded');
    $effective = effectiveProviderEvents('scope');
    expect($effective)->toHaveCount(2)->and($effective[0]->id)->toBe('same-native')
        ->and($effective[0]->nodeId)->toBe('two')->and($effective[1]->nodeId)->toBe('two');
});
