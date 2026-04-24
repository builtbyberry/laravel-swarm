<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

test('from task preserves reserved keys in plain task arrays', function () {
    $context = RunContext::fromTask([
        'input' => 'Draft outline',
        'draft_id' => 42,
        'audience' => 'intermediate developers',
    ]);

    expect($context->input)->toBe('{"input":"Draft outline","draft_id":42,"audience":"intermediate developers"}');
    expect($context->data)->toBe([
        'input' => 'Draft outline',
        'draft_id' => 42,
        'audience' => 'intermediate developers',
    ]);
});

test('from task returns an existing run context unchanged', function () {
    $context = RunContext::from([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
    ], 'existing-run-id');

    expect(RunContext::fromTask($context))->toBe($context);
});

test('from payload reconstructs serialized queue payloads', function () {
    $context = RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
        'artifacts' => [],
    ]);

    expect($context->runId)->toBe('queued-run-id');
    expect($context->input)->toBe('Draft outline');
    expect($context->data)->toBe(['draft_id' => 42]);
    expect($context->metadata)->toBe(['campaign' => 'content-calendar']);
});

test('from still supports explicit developer-facing context construction', function () {
    $context = RunContext::from([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
    ], 'explicit-run-id');

    expect($context->runId)->toBe('explicit-run-id');
    expect($context->input)->toBe('Draft outline');
    expect($context->data)->toBe(['draft_id' => 42]);
    expect($context->metadata)->toBe(['campaign' => 'content-calendar']);
});

test('from rejects plain arrays that are not explicit context payloads', function () {
    expect(fn () => RunContext::from(['draft_id' => 42]))
        ->toThrow(SwarmException::class, 'RunContext::from() expects an explicit context payload array containing an [input] key.');
});

test('from rejects non array explicit data payloads', function () {
    expect(fn () => RunContext::from([
        'input' => 'Draft outline',
        'data' => 'bad',
    ]))->toThrow(SwarmException::class, 'RunContext::from() expects [data] to be an array.');
});

test('from rejects non string input and points developers to from task', function () {
    expect(fn () => RunContext::from([
        'input' => ['topic' => 'Laravel'],
        'data' => [],
    ]))->toThrow(
        SwarmException::class,
        'RunContext::from() expects input to be a string, [array] given. Use RunContext::fromTask() to pass structured arrays as task input.',
    );
});

test('from payload rejects missing serialized keys', function () {
    expect(fn () => RunContext::fromPayload([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
        'artifacts' => [],
    ]))->toThrow(SwarmException::class, 'RunContext::fromPayload() expects serialized queue payload keys: [run_id, input, data, metadata, artifacts].');
});

test('from payload rejects invalid serialized data types', function () {
    expect(fn () => RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 'Draft outline',
        'data' => 'bad',
        'metadata' => ['campaign' => 'content-calendar'],
        'artifacts' => [],
    ]))->toThrow(SwarmException::class, 'RunContext::fromPayload() expects [data] to be an array.');
});

test('from payload rejects non string input types', function () {
    expect(fn () => RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 42,
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
        'artifacts' => [],
    ]))->toThrow(
        SwarmException::class,
        'RunContext::fromPayload() expects input to be a string, [integer] given.',
    );
});

test('from task continues to accept structured arrays without throwing', function () {
    expect(fn () => RunContext::fromTask([
        'input' => 'Draft outline',
        'draft_id' => 42,
    ]))->not->toThrow(SwarmException::class);
});

test('from task rejects structured arrays that cannot be encoded as json', function () {
    $handle = fopen('php://memory', 'r');

    try {
        expect(fn () => RunContext::fromTask([
            'input' => $handle,
        ]))->toThrow(SwarmException::class, 'Structured swarm task input must be JSON-encodable plain data.');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
});
