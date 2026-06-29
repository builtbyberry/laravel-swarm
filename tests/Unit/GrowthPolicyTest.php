<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;

test('parse maps recognised values to their case', function (string $value, GrowthPolicy $expected) {
    expect(GrowthPolicy::parse($value))->toBe(['policy' => $expected, 'invalid' => false]);
})->with([
    ['ignore', GrowthPolicy::Ignore],
    ['warn', GrowthPolicy::Warn],
    ['degrade_to_cold', GrowthPolicy::DegradeToCold],
    ['backpressure', GrowthPolicy::Backpressure],
    ['refuse', GrowthPolicy::Refuse],
    ['  REFUSE  ', GrowthPolicy::Refuse],
]);

test('parse defaults null and blank to warn+degrade without flagging invalid', function (?string $value) {
    expect(GrowthPolicy::parse($value))->toBe(['policy' => GrowthPolicy::DegradeToCold, 'invalid' => false]);
})->with([null, '', '   ']);

test('parse falls back to the default and flags an unrecognised value', function () {
    expect(GrowthPolicy::parse('explode'))->toBe(['policy' => GrowthPolicy::DegradeToCold, 'invalid' => true]);
});

test('tryFromConfig returns the parsed policy', function () {
    expect(GrowthPolicy::tryFromConfig('backpressure'))->toBe(GrowthPolicy::Backpressure)
        ->and(GrowthPolicy::tryFromConfig(null))->toBe(GrowthPolicy::DegradeToCold)
        ->and(GrowthPolicy::tryFromConfig('nonsense'))->toBe(GrowthPolicy::DegradeToCold);
});

test('rank orders the ladder by escalating severity', function () {
    expect(GrowthPolicy::Ignore->rank())->toBeLessThan(GrowthPolicy::Warn->rank())
        ->and(GrowthPolicy::Warn->rank())->toBeLessThan(GrowthPolicy::DegradeToCold->rank())
        ->and(GrowthPolicy::DegradeToCold->rank())->toBeLessThan(GrowthPolicy::Backpressure->rank())
        ->and(GrowthPolicy::Backpressure->rank())->toBeLessThan(GrowthPolicy::Refuse->rank());
});

test('permits is the cumulative-band rule: a rung includes every lower one', function () {
    // Backpressure permits warn + degrade + backpressure, but not refuse.
    expect(GrowthPolicy::Backpressure->permits(GrowthPolicy::Warn))->toBeTrue()
        ->and(GrowthPolicy::Backpressure->permits(GrowthPolicy::DegradeToCold))->toBeTrue()
        ->and(GrowthPolicy::Backpressure->permits(GrowthPolicy::Backpressure))->toBeTrue()
        ->and(GrowthPolicy::Backpressure->permits(GrowthPolicy::Refuse))->toBeFalse();

    // Ignore permits nothing above itself.
    expect(GrowthPolicy::Ignore->permits(GrowthPolicy::Warn))->toBeFalse();

    // Refuse permits the whole ladder.
    expect(GrowthPolicy::Refuse->permits(GrowthPolicy::Backpressure))->toBeTrue();
});
