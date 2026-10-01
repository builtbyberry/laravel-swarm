<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Telemetry\PackageJobTelemetryState;

function packageJobTelemetryStateCap(): int
{
    return (new ReflectionClassConstant(PackageJobTelemetryState::class, 'MAX_PENDING'))->getValue();
}

test('markers nobody consumes cannot accumulate past the cap', function (): void {
    $state = new PackageJobTelemetryState;
    $cap = packageJobTelemetryStateCap();

    for ($attempt = 1; $attempt <= $cap * 4; $attempt++) {
        $state->markFailed("job:run:uuid:{$attempt}", 'uuid');
    }

    expect($state->pendingCount())->toBe($cap);
});

test('the cap evicts the oldest marker and keeps the newest', function (): void {
    $state = new PackageJobTelemetryState;
    $cap = packageJobTelemetryStateCap();

    for ($attempt = 1; $attempt <= $cap + 1; $attempt++) {
        $state->markFailed("job:run:uuid:{$attempt}", 'uuid');
    }

    expect($state->consumeFailed('job:run:uuid:1'))->toBeFalse()
        ->and($state->consumeFailed('job:run:uuid:2'))->toBeTrue()
        ->and($state->consumeFailed('job:run:uuid:'.($cap + 1)))->toBeTrue();
});

test('a marker is consumed exactly once', function (): void {
    $state = new PackageJobTelemetryState;

    $state->markFailed('job:run:uuid:1', 'uuid');
    $state->markFailed('job:run:uuid:1', 'uuid');

    expect($state->pendingCount())->toBe(1)
        ->and($state->consumeFailed('job:run:uuid:1'))->toBeTrue()
        ->and($state->consumeFailed('job:run:uuid:1'))->toBeFalse()
        ->and($state->pendingCount())->toBe(0);
});

test('a marker left without a queue job is still consumable by its key', function (): void {
    $state = new PackageJobTelemetryState;

    $state->markFailed('job:run::1', null);

    expect($state->consumeFailed('job:run::1'))->toBeTrue();
});

test('forgetting a queue job drops every marker that job left and no other', function (): void {
    $state = new PackageJobTelemetryState;

    $state->markFailed('job:run:1:1', '1');
    $state->markFailed('job:run:1:2', '1');
    $state->markFailed('job:run:11:1', '11');
    $state->markFailed('job:run::1', null);

    $state->forgetJob('1');

    expect($state->pendingCount())->toBe(2)
        ->and($state->consumeFailed('job:run:1:1'))->toBeFalse()
        ->and($state->consumeFailed('job:run:1:2'))->toBeFalse()
        ->and($state->consumeFailed('job:run:11:1'))->toBeTrue()
        ->and($state->consumeFailed('job:run::1'))->toBeTrue();
});
