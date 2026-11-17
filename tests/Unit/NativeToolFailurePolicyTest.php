<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeToolFailurePolicy;

covers(NativeToolFailurePolicy::class);

it('defaults to propagating, so Swarm keeps its documented contract unless asked not to', function () {
    expect(NativeToolFailurePolicy::parse(null))->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => false])
        ->and(NativeToolFailurePolicy::parse(''))->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => false])
        ->and(NativeToolFailurePolicy::parse('   '))->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => false]);
});

it('parses both policies, case-insensitively and ignoring surrounding space', function (string $value, NativeToolFailurePolicy $expected) {
    expect(NativeToolFailurePolicy::parse($value))->toBe(['policy' => $expected, 'invalid' => false])
        ->and(NativeToolFailurePolicy::tryFromConfig($value))->toBe($expected);
})->with([
    ['propagate', NativeToolFailurePolicy::Propagate],
    ['PROPAGATE', NativeToolFailurePolicy::Propagate],
    ['  Propagate  ', NativeToolFailurePolicy::Propagate],
    ['recover', NativeToolFailurePolicy::Recover],
    ['Recover', NativeToolFailurePolicy::Recover],
]);

it('flags an unrecognised value invalid but still resolves it, never throwing', function () {
    // tryFromConfig() is called from inside the tool-failure path: throwing a
    // ValueError on a typo'd config value there would mask the tool's own
    // exception. parse() still reports the typo for callers that want to.
    expect(NativeToolFailurePolicy::parse('not-a-policy'))
        ->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => true])
        ->and(NativeToolFailurePolicy::tryFromConfig('not-a-policy'))
        ->toBe(NativeToolFailurePolicy::Propagate);
});
