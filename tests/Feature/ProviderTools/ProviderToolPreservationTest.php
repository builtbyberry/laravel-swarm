<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderGeneratedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\ProviderTools\Fixtures\ProviderSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('concurrency.default', 'sync');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ProviderAgent::$calls = ProviderAgent::$failures = 0;
});

it('preserves live completed memory and persisted provider events across capture and stores', function (CaptureDecision $decision, string $driver) {
    config()->set('swarm.streaming.replay.driver', $driver);
    config()->set('swarm.persistence.encrypt_at_rest', true);
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision, activeContext: CaptureDecision::Full));
    $stream = (new ProviderSwarm)->stream('task');
    expect(ProviderAgent::$calls)->toBe(0);
    $events = collect(iterator_to_array($stream));
    $tools = $events->whereInstanceOf(SwarmProviderToolEvent::class)->values();
    expect($tools)->toHaveCount(2)->and($tools->pluck('id')->all())->toBe(['native-searching', 'native-denied'])
        ->and($tools->pluck('providerStatus')->all())->toBe(['searching', 'denied'])
        ->and($tools[0]->invocationId)->toBe('invocation-1')->and($tools[0]->timestamp)->toBe(1710000001)
        ->and($tools[0]->payload->status)->toBe(match ($decision) {
            CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted'
        });
    $wire = $events->map->toArray()->all();
    expect($stream->streamedResponse->events->map->toArray()->all())->toBe($wire)
        ->and(collect(iterator_to_array($stream))->map->toArray()->all())->toBe($wire)
        ->and(collect(app(StreamEventStore::class)->events($stream->runId))->map->toArray()->all())->toBe($wire)
        ->and(ProviderAgent::$calls)->toBe(1);
    if ($decision !== CaptureDecision::Full) {
        expect(json_encode($wire))->not->toContain('canary-secret', 'secret-key');
    }
    $raw = DB::table('swarm_stream_events')->where('run_id', $stream->runId)->pluck('payload')->implode('');
    expect($raw)->not->toContain('canary-secret', 'secret-key');
    expect(json_encode(app(RunHistoryStore::class)->find($stream->runId)))->not->toContain('canary-secret', 'secret-key');
})->with(CaptureDecision::cases())->with(['database', 'cache']);

it('maps static and generated hierarchical workers with independent step budgets', function (string $kind) {
    $plan = (new ProviderStaticSwarm)->plan();
    FakeHierarchicalCoordinator::fake([$plan]);
    $swarm = $kind === 'static' ? new ProviderStaticSwarm : new ProviderGeneratedSwarm;
    config()->set('swarm.provider_tools.max_step_bytes', 200);
    $stream = $swarm->stream('task');
    $events = collect(iterator_to_array($stream))->whereInstanceOf(SwarmProviderToolEvent::class)->values();
    expect($events)->toHaveCount(4)->and($events->pluck('nodeId')->all())->toBe(['first', 'first', 'second', 'second'])
        ->and($events->map(fn ($e) => $e->payload->status)->all())->toBe(['available', 'partial', 'available', 'partial'])
        ->and($events[0]->causalId())->not->toBe($events[2]->causalId());
})->with(['static', 'generated']);

it('broadcasts provider wire events through each helper with capture applied', function (CaptureDecision $decision, string $method) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision, activeContext: CaptureDecision::Full));
    Event::fake([AnonymousEvent::class]);
    config()->set('queue.default', 'sync');
    $response = (new ProviderSwarm)->{$method}('task', new Channel('provider-test'));
    unset($response); // Pending queued dispatch occurs on destruction.
    $wire = Event::dispatched(AnonymousEvent::class)->map(fn ($event) => $event[0]->broadcastWith());
    $tools = $wire->where('type', 'swarm_provider_tool_event')->values();
    expect($tools)->toHaveCount(2)->and($tools->pluck('id')->all())->toBe(['native-searching', 'native-denied'])
        ->and($tools[1]['provider_status'])->toBe('denied')->and($tools[0]['invocation_id'])->toBe('invocation-1')
        ->and(ProviderAgent::$calls)->toBe(1);
    if ($decision !== CaptureDecision::Full) {
        expect(json_encode($wire))->not->toContain('canary-secret', 'secret-key');
    }
})->with(CaptureDecision::cases())->with(['broadcast', 'broadcastNow', 'broadcastOnQueue']);

it('does not fabricate provider activity on nonstream or fake responses', function () {
    $response = (new ProviderSwarm)->prompt('task');
    expect(ProviderAgent::$calls)->toBe(0)->and($response->toArray())->not->toHaveKey('provider_tools');
    ProviderSwarm::fake(['fake output']);
    $stream = ProviderSwarm::make()->stream('task');
    ProviderSwarm::assertNeverStreamed();
    expect(collect(iterator_to_array($stream))->whereInstanceOf(SwarmProviderToolEvent::class))->toBeEmpty();
    ProviderSwarm::assertStreamed('task');
});

