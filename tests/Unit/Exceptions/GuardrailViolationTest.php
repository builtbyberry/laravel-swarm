<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;

test('block carries policy code reason scope and safe metadata keys', function () {
    $e = GuardrailViolation::block('policy_x', 'human reason', ['k' => 'v'], 'output');

    expect($e->policyCode)->toBe('policy_x')
        ->and($e->getMessage())->toBe('human reason')
        ->and($e->scope)->toBe('output')
        ->and($e->metadata)->toBe(['k' => 'v']);

    expect($e->safeContextMetadata())->toBe([
        'guardrail_code' => 'policy_x',
        'guardrail_scope' => 'output',
        'guardrail_metadata' => ['k' => 'v'],
    ]);
});

test('safe context metadata omits null scope and empty metadata', function () {
    $e = GuardrailViolation::block('p', 'r');

    expect($e->safeContextMetadata())->toBe(['guardrail_code' => 'p']);
});
