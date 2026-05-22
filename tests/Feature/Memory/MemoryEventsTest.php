<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryForgotten;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Memory\CacheMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle event dispatch tests for the bundled memory stores.
 *
 * Events fire at the store layer (not at the SwarmMemory facade) so any path
 * that reaches a store — including direct store resolution and future
 * companion drivers — emits the same lifecycle stream.
 */
beforeEach(function () {
    /** @var Factory $cacheFactory */
    $cacheFactory = $this->app->make('cache');
    $cacheFactory->store('array')->flush();
});

// ---------------------------------------------------------------------------
// CacheMemoryStore
// ---------------------------------------------------------------------------

test('cache store dispatches MemoryWritten on put with scope, scope_id, key, metadata', function () {
    Event::fake([MemoryWritten::class, MemoryRead::class, MemoryForgotten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);
    expect($store)->toBeInstanceOf(CacheMemoryStore::class);

    $store->put(new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'last_output',
        value: 'agent A said hello',
        metadata: ['source' => 'test', 'redacted' => false],
    ));

    Event::assertDispatched(MemoryWritten::class, fn (MemoryWritten $event): bool => $event->scope === MemoryScope::Run
        && $event->scopeId === 'run-1'
        && $event->key === 'last_output'
        && $event->metadata === ['source' => 'test', 'redacted' => false]);

    Event::assertNotDispatched(MemoryRead::class);
    Event::assertNotDispatched(MemoryForgotten::class);
});

test('cache store dispatches MemoryRead on get whether or not the entry exists', function () {
    Event::fake([MemoryRead::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->get(MemoryScope::Run, 'run-1', 'missing');

    Event::assertDispatched(MemoryRead::class, fn (MemoryRead $event): bool => $event->scope === MemoryScope::Run
        && $event->scopeId === 'run-1'
        && $event->key === 'missing');

    Event::assertDispatchedTimes(MemoryRead::class, 1);

    $store->put(new MemoryEntry(MemoryScope::Conversation, 'conv-1', 'present', 'value'));
    $store->get(MemoryScope::Conversation, 'conv-1', 'present');

    Event::assertDispatchedTimes(MemoryRead::class, 2);
    Event::assertDispatched(MemoryRead::class, fn (MemoryRead $event): bool => $event->scope === MemoryScope::Conversation
        && $event->scopeId === 'conv-1'
        && $event->key === 'present');
});

test('cache store dispatches MemoryForgotten on forget whether or not the entry existed', function () {
    Event::fake([MemoryForgotten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->forget(MemoryScope::Run, 'run-1', 'absent');

    Event::assertDispatched(MemoryForgotten::class, fn (MemoryForgotten $event): bool => $event->scope === MemoryScope::Run
        && $event->scopeId === 'run-1'
        && $event->key === 'absent'
        && $event->existed === false);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'present', 'x'));
    $store->forget(MemoryScope::Run, 'run-1', 'present');

    Event::assertDispatchedTimes(MemoryForgotten::class, 2);
    Event::assertDispatched(MemoryForgotten::class, fn (MemoryForgotten $event): bool => $event->scope === MemoryScope::Run
        && $event->scopeId === 'run-1'
        && $event->key === 'present'
        && $event->existed === true);
});

test('cache store MemoryForgotten exposes existed flag distinguishing real deletions from no-op probes', function () {
    Event::fake([MemoryForgotten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    // No-op probe — nothing at the address.
    $store->forget(MemoryScope::Run, 'run-1', 'never-was');

    // Real deletion — write, then forget.
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'real', 'value'));
    $store->forget(MemoryScope::Run, 'run-1', 'real');

    Event::assertDispatchedTimes(MemoryForgotten::class, 2);
    Event::assertDispatched(
        MemoryForgotten::class,
        fn (MemoryForgotten $event): bool => $event->key === 'never-was' && $event->existed === false,
    );
    Event::assertDispatched(
        MemoryForgotten::class,
        fn (MemoryForgotten $event): bool => $event->key === 'real' && $event->existed === true,
    );
});

test('cache store all() does not emit MemoryRead per entry', function () {
    Event::fake([MemoryRead::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'a', 1));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'b', 2));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'c', 3));

    $entries = $store->all(MemoryScope::Run, 'run-1');

    expect($entries)->toHaveCount(3);
    Event::assertNotDispatched(MemoryRead::class);
});

test('cache store emits one event per operation (no extras for repeated puts)', function () {
    Event::fake([MemoryWritten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'first'));
    $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'k', 'second'));

    Event::assertDispatchedTimes(MemoryWritten::class, 2);
});

// ---------------------------------------------------------------------------
// DatabaseMemoryStore
// ---------------------------------------------------------------------------

function makeSwarmMemoriesTable(): void
{
    if (Schema::hasTable('swarm_memories')) {
        return;
    }

    Schema::create('swarm_memories', function (Blueprint $table): void {
        $table->id();
        $table->string('scope');
        $table->string('scope_id');
        // run_id mirrors the production schema added by
        // 2026_05_21_000003_add_run_id_to_swarm_memories_table. No FK here —
        // these synthetic tests don't exercise the cascade; that's covered by
        // MemoryRunIdCascadeTest against the real migration stack.
        $table->string('run_id')->nullable();
        $table->string('key');
        $table->json('value')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamp('created_at')->useCurrent();
        $table->timestamp('updated_at')->useCurrent();
        $table->unique(['scope', 'scope_id', 'key']);
    });
}

