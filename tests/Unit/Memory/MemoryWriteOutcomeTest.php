<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\MemoryWriteOutcome;

test('it marks a prospective entry as skipped without changing its value or existing metadata', function (): void {
    $entry = new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'secret',
        value: 'sensitive',
        metadata: ['origin' => 'tool:remember'],
    );

    $marked = MemoryWriteOutcome::skipped($entry);

    expect($marked)->not->toBe($entry)
        ->and($marked->value)->toBe('sensitive')
        ->and($marked->metadata)->toBe([
            'origin' => 'tool:remember',
            MemoryWriteOutcome::KEY => 'skipped',
        ])
        ->and(MemoryWriteOutcome::wasSkipped($marked))->toBeTrue()
        ->and(MemoryWriteOutcome::wasSkipped($entry))->toBeFalse();
});
