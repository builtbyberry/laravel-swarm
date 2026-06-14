<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\SwarmArtifact;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

enum InvalidTaskStatus: string
{
    case Draft = 'draft';
}

class InvalidJsonSerializableTask implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['safe' => true];
    }
}

class InvalidStringableTask
{
    public function __toString(): string
    {
        return 'safe';
    }
}

class InvalidPublicPropertyTask
{
    public string $value = 'safe';
}

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
    $context = RunContext::fromTask([
        'input' => 'Draft outline',
        'draft_id' => 42,
        'nested' => [
            'score' => 9.5,
            'approved' => true,
            'empty' => null,
        ],
    ]);

    expect($context->data)->toBe([
        'input' => 'Draft outline',
        'draft_id' => 42,
        'nested' => [
            'score' => 9.5,
            'approved' => true,
            'empty' => null,
        ],
    ]);
});

test('from task rejects structured arrays that are not plain data', function (mixed $value) {
    expect(fn () => RunContext::fromTask([
        'input' => $value,
    ]))->toThrow(SwarmException::class);
})->with([
    'json serializable object' => [new InvalidJsonSerializableTask],
    'stringable object' => [new InvalidStringableTask],
    'public property object' => [new InvalidPublicPropertyTask],
    'enum' => [InvalidTaskStatus::Draft],
    'nested object' => [['nested' => new InvalidPublicPropertyTask]],
]);

