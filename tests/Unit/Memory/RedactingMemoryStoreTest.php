<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWriteSkipped;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryWriteOutcome;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\InMemoryMemoryStore;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

test('a skipped store write returns a marked prospective entry without replacing an existing value', function (): void {
    Event::fake([MemoryWriteSkipped::class]);
    $inner = new InMemoryMemoryStore;
    $inner->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'secret', 'existing'));
    $store = new RedactingMemoryStore(
        inner: $inner,
        policy: new SkippingMemoryCapturePolicy(['secret']),
        events: app(Dispatcher::class),
    );

    $returned = $store->put(new MemoryEntry(
        MemoryScope::Run,
        'run-1',
        'secret',
        'replacement',
        ['origin' => 'tool:remember'],
    ));

    expect(MemoryWriteOutcome::wasSkipped($returned))->toBeTrue()
        ->and($returned->metadata['origin'])->toBe('tool:remember')
        ->and($inner->get(MemoryScope::Run, 'run-1', 'secret')?->value)->toBe('existing');
});
