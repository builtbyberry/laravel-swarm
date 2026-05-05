<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class SwarmHealthRecordingCacheStore extends ArrayStore
{
    /** @var array<string, bool> */
    public static array $keys = [];

    public function put($key, $value, $seconds): bool
    {
        self::$keys[(string) $key] = true;

        return parent::put($key, $value, $seconds);
    }

    public function forget($key): bool
    {
        unset(self::$keys[(string) $key]);

        return parent::forget($key);
    }
}

class SwarmHealthFailingCacheStore extends ArrayStore
{
    public function put($key, $value, $seconds): bool
    {
        return false;
    }
}

beforeEach(function (): void {
    Cache::extend('swarm-health-recording', fn (): Repository => new Repository(new SwarmHealthRecordingCacheStore));
    Cache::extend('swarm-health-failing', fn (): Repository => new Repository(new SwarmHealthFailingCacheStore));

    config()->set('cache.stores.swarm-health-recording', ['driver' => 'swarm-health-recording']);
    config()->set('cache.stores.swarm-health-failing', ['driver' => 'swarm-health-failing']);

    SwarmHealthRecordingCacheStore::$keys = [];
});

test('cache backed persistence stores assert readiness and remove probe keys', function (): void {
    config()->set('swarm.context.store', 'swarm-health-recording');
    config()->set('swarm.artifacts.store', 'swarm-health-recording');
    config()->set('swarm.history.store', 'swarm-health-recording');
    config()->set('swarm.streaming.replay.store', 'swarm-health-recording');

    app(CacheContextStore::class)->assertReady();
    app(CacheArtifactRepository::class)->assertReady();
    app(CacheRunHistoryStore::class)->assertReady();
    app(CacheStreamEventStore::class)->assertReady();

    expect(SwarmHealthRecordingCacheStore::$keys)->toBe([]);
});

test('cache backed persistence readiness fails clearly when a cache write fails', function (): void {
    config()->set('swarm.context.store', 'swarm-health-failing');

    expect(fn () => app(CacheContextStore::class)->assertReady())
        ->toThrow(SwarmException::class, 'Laravel Swarm [context] cache store [swarm-health-failing] failed to write readiness probe.');
});

test('database stream event store asserts readiness', function (): void {
    app(DatabaseStreamEventStore::class)->assertReady();

    config()->set('swarm.tables.stream_events', 'missing_swarm_stream_events');

    expect(fn () => app(DatabaseStreamEventStore::class)->assertReady())
        ->toThrow(SwarmException::class, 'Database-backed stream replay requires the [missing_swarm_stream_events] table.');
});

test('swarm health passes for cache stores without durable readiness', function (): void {
    config()->set('swarm.tables.durable', 'missing_swarm_durable_runs');

    expect(Artisan::call('swarm:health'))->toBe(0);
    expect(Artisan::output())->toContain('Context');
    expect(Artisan::output())->not->toContain('Durable runtime');
});

test('swarm health durable option verifies durable database readiness', function (): void {
    expect(Artisan::call('swarm:health', ['--durable' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Durable runtime');

    config()->set('swarm.tables.durable', 'missing_swarm_durable_runs');

    expect(Artisan::call('swarm:health', ['--durable' => true]))->toBe(1);
    expect(Artisan::output())->toContain('missing_swarm_durable_runs');
});

test('swarm health json output is structured', function (): void {
    expect(Artisan::call('swarm:health', ['--json' => true]))->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload)
        ->toBeArray()
        ->and($payload['ok'])->toBeTrue()
        ->and($payload['checks'])->toHaveCount(4)
        ->and($payload['checks'][0])->toHaveKeys(['component', 'driver', 'store', 'status', 'details']);
});

test('swarm health identifies failing cache component', function (): void {
    config()->set('swarm.context.store', 'swarm-health-failing');
    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(StreamEventStore::class);

    expect(Artisan::call('swarm:health'))->toBe(1);
    expect(Artisan::output())
        ->toContain('Context')
        ->toContain('swarm-health-failing')
        ->toContain('failed to write readiness probe');
});
