<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\DurableDispatchType;
use BuiltByBerry\LaravelSwarm\Enums\OutboxDispatchType;
use BuiltByBerry\LaravelSwarm\Enums\RelayLane;

// The stored dispatch_type column values and the swarm:relay --type keywords are
// a persisted/CLI contract. Lock the string values so a rename can never slip
// through silently.

test('DurableDispatchType string values are the persisted contract', function (): void {
    expect(DurableDispatchType::Step->value)->toBe('step')
        ->and(DurableDispatchType::Branch->value)->toBe('branch')
        ->and(DurableDispatchType::QueuedResume->value)->toBe('queued_resume')
        ->and(DurableDispatchType::cases())->toHaveCount(3);
});

test('RelayLane names the two relay lanes', function (): void {
    expect(RelayLane::Durable->value)->toBe('durable')
        ->and(RelayLane::Audit->value)->toBe('audit')
        ->and(RelayLane::cases())->toHaveCount(2);
});

test('durable dispatch type values match the deprecated enum for backward compatibility', function (): void {
    foreach (DurableDispatchType::cases() as $type) {
        expect(OutboxDispatchType::from($type->value)->value)->toBe($type->value);
    }
});

test('deprecated OutboxDispatchType still resolves every legacy value', function (): void {
    expect(OutboxDispatchType::Step->value)->toBe('step')
        ->and(OutboxDispatchType::Branch->value)->toBe('branch')
        ->and(OutboxDispatchType::QueuedResume->value)->toBe('queued_resume')
        ->and(OutboxDispatchType::Audit->value)->toBe('audit')
        ->and(OutboxDispatchType::Audit->isAudit())->toBeTrue()
        ->and(OutboxDispatchType::Step->isAudit())->toBeFalse();
});

test('deprecated OutboxDispatchType bridges to the split enums', function (): void {
    expect(OutboxDispatchType::Audit->lane())->toBe(RelayLane::Audit)
        ->and(OutboxDispatchType::Step->lane())->toBe(RelayLane::Durable)
        ->and(OutboxDispatchType::Step->toDurableDispatchType())->toBe(DurableDispatchType::Step)
        ->and(OutboxDispatchType::Audit->toDurableDispatchType())->toBeNull();
});
