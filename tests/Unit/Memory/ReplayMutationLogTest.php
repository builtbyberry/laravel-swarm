<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\ReplayMutationLog;

test('replay mutations preserve operation order', function () {
    $log = new ReplayMutationLog;

    $log->put('run-1', 'finding', 'first', ['source' => 'retry']);
    $log->forget('run-1', 'finding');
    $log->put('run-1', 'finding', 'second');

    expect($log->toArray())->toBe([
        ['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'value' => 'first', 'metadata' => ['source' => 'retry']],
        ['op' => 'forget', 'scope_id' => 'run-1', 'key' => 'finding'],
        ['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'value' => 'second', 'metadata' => []],
    ]);
});

test('replay mutations round trip through plain array data', function () {
    $payload = [
        ['op' => 'put', 'scope_id' => 'run-1', 'key' => 'nested', 'value' => ['answer' => 42], 'metadata' => ['source' => 'tool']],
        ['op' => 'forget', 'scope_id' => 'run-1', 'key' => 'obsolete'],
    ];

    $log = ReplayMutationLog::fromArray($payload);

    expect($log->toArray())->toBe($payload)
        ->and($log->isEmpty())->toBeFalse()
        ->and($log->isApplied())->toBeFalse();

    $log->markApplied();

    expect($log->isApplied())->toBeTrue();
});

test('a new replay mutation log is empty and unapplied', function () {
    $log = new ReplayMutationLog;

    expect($log->isEmpty())->toBeTrue()
        ->and($log->isApplied())->toBeFalse();
});

test('deserializing a malformed replay mutation fails loud', function (mixed $row) {
    expect(fn () => ReplayMutationLog::fromArray([$row]))
        ->toThrow(SwarmException::class, 'Invalid replay memory mutation');
})->with([
    'non-array row' => ['invalid'],
    'unknown op' => [['op' => 'replace', 'scope_id' => 'run-1', 'key' => 'finding']],
    'empty scope' => [['op' => 'forget', 'scope_id' => '', 'key' => 'finding']],
    'non-string scope' => [['op' => 'forget', 'scope_id' => 123, 'key' => 'finding']],
    'empty key' => [['op' => 'forget', 'scope_id' => 'run-1', 'key' => '']],
    'non-string key' => [['op' => 'forget', 'scope_id' => 'run-1', 'key' => false]],
    'put missing value' => [['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'metadata' => []]],
    'put metadata is not an array' => [['op' => 'put', 'scope_id' => 'run-1', 'key' => 'finding', 'value' => 'value', 'metadata' => 'invalid']],
]);