function seedSwarmRunHistory(string $runId): void
{
    // Seed a parent swarm_run_histories row so Run-scoped memory puts pass the
    // run_id FK added by 2026_05_21_000003. Only required when the real
    // (migrated) memories table is in play.
    if (DB::table('swarm_run_histories')->where('run_id', $runId)->exists()) {
        return;
    }

    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => 'ExampleSwarm',
        'topology' => 'sequential',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('database store dispatches MemoryWritten on put with metadata payload', function () {
    config()->set('swarm.persistence.driver', 'database');
    makeSwarmMemoriesTable();

    Event::fake([MemoryWritten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);
    expect($store)->toBeInstanceOf(DatabaseMemoryStore::class);

    $store->put(new MemoryEntry(
        scope: MemoryScope::Agent,
        scopeId: 'AgentX',
        key: 'pref',
        value: ['mode' => 'verbose'],
        metadata: ['source' => 'unit-test'],
    ));

    Event::assertDispatched(MemoryWritten::class, fn (MemoryWritten $event): bool => $event->scope === MemoryScope::Agent
        && $event->scopeId === 'AgentX'
        && $event->key === 'pref'
        && $event->metadata === ['source' => 'unit-test']);
});

test('database store dispatches MemoryRead on get whether the row exists or not', function () {
    config()->set('swarm.persistence.driver', 'database');
    makeSwarmMemoriesTable();
    seedSwarmRunHistory('r1');

    Event::fake([MemoryRead::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->get(MemoryScope::Run, 'r1', 'absent');
    $store->put(new MemoryEntry(MemoryScope::Run, 'r1', 'present', 'v'));
    $store->get(MemoryScope::Run, 'r1', 'present');

    Event::assertDispatchedTimes(MemoryRead::class, 2);
    Event::assertDispatched(MemoryRead::class, fn (MemoryRead $e): bool => $e->scope === MemoryScope::Run && $e->key === 'absent');
    Event::assertDispatched(MemoryRead::class, fn (MemoryRead $e): bool => $e->scope === MemoryScope::Run && $e->key === 'present');
});

test('database store dispatches MemoryForgotten on forget whether row existed or not', function () {
    config()->set('swarm.persistence.driver', 'database');
    makeSwarmMemoriesTable();

    Event::fake([MemoryForgotten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    $store->forget(MemoryScope::Swarm, 'SwarmA', 'absent');
    $store->put(new MemoryEntry(MemoryScope::Swarm, 'SwarmA', 'present', 'v'));
    $store->forget(MemoryScope::Swarm, 'SwarmA', 'present');

    Event::assertDispatchedTimes(MemoryForgotten::class, 2);
    Event::assertDispatched(MemoryForgotten::class, fn (MemoryForgotten $e): bool => $e->key === 'absent' && $e->scope === MemoryScope::Swarm && $e->existed === false);
    Event::assertDispatched(MemoryForgotten::class, fn (MemoryForgotten $e): bool => $e->key === 'present' && $e->scope === MemoryScope::Swarm && $e->existed === true);
});

test('database store MemoryForgotten exposes existed flag distinguishing real deletions from no-op probes', function () {
    config()->set('swarm.persistence.driver', 'database');
    makeSwarmMemoriesTable();

    Event::fake([MemoryForgotten::class]);

    /** @var MemoryStore $store */
    $store = $this->app->make(MemoryStore::class);

    // No-op probe — no row at the address.
    $store->forget(MemoryScope::Swarm, 'SwarmB', 'never-was');

    // Real deletion — insert, then delete.
    $store->put(new MemoryEntry(MemoryScope::Swarm, 'SwarmB', 'real', 'v'));
    $store->forget(MemoryScope::Swarm, 'SwarmB', 'real');

    Event::assertDispatchedTimes(MemoryForgotten::class, 2);
    Event::assertDispatched(
        MemoryForgotten::class,
        fn (MemoryForgotten $e): bool => $e->key === 'never-was' && $e->existed === false,
    );
    Event::assertDispatched(
        MemoryForgotten::class,
        fn (MemoryForgotten $e): bool => $e->key === 'real' && $e->existed === true,
    );
});

// ---------------------------------------------------------------------------
// Facade pass-through — events fire from the store layer even when invoked
// via the SwarmMemory facade.
// ---------------------------------------------------------------------------

test('events fire when memory is accessed via the SwarmMemory facade', function () {
    Event::fake([MemoryWritten::class, MemoryRead::class, MemoryForgotten::class]);

    /** @var SwarmMemory $memory */
    $memory = $this->app->make(SwarmMemory::class);

    $memory->put(MemoryScope::Run, 'run-facade', 'k', 'v', ['m' => 1]);
    $memory->get(MemoryScope::Run, 'run-facade', 'k');
    $memory->forget(MemoryScope::Run, 'run-facade', 'k');

    Event::assertDispatched(MemoryWritten::class, fn (MemoryWritten $e): bool => $e->key === 'k' && $e->metadata === ['m' => 1]);
    Event::assertDispatched(MemoryRead::class, fn (MemoryRead $e): bool => $e->key === 'k');
    Event::assertDispatched(MemoryForgotten::class, fn (MemoryForgotten $e): bool => $e->key === 'k');
});
