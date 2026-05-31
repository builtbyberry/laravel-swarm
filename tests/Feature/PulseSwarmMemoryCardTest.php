<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemorySnapshotted;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Pulse\Livewire\SwarmMemory as SwarmMemoryCard;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmMemoryMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse as PulseFacade;
use Laravel\Pulse\Pulse;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('database.default', 'testing');

    PulseFacade::purge();
    PulseFacade::flush();
    app(Pulse::class)->register([
        SwarmMemoryMetrics::class => ['enabled' => true],
    ]);

    // Default cache memory store is bound by the service provider; reset run
    // ids in the FK table are not needed because we exercise the recorder via
    // raw events plus the cache store (no FK).
    config()->set('swarm.persistence.driver', 'cache');
});

test('SwarmMemoryMetrics records entry-count and write-byte aggregates per scope from MemoryWritten events', function () {
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k1', 'hello'));
    $store->put(new MemoryEntry(MemoryScope::Conversation, 'conv-1', 'k2', ['nested' => 'value']));

    PulseFacade::ingest();

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_entries')->pluck('key')->all())
        ->toContain('run')
        ->toContain('conversation');

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_bytes_total')->pluck('key')->all())
        ->toContain('run')
        ->toContain('conversation');

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_bytes_samples')->pluck('key')->all())
        ->toContain('run')
        ->toContain('conversation');
});

test('SwarmMemoryMetrics records hit and miss read totals per scope from MemoryRead events', function () {
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'present', 'value'));
    $store->get(MemoryScope::Run, 'run-1', 'present'); // hit
    $store->get(MemoryScope::Run, 'run-1', 'missing'); // miss

    PulseFacade::ingest();

    $totals = DB::table('pulse_aggregates')
        ->where('type', 'swarm_memory_read_total')
        ->where('key', 'run')
        ->count();

    expect($totals)->toBeGreaterThanOrEqual(1);

    $hitRows = DB::table('pulse_aggregates')
        ->where('type', 'swarm_memory_read_hits')
        ->where('key', 'run')
        ->count();

    // The hit aggregate was recorded for the present-key get(); the miss
    // intentionally does not record a hit. Pulse stores one row per bucket,
    // so we assert the type/key exists rather than locking the exact sum.
    expect($hitRows)->toBeGreaterThanOrEqual(1);
});

test('SwarmMemoryMetrics records snapshot byte size and entry count aggregates from MemorySnapshotted events', function () {
    event(new MemorySnapshotted(
        runId: 'run-1',
        stepIndex: 0,
        snapshotId: 'run-1:0',
        bytes: 1234,
        entryCount: 7,
    ));

    PulseFacade::ingest();

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_snapshot_count')->pluck('key')->all())
        ->toContain('snapshots');

    $bytes = (int) DB::table('pulse_aggregates')
        ->where('type', 'swarm_memory_snapshot_bytes')
        ->where('key', 'snapshots')
        ->sum('value');

    expect($bytes)->toBeGreaterThanOrEqual(1234);

    $entries = (int) DB::table('pulse_aggregates')
        ->where('type', 'swarm_memory_snapshot_entries')
        ->where('key', 'snapshots')
        ->sum('value');

    expect($entries)->toBeGreaterThanOrEqual(7);
});

test('SwarmMemoryMetrics respects swarm.pulse.memory.sample_rate = 0.0 (recorder is a no-op)', function () {
    config()->set('swarm.pulse.memory.sample_rate', 0.0);

    $store = $this->app->make(MemoryStore::class);
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'v'));
    $store->get(MemoryScope::Run, 'run-1', 'k');

    event(new MemorySnapshotted(runId: 'r', stepIndex: 0, snapshotId: 'r:0', bytes: 100, entryCount: 1));

    PulseFacade::ingest();

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_entries')->count())->toBe(0);
    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_read_total')->count())->toBe(0);
    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_snapshot_count')->count())->toBe(0);
});

test('SwarmMemoryMetrics handles MemoryWritten without bytes (third-party driver shape) without erroring', function () {
    event(new MemoryWritten(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'k',
        metadata: [],
        bytes: null,
    ));

    PulseFacade::ingest();

    // Entry count still recorded; bytes aggregates skipped because bytes was null.
    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_entries')->where('key', 'run')->count())
        ->toBeGreaterThanOrEqual(1);

    expect(DB::table('pulse_aggregates')->where('type', 'swarm_memory_bytes_total')->where('key', 'run')->count())
        ->toBe(0);
});

test('swarm.memory card renders through the registered livewire alias with per-scope and snapshot blocks', function () {
    $store = $this->app->make(MemoryStore::class);
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'value'));
    $store->get(MemoryScope::Run, 'run-1', 'k');

    event(new MemorySnapshotted(runId: 'run-1', stepIndex: 0, snapshotId: 'run-1:0', bytes: 256, entryCount: 3));

    PulseFacade::ingest();

    Livewire::withoutLazyLoading();

    Livewire::test('swarm.memory')
        ->assertSee('Swarm Memory')
        ->assertSee('Snapshots persisted')
        ->assertSee('Avg snapshot bytes')
        ->assertSee('Avg snapshot entries')
        ->assertSee('Run');
});

test('swarm.memory card resolveSnapshot derives per-scope counts, average bytes, and hit rate from raw aggregates', function () {
    $timestamp = now()->getTimestamp();

    // Run scope: 4 writes totalling 200 bytes (avg 50), 10 reads with 4 hits (40%).
    for ($i = 0; $i < 4; $i++) {
        PulseFacade::record('swarm_memory_entries', 'run', value: 1, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_memory_bytes_total', 'run', value: 50, timestamp: $timestamp)->sum();
        PulseFacade::record('swarm_memory_bytes_samples', 'run', value: 1, timestamp: $timestamp)->sum();
    }
    for ($i = 0; $i < 10; $i++) {
        PulseFacade::record('swarm_memory_read_total', 'run', value: 1, timestamp: $timestamp)->sum();
    }
    for ($i = 0; $i < 4; $i++) {
        PulseFacade::record('swarm_memory_read_hits', 'run', value: 1, timestamp: $timestamp)->sum();
    }

    // Snapshots: 2 rows totalling 500 bytes, 8 entries (avg 250 bytes, 4 entries).
    for ($i = 0; $i < 2; $i++) {
        PulseFacade::record('swarm_memory_snapshot_count', 'snapshots', value: 1, timestamp: $timestamp)->sum();
    }
    PulseFacade::record('swarm_memory_snapshot_bytes', 'snapshots', value: 500, timestamp: $timestamp)->sum();
    PulseFacade::record('swarm_memory_snapshot_entries', 'snapshots', value: 8, timestamp: $timestamp)->sum();

    PulseFacade::ingest();

    $card = new class extends SwarmMemoryCard
    {
        /** @return array{scopes: Collection<int, object>, snapshots: object} */
        public function snapshot(): array
        {
            return $this->resolveSnapshot();
        }
    };

    $result = $card->snapshot();

    $runRow = $result['scopes']->firstWhere('scope', 'run');

    expect($runRow)->not()->toBeNull()
        ->and($runRow->entries)->toBe(4)
        ->and($runRow->averageBytes)->toBe(50)
        ->and($runRow->readTotal)->toBe(10)
        ->and($runRow->hitRate)->toBe(40.0);

    expect($result['snapshots']->count)->toBe(2)
        ->and($result['snapshots']->averageBytes)->toBe(250)
        ->and($result['snapshots']->averageEntries)->toBe(4);
});
