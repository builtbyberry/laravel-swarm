<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use Illuminate\Container\Container;

/**
 * RunContext is now a write-through facade over the SwarmMemory Run scope.
 * Two guarantees this suite locks down:
 *
 * 1. Writes through `mergeData()` and the new `ArrayAccess` surface land in
 *    both the cached `$data` array and the bound SwarmMemory store. The
 *    cache keeps the hot inter-step relay (`prompt()`, Sequential / Parallel
 *    / Hierarchical runners) array-fast; the store is the canonical view
 *    that durable resumes, cross-worker reads, and the v0.10 operator
 *    surface will rely on.
 * 2. When no SwarmMemory binding is available (e.g. POPO test setups that
 *    never boot the application), RunContext degrades to in-memory-only
 *    behaviour — no fatals, no log noise — so existing direct-construction
 *    fixtures keep working.
 */
beforeEach(function () {
    $this->app->singleton(MemoryStore::class, fn (): MemoryStore => new InMemoryMemoryStore);
    $this->app->singleton(SwarmMemory::class, DefaultSwarmMemory::class);
});

// ---------------------------------------------------------------------------
// mergeData() write-through
// ---------------------------------------------------------------------------

test('mergeData populates both the cached $data array and the SwarmMemory Run scope', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    $context->mergeData(['last_output' => 'agent-said-hello', 'steps' => 1]);

    expect($context->data)->toBe(['last_output' => 'agent-said-hello', 'steps' => 1]);
    expect($memory->get(MemoryScope::Run, 'run-1', 'last_output'))->toBe('agent-said-hello');
    expect($memory->get(MemoryScope::Run, 'run-1', 'steps'))->toBe(1);
});

test('mergeData write-through preserves the canonical store across multiple merges', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    $context->mergeData(['a' => 1]);
    $context->mergeData(['b' => 2]);
    $context->mergeData(['a' => 'updated']); // overwrite

    expect($memory->get(MemoryScope::Run, 'run-1', 'a'))->toBe('updated');
    expect($memory->get(MemoryScope::Run, 'run-1', 'b'))->toBe(2);
});

test('mergeData on one RunContext is observable through SwarmMemory by another worker resolving the same run_id', function () {
    // Simulates the durable-resume scenario: worker A merges data on a
    // RunContext, then worker B (different process / different RunContext
    // instance) resolves the same run via SwarmMemory.
    $contextA = new RunContext(runId: 'run-shared', input: 'go');
    $contextA->mergeData(['handoff' => 'value-from-worker-a']);

    $memory = $this->app->make(SwarmMemory::class);

    expect($memory->get(MemoryScope::Run, 'run-shared', 'handoff'))->toBe('value-from-worker-a');
});

// ---------------------------------------------------------------------------
// ArrayAccess — direct SwarmMemory reads/writes
// ---------------------------------------------------------------------------

test('$context[$key] reads go straight through SwarmMemory rather than the cache', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    // Pre-populate the canonical store directly (bypassing RunContext entirely
    // so the cache stays empty — proves the ArrayAccess read is not a cache lookup).
    $memory->put(MemoryScope::Run, 'run-1', 'set_by_other_worker', 'canonical');

    expect($context['set_by_other_worker'])->toBe('canonical');
    expect($context->data)->toBe([]); // cache is still empty
});

test('$context[$key] = $value writes to both SwarmMemory and the cache', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    $context['note'] = 'remember me';

    expect($memory->get(MemoryScope::Run, 'run-1', 'note'))->toBe('remember me');
    expect($context->data['note'])->toBe('remember me');
});

test('isset($context[$key]) consults SwarmMemory, not the cache', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'present', 'yes');

    $context = new RunContext(runId: 'run-1', input: 'go');

    expect(isset($context['present']))->toBeTrue();
    expect(isset($context['absent']))->toBeFalse();
});