test('from task rejects closures as non plain data', function () {
    $closure = fn (): string => 'bad';

    expect(fn () => RunContext::fromTask([
        'input' => $closure,
    ]))->toThrow(SwarmException::class, 'Swarm plain data value [task.input] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('from task rejects resources as non plain data', function () {
    $handle = fopen('php://memory', 'r');

    try {
        expect(fn () => RunContext::fromTask([
            'input' => $handle,
        ]))->toThrow(SwarmException::class, 'Swarm plain data value [task.input] must be a string, integer, float, boolean, null, or array of plain data.');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
});

test('queue payload rejects invalid explicit context data', function () {
    $context = new RunContext('run-id', 'input', data: ['bad' => new InvalidPublicPropertyTask]);

    expect(fn () => $context->toQueuePayload())
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.data.bad] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('queue payload rejects invalid explicit context metadata', function () {
    $context = new RunContext('run-id', 'input', metadata: ['bad' => new InvalidPublicPropertyTask]);

    expect(fn () => $context->toQueuePayload())
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('queue payload rejects invalid explicit artifact content', function () {
    $context = new RunContext('run-id', 'input');
    $context->addArtifact(new SwarmArtifact('manual', new InvalidPublicPropertyTask));

    expect(fn () => $context->toQueuePayload())
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.artifacts.0.content] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('queue payload rejects invalid explicit artifact metadata', function () {
    $context = new RunContext('run-id', 'input');
    $context->addArtifact(new SwarmArtifact('manual', 'content', ['bad' => new InvalidPublicPropertyTask]));

    expect(fn () => $context->toQueuePayload())
        ->toThrow(SwarmException::class, 'Swarm plain data value [RunContext.artifacts.0.metadata.bad] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('from payload rejects invalid serialized artifact content', function () {
    expect(fn () => RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 'Draft outline',
        'data' => [],
        'metadata' => [],
        'artifacts' => [
            ['name' => 'manual', 'content' => new InvalidPublicPropertyTask, 'metadata' => [], 'step_agent_class' => null],
        ],
    ]))->toThrow(SwarmException::class, 'Swarm plain data value [RunContext payload.artifacts.0.content] must be a string, integer, float, boolean, null, or array of plain data.');
});

test('from payload rejects non array serialized artifact metadata', function () {
    expect(fn () => RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 'Draft outline',
        'data' => [],
        'metadata' => [],
        'artifacts' => [
            ['name' => 'manual', 'content' => 'content', 'metadata' => 'bad', 'step_agent_class' => null],
        ],
    ]))->toThrow(SwarmException::class, 'Swarm artifact metadata [RunContext payload.artifacts.0.metadata] must be an array.');
});

test('from payload normalizes serialized artifact defaults and ignores unknown keys', function () {
    $context = RunContext::fromPayload([
        'run_id' => 'queued-run-id',
        'input' => 'Draft outline',
        'data' => [],
        'metadata' => [],
        'artifacts' => [
            ['name' => 'manual', 'ignored' => 'value'],
        ],
    ]);

    expect($context->artifacts)->toHaveCount(1);
    expect($context->artifacts[0]->toArray())->toBe([
        'name' => 'manual',
        'content' => null,
        'metadata' => [],
        'step_agent_class' => null,
    ]);
});

test('withActor accepts an Actor instance and stores it under metadata.actor', function () {
    $context = RunContext::fromTask('task')
        ->withActor(new Actor(id: 'u-1', type: 'user'));

    expect($context->metadata['actor'])->toBe([
        'id' => 'u-1',
        'type' => 'user',
        'name' => null,
        'metadata' => [],
    ]);
});

test('withActor accepts string shorthand', function () {
    $context = RunContext::fromTask('task')->withActor('api_token:abc-123');

    expect($context->metadata['actor']['type'])->toBe('api_token');
    expect($context->metadata['actor']['id'])->toBe('abc-123');
});

test('withActor with null clears the actor binding', function () {
    $context = RunContext::fromTask('task')->withActor('system:cron');
    expect($context->metadata)->toHaveKey('actor');

    $context->withActor(null);
    expect($context->metadata)->not->toHaveKey('actor');
});

test('actor() returns null when no actor is bound', function () {
    $context = RunContext::fromTask('task');

    expect($context->actor())->toBeNull();
});

test('actor() rehydrates the bound Actor from metadata', function () {
    $context = RunContext::fromTask('task')
        ->withActor(new Actor(id: 'u-9', type: 'user', name: 'Ada'));

    $actor = $context->actor();

    expect($actor)->not->toBeNull();
    expect($actor->id)->toBe('u-9');
    expect($actor->name)->toBe('Ada');
});

test('withActor survives queue payload serialization roundtrip', function () {
    $context = RunContext::fromTask('task')
        ->withActor(new Actor(id: 'u-9', type: 'user'));

    $rehydrated = RunContext::fromPayload($context->toQueuePayload());

    expect($rehydrated->metadata['actor']['id'])->toBe('u-9');
    expect($rehydrated->metadata['actor']['type'])->toBe('user');
});

test('conversationId() returns null when no conversation is bound', function () {
    expect(RunContext::fromTask('task')->conversationId())->toBeNull();
});

test('withConversationId binds and reads back the conversation id', function () {
    $context = RunContext::fromTask('task')->withConversationId('conv-7');

    expect($context->conversationId())->toBe('conv-7');
});

test('withConversationId trims and treats empty or whitespace as cleared', function () {
    expect(RunContext::fromTask('task')->withConversationId('  conv-7  ')->conversationId())->toBe('conv-7');
    expect(RunContext::fromTask('task')->withConversationId('')->conversationId())->toBeNull();
    expect(RunContext::fromTask('task')->withConversationId('   ')->conversationId())->toBeNull();
});

test('withConversationId(null) clears a previously bound id', function () {
    $context = RunContext::fromTask('task')
        ->withConversationId('conv-7')
        ->withConversationId(null);

    expect($context->conversationId())->toBeNull();
    expect($context->metadata)->not->toHaveKey('conversation_id');
});

test('withConversationId survives queue payload serialization roundtrip', function () {
    $context = RunContext::fromTask('task')->withConversationId('conv-7');

    $rehydrated = RunContext::fromPayload($context->toQueuePayload());

    expect($rehydrated->conversationId())->toBe('conv-7');
});

test('fake applies the conversation_id override', function () {
    expect(RunContext::fake(['conversation_id' => 'conv-9'])->conversationId())->toBe('conv-9');
    expect(RunContext::fake()->conversationId())->toBeNull();
});

test('fake returns a deterministic context with sensible defaults', function () {
    $context = RunContext::fake();

    expect($context->runId)->toBe('fake-run-id');
    expect($context->input)->toBe('');
    expect($context->data)->toBe([]);
    expect($context->metadata)->toBe([]);
    expect($context->artifacts)->toBe([]);
    expect($context->actor())->toBeNull();
});

test('fake applies run_id, input, data, metadata and artifact overrides', function () {
    $artifact = new SwarmArtifact('manual', 'draft body');

    $context = RunContext::fake([
        'run_id' => 'test-run-1',
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
        'artifacts' => [$artifact],
    ]);

    expect($context->runId)->toBe('test-run-1');
    expect($context->input)->toBe('Draft outline');
    expect($context->data)->toBe(['draft_id' => 42]);
    expect($context->metadata)->toBe(['campaign' => 'content-calendar']);
    expect($context->artifacts)->toBe([$artifact]);
});

test('fake actor override accepts an Actor value object', function () {
    $context = RunContext::fake([
        'actor' => new Actor(id: 'u-1', type: 'user', name: 'Ada'),
    ]);

    expect($context->actor()?->id)->toBe('u-1');
    expect($context->actor()?->type)->toBe('user');
    expect($context->actor()?->name)->toBe('Ada');
});

test('fake actor override accepts string shorthand', function () {
    $context = RunContext::fake(['actor' => 'system:cron']);

    expect($context->metadata['actor']['type'])->toBe('system');
    expect($context->metadata['actor']['id'])->toBe('cron');
});

test('fake actor override null does not bind any actor', function () {
    $context = RunContext::fake(['actor' => null]);

    expect($context->actor())->toBeNull();
    expect($context->metadata)->not->toHaveKey('actor');
});

test('fake composes cleanly with the existing fluent builders', function () {
    $context = RunContext::fake(['input' => 'Draft outline'])
        ->withActor('user:42')
        ->withLabels(['tenant' => 'acme'])
        ->mergeData(['draft_id' => 7])
        ->mergeMetadata(['campaign' => 'content-calendar']);

    expect($context->runId)->toBe('fake-run-id');
    expect($context->input)->toBe('Draft outline');
    expect($context->data)->toBe(['draft_id' => 7]);
    expect($context->actor()?->id)->toBe('42');
    expect($context->labels())->toBe(['tenant' => 'acme']);
    expect($context->metadata['campaign'])->toBe('content-calendar');
});

test('fake context serializes through the queue payload roundtrip', function () {
    $context = RunContext::fake([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'actor' => 'user:7',
    ]);

    $rehydrated = RunContext::fromPayload($context->toQueuePayload());

    expect($rehydrated->runId)->toBe('fake-run-id');
    expect($rehydrated->input)->toBe('Draft outline');
    expect($rehydrated->data)->toBe(['draft_id' => 42]);
    expect($rehydrated->metadata['actor']['id'])->toBe('7');
    expect($rehydrated->metadata['actor']['type'])->toBe('user');
});
