<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeToolFailurePolicy;

covers(NativeToolFailurePolicy::class);

it('defaults to propagating, so Swarm keeps its documented contract unless asked not to', function (?string $value) {
    expect(NativeToolFailurePolicy::parse($value))->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => false]);
})->with([null, '', '   ']);

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

it('flags an unrecognised string invalid but still resolves it, never throwing', function () {
    // tryFromConfig() is called from inside the tool-failure path: throwing a
    // ValueError on a typo'd config value there would mask the tool's own
    // exception. The flag it reports is consumed once at boot, by the service
    // provider, so the typo is not silent. Non-string values are guarded at the
    // call site, not here -- the parameter is deliberately ?string, and
    // NativeToolFailurePropagationTest covers the reachable non-string cases.
    expect(NativeToolFailurePolicy::parse('not-a-policy'))
        ->toBe(['policy' => NativeToolFailurePolicy::Propagate, 'invalid' => true])
        ->and(NativeToolFailurePolicy::tryFromConfig('not-a-policy'))
        ->toBe(NativeToolFailurePolicy::Propagate);
});
