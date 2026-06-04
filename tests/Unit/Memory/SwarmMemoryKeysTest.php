<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;

test('stepOutput builds the reserved key for a step index', function () {
    expect(SwarmMemoryKeys::stepOutput(0))->toBe('swarm:step.0.output');
    expect(SwarmMemoryKeys::stepOutput(7))->toBe('swarm:step.7.output');
});

test('isStepOutput recognises only step-output keys', function (string $key, bool $expected) {
    expect(SwarmMemoryKeys::isStepOutput($key))->toBe($expected);
})->with([
    'step 0' => ['swarm:step.0.output', true],
    'step 12' => ['swarm:step.12.output', true],
    'last_output' => ['last_output', false],
    'steps' => ['steps', false],
    'other reserved' => ['swarm:active_run', false],
    'partial prefix' => ['swarm:step.1.input', false],
    'no index' => ['swarm:step.output', false],
    'user key' => ['brief', false],
]);

test('stepIndexOf returns the encoded index or null', function () {
    expect(SwarmMemoryKeys::stepIndexOf('swarm:step.0.output'))->toBe(0);
    expect(SwarmMemoryKeys::stepIndexOf('swarm:step.41.output'))->toBe(41);
    expect(SwarmMemoryKeys::stepIndexOf('last_output'))->toBeNull();
    expect(SwarmMemoryKeys::stepIndexOf('swarm:step.x.output'))->toBeNull();
});

test('stepOutput and isStepOutput round-trip', function (int $index) {
    expect(SwarmMemoryKeys::isStepOutput(SwarmMemoryKeys::stepOutput($index)))->toBeTrue();
    expect(SwarmMemoryKeys::stepIndexOf(SwarmMemoryKeys::stepOutput($index)))->toBe($index);
})->with([0, 1, 9, 100]);
