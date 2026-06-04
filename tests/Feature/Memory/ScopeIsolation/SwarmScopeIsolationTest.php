<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;

/**
 * Swarm scope is shared across all agents in a swarm class, addressed by the
 * swarm's class string. Two swarm classes never share an entry, and a running
 * swarm only ever gathers its own Swarm-scoped memory.
 */
pest()->group('compliance');

beforeEach(function () {
    FakeResearcher::fake(fn (): string => 'research-out');

    $this->recorder = new RecordingSnapshotsMemory;
    $this->app->instance(SnapshotsMemory::class, $this->recorder);
});

test('swarm-scoped memory is keyed by swarm class string', function () {
    $memory = app(SwarmMemory::class);

    $memory->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared-note', 'wide-value');
    $memory->put(MemoryScope::Swarm, FakeSequentialSwarm::class, 'shared-note', 'sequential-value');

    expect($memory->get(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared-note'))->toBe('wide-value')
        ->and($memory->get(MemoryScope::Swarm, FakeSequentialSwarm::class, 'shared-note'))->toBe('sequential-value');

    $values = array_map(
        static fn (MemoryEntry $entry): mixed => $entry->value,
        $memory->all(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class),
    );

    expect($values)->toBe(['wide-value']);
});

test('a wide-view swarm surfaces only its own Swarm-scoped entries', function () {
    // Both swarm classes carry a Swarm-scoped note; the running swarm sees only
    // the entry addressed to its own class.
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeWideViewPropagationSwarm::class, 'shared-note', 'own-value');
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeSequentialSwarm::class, 'shared-note', 'other-value');

    FakeWideViewPropagationSwarm::make()->run(RunContext::from('task', 'swarm-iso-run'));

    $swarmScoped = array_filter(
        capturedEntries($this->recorder),
        static fn (MemoryEntry $entry): bool => $entry->scope === MemoryScope::Swarm,
    );

    expect($swarmScoped)->not->toBeEmpty();
    foreach ($swarmScoped as $entry) {
        expect($entry->scopeId)->toBe(FakeWideViewPropagationSwarm::class)
            ->and($entry->value)->not->toBe('other-value');
    }
});
