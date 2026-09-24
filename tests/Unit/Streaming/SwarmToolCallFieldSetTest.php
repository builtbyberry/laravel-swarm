<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * Literal drift guard: each upstream property change needs explicit triage.
 *
 * @see SwarmToolCall::toArray()
 */
test('Data\\ToolCall property set is pinned so upstream additions force a triage', function (): void {
    // Hardcoded snapshot of the reviewed Laravel AI 1.0 property set.
    $expected = [
        'id',
        'name',
        'arguments',
        'resultId',
        'reasoningId',
        'reasoningSummary',
        'reasoningEncryptedContent',
        'thoughtSignature',
    ];

    $actual = collect((new ReflectionClass(ToolCall::class))->getProperties())
        ->map(fn (ReflectionProperty $p): string => $p->getName())
        ->sort()
        ->values()
        ->all();

    expect($actual)->toBe(
        collect($expected)->sort()->values()->all(),
        'laravel/ai Data\\ToolCall changed its property set. Decide whether each added/removed field '
        .'belongs in SwarmToolCall::toArray() (carry it) or stays excluded (e.g. another opaque blob like '
        .'reasoning_encrypted_content), then update this hardcoded list to match.',
    );
});

test('serialized tool calls omit opaque provider continuation state without mutating native data', function (): void {
    $call = new ToolCall(
        id: 'call',
        name: 'lookup',
        arguments: ['query' => 'visible'],
        resultId: 'result',
        reasoningEncryptedContent: 'OPAQUE_ENCRYPTED_REASONING',
        thoughtSignature: 'OPAQUE_THOUGHT_SIGNATURE',
    );
    $event = new SwarmToolCall('event', 'run', 0, 'agent', $call, 123);
    $payload = $event->toArray();

    expect($payload['tool_call'])->toBe([
        'id' => 'call',
        'name' => 'lookup',
        'arguments' => ['query' => 'visible'],
        'result_id' => 'result',
        'reasoning_id' => null,
        'reasoning_summary' => null,
    ])->and(SwarmToolCall::fromArray($payload)->toArray())->toBe($payload)
        ->and($call->reasoningEncryptedContent)->toBe('OPAQUE_ENCRYPTED_REASONING')
        ->and($call->thoughtSignature)->toBe('OPAQUE_THOUGHT_SIGNATURE');
});