test('unset($context[$key]) forgets from SwarmMemory and removes from the cache', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    $context['scratchpad'] = 'temp';
    expect($context->data['scratchpad'])->toBe('temp');
    expect($memory->get(MemoryScope::Run, 'run-1', 'scratchpad'))->toBe('temp');

    unset($context['scratchpad']);

    expect($context->data)->not->toHaveKey('scratchpad');
    expect($memory->get(MemoryScope::Run, 'run-1', 'scratchpad'))->toBeNull();
});

test('appending without a key throws — RunContext memory is keyed access only', function () {
    $context = new RunContext(runId: 'run-1', input: 'go');

    expect(fn () => $context[] = 'no-key')->toThrow(SwarmException::class);
});

// ---------------------------------------------------------------------------
// prompt() — cache-backed for performance; behaviour unchanged
// ---------------------------------------------------------------------------

test('prompt() reads the last_output from the cache rather than round-tripping through SwarmMemory', function () {
    $memory = $this->app->make(SwarmMemory::class);
    $context = new RunContext(runId: 'run-1', input: 'go');

    $context->mergeData(['last_output' => 'first-output']);

    // Drift the canonical store underneath the cache. prompt() must still
    // return the cached value — the cache is the authoritative read source
    // for the hot inter-step relay (Eloquent-style attribute caching).
    $memory->put(MemoryScope::Run, 'run-1', 'last_output', 'mutated-out-of-band');

    expect($context->prompt())->toBe('first-output');
});

test('prompt() falls back to input when no last_output has been written', function () {
    $context = new RunContext(runId: 'run-1', input: 'original prompt');

    expect($context->prompt())->toBe('original prompt');
});

// ---------------------------------------------------------------------------
// Null-bind tolerance — works without an application container
// ---------------------------------------------------------------------------

test('mergeData on a RunContext built without a SwarmMemory binding behaves as in-memory only', function () {
    // Replace the container with a fresh one that has nothing bound. This
    // mirrors the POPO-style fixture setup some test suites use to construct
    // RunContext without booting the framework.
    $previous = Container::getInstance();

    try {
        Container::setInstance(new Container);

        $context = new RunContext(runId: 'run-1', input: 'go');
        $context->mergeData(['k' => 'v']);

        expect($context->data['k'])->toBe('v');
        // No fatal, no log noise, no exception — degrades cleanly.
    } finally {
        Container::setInstance($previous);
    }
});

test('ArrayAccess on an unbound RunContext falls back to the cached data array', function () {
    $previous = Container::getInstance();

    try {
        Container::setInstance(new Container);

        $context = new RunContext(runId: 'run-1', input: 'go', data: ['preset' => 'value']);

        expect($context['preset'])->toBe('value');
        expect(isset($context['preset']))->toBeTrue();
        expect(isset($context['missing']))->toBeFalse();

        $context['new'] = 'written';
        expect($context->data['new'])->toBe('written');

        unset($context['preset']);
        expect($context->data)->not->toHaveKey('preset');
    } finally {
        Container::setInstance($previous);
    }
});

// ---------------------------------------------------------------------------
// Serialization unchanged — fromPayload / toQueuePayload round-trip
// ---------------------------------------------------------------------------

test('toQueuePayload includes the cached data and round-trips through fromPayload unchanged', function () {
    $context = new RunContext(runId: 'run-1', input: 'go');
    $context->mergeData(['k1' => 'v1', 'k2' => ['nested' => true]]);

    $payload = $context->toQueuePayload();
    $rehydrated = RunContext::fromPayload($payload);

    expect($rehydrated->runId)->toBe('run-1');
    expect($rehydrated->input)->toBe('go');
    expect($rehydrated->data)->toBe(['k1' => 'v1', 'k2' => ['nested' => true]]);
});

test('RunContext::fake([data: ...]) seeds the cache without requiring SwarmMemory write-through', function () {
    $previous = Container::getInstance();

    try {
        Container::setInstance(new Container);

        $context = RunContext::fake([
            'run_id' => 'fake-1',
            'input' => 'test',
            'data' => ['preloaded' => 'yes'],
        ]);

        expect($context->data['preloaded'])->toBe('yes');
        expect($context['preloaded'])->toBe('yes');
    } finally {
        Container::setInstance($previous);
    }
});