it('does not invent completed playback after interruption', function () {
    ProviderAgent::$failures = 1;
    $stream = (new ProviderSwarm)->stream('task');
    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'fixture interruption');
    expect($stream->streamedResponse)->toBeNull()
        ->and($stream->events->whereInstanceOf(SwarmProviderToolEvent::class))->toHaveCount(1);
    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'fixture interruption');
    expect(ProviderAgent::$calls)->toBe(1);
});

it('reports unavailable protected evidence on key loss without leaking ciphertext', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $stream = (new ProviderSwarm)->stream('task');
    iterator_to_array($stream);
    app()->instance(SwarmPersistenceCipher::class, new SwarmPersistenceCipher(app('config'), new Encrypter(random_bytes(32), 'AES-256-CBC'), app('log')));
    app()->forgetInstance(DatabaseCausalLogStore::class);
    app()->forgetInstance(StreamEventStore::class);
    app()->forgetInstance(TieredStreamEventStore::class);
    $event = collect(app(StreamEventStore::class)->events($stream->runId))->whereInstanceOf(SwarmProviderToolEvent::class)->first();
    expect($event->payload->status)->toBe('unavailable')->and($event->payload->reasons)->toBe(['decrypt_failed'])
        ->and(json_encode($event->toArray()))->not->toContain('canary-secret', 'sw0:');
});

it('cleans partial provider replay on continue and fails closed by default', function (string $policy) {
    config()->set('swarm.streaming.replay.failure_policy', $policy);
    $store = new class implements StreamEventStore
    {
        public array $recorded = [];

        public bool $forgotten = false;

        public function assertReady(): void {}

        public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
        {
            if ($event instanceof SwarmProviderToolEvent && $event->providerStatus === 'denied') {
                throw new RuntimeException('fixture replay failure');
            }
            $this->recorded[] = $event;
        }

        public function forget(string $runId): void
        {
            $this->recorded = [];
            $this->forgotten = true;
        }

        public function events(string $runId): iterable
        {
            yield from $this->recorded;
        }
    };
    app()->instance(StreamEventStore::class, $store);
    $stream = (new ProviderSwarm)->stream('task');
    if ($policy === 'continue') {
        iterator_to_array($stream);
        expect($stream->streamedResponse)->not->toBeNull()->and($store->recorded)->toBe([])->and($store->forgotten)->toBeTrue();
    } else {
        expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'fixture replay failure');
        expect($stream->streamedResponse)->toBeNull()->and($store->forgotten)->toBeFalse();
    }
    expect(ProviderAgent::$calls)->toBe(1);
})->with(['fail', 'continue']);

it('honors default output capture off and keeps telemetry free of provider payload', function () {
    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    config()->set('swarm.capture.outputs', false);
    $stream = (new ProviderSwarm)->stream('task');
    $events = collect(iterator_to_array($stream))->whereInstanceOf(SwarmProviderToolEvent::class);
    $telemetry = $sink->recordsForCategory('stream.event');
    expect(array_column($telemetry, 'event_type'))->toContain('swarm_provider_tool_event')
        ->and(json_encode($telemetry))->not->toContain('secret-key', 'canary-secret');
    expect($events->first()->payload->status)->toBe('redacted')
        ->and(json_encode($events->map->toArray()->all()))->not->toContain('secret-key', 'canary-secret');
});

it('retains cold audit history until explicit forget after pruning hot history', function () {
    $stream = (new ProviderSwarm)->stream('task');
    iterator_to_array($stream);
    $cold = app(DatabaseColdArchiveDriver::class);
    $boundary = DB::table('swarm_stream_events')->max('id') + 1;
    $cold->graduate($stream->runId, 0, $boundary, '{}');
    $cold->reclaim($stream->runId, $boundary);
    DB::table('swarm_run_histories')->where('run_id', $stream->runId)->update(['expires_at' => now()->subDay()]);
    Artisan::call('swarm:prune');
    expect(DB::table('swarm_stream_events')->where('run_id', $stream->runId)->count())->toBe(0)
        ->and(DB::table('swarm_run_histories')->where('run_id', $stream->runId)->count())->toBe(0)
        ->and(DB::table('swarm_cold_archives')->where('run_id', $stream->runId)->count())->toBeGreaterThan(0);
    app(StreamEventStore::class)->forget($stream->runId);
    expect(DB::table('swarm_cold_archives')->where('run_id', $stream->runId)->count())->toBe(0);
});
