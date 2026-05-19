<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\LeaseManager;

beforeEach(function (): void {
    $this->leases = app(LeaseManager::class);
});

test('resolveQueueLeaseSeconds doubles the timeout for long timeouts', function (): void {
    expect($this->leases->resolveQueueLeaseSeconds(180))->toBe(360);
    expect($this->leases->resolveQueueLeaseSeconds(600))->toBe(1200);
});

test('resolveQueueLeaseSeconds enforces a 300-second floor for short timeouts', function (): void {
    expect($this->leases->resolveQueueLeaseSeconds(60))->toBe(300);
    expect($this->leases->resolveQueueLeaseSeconds(149))->toBe(300);
    expect($this->leases->resolveQueueLeaseSeconds(150))->toBe(300);
});

test('failCoordinationRunIfQueueHierarchicalParallel is a no-op when no row exists', function (): void {
    $this->leases->failCoordinationRunIfQueueHierarchicalParallel('missing-run-id', new RuntimeException('boom'));

    expect(true)->toBeTrue();
});

test('markCoordinationRunCompleted is a no-op when no row exists', function (): void {
    $this->leases->markCoordinationRunCompleted('missing-run-id');

    expect(true)->toBeTrue();
});
