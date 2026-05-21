<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use Carbon\CarbonImmutable;

test('constructs with required fields and defaulted metadata + timestamps', function () {
    $entry = new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-abc',
        key: 'user_preference',
        value: 'dark',
    );

    expect($entry->scope)->toBe(MemoryScope::Run);
    expect($entry->scopeId)->toBe('run-abc');
    expect($entry->key)->toBe('user_preference');
    expect($entry->value)->toBe('dark');
    expect($entry->metadata)->toBe([]);
    expect($entry->createdAt)->toBeNull();
    expect($entry->updatedAt)->toBeNull();
});

test('withValue returns a new instance with the new value, preserving the address', function () {
    $original = new MemoryEntry(
        scope: MemoryScope::Conversation,
        scopeId: 'conv-1',
        key: 'tone',
        value: 'formal',
        metadata: ['source' => 'classifier'],
    );

    $updated = $original->withValue('casual');

    expect($updated)->not->toBe($original);
    expect($updated->value)->toBe('casual');
    expect($updated->scope)->toBe($original->scope);
    expect($updated->scopeId)->toBe($original->scopeId);
    expect($updated->key)->toBe($original->key);
    expect($updated->metadata)->toBe(['source' => 'classifier']);
});

test('withValue can replace metadata when an array is supplied', function () {
    $original = new MemoryEntry(
        scope: MemoryScope::Run,
        scopeId: 'run-1',
        key: 'x',
        value: 1,
        metadata: ['old' => true],
    );

    $updated = $original->withValue(2, metadata: ['new' => true]);

    expect($updated->value)->toBe(2);
    expect($updated->metadata)->toBe(['new' => true]);
});

test('withTimestamps stamps both timestamps without mutating the source', function () {
    $original = new MemoryEntry(
        scope: MemoryScope::Agent,
        scopeId: 'App\\Agents\\Coach',
        key: 'persona',
        value: ['style' => 'friendly'],
    );

    $createdAt = CarbonImmutable::parse('2026-05-21T12:00:00Z');
    $updatedAt = CarbonImmutable::parse('2026-05-21T12:30:00Z');

    $persisted = $original->withTimestamps($createdAt, $updatedAt);

    expect($persisted->createdAt)->toEqual($createdAt);
    expect($persisted->updatedAt)->toEqual($updatedAt);
    expect($original->createdAt)->toBeNull();
    expect($original->updatedAt)->toBeNull();
});

test('accepts plain-data values: string, int, float, bool, null, nested arrays', function () {
    $cases = [
        'string' => 'hello',
        'int' => 42,
        'float' => 3.14,
        'bool' => true,
        'null' => null,
        'nested' => ['a' => 1, 'b' => [2, 3, 4]],
    ];

    foreach ($cases as $label => $value) {
        $entry = new MemoryEntry(
            scope: MemoryScope::Swarm,
            scopeId: 'App\\Swarms\\Triage',
            key: $label,
            value: $value,
        );

        expect($entry->value)->toEqual($value, "value [{$label}] preserved as-is");
    }
});
