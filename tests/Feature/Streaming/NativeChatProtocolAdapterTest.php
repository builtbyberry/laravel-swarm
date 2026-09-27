<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\PayloadAvailability;
use BuiltByBerry\LaravelSwarm\Streaming\Protocols\AgentUserInteractionSwarmProtocol;
use BuiltByBerry\LaravelSwarm\Streaming\Protocols\VercelSwarmProtocol;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\AgentUserInteractionClient;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\VercelDataStreamClient;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;

/** @param list<SwarmStreamEvent> $events */
function nativeProtocolStream(array $events, string $topology = 'sequential', ?Closure $after = null): StreamableSwarmResponse
{
    return new StreamableSwarmResponse(
        runId: 'swarm-run-1',
        generator: function () use ($events, $after): Generator {
            foreach ($events as $event) {
                yield $event;
            }

            $after?->__invoke();
        },
        topology: $topology,
        nativeChatProtocolsEnabled: true,
    );
}

function renderedProtocolContent(StreamableSwarmResponse $stream): string
{
    $_SERVER['LARAVEL_OCTANE'] = true;

    try {
        $response = $stream->toResponse(request());
    } finally {
        unset($_SERVER['LARAVEL_OCTANE']);
    }

    return implode('', iterator_to_array(($response->getCallback())()));
}

/** @return list<SwarmStreamEvent> */
function successfulNativeProtocolEvents(bool $withRows = false): array
{
    return [
        new SwarmStreamStart('stream-start', 'swarm-run-1', 'App\\Swarms\\WritingSwarm', 'sequential', 'private input', ['secret' => true], 1),
        new SwarmStepStart('step-start', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'Writer', 'private input', 2),
        new SwarmReasoningDelta('reasoning', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'reasoning-1', 'private chain of thought', 3, null),
        new SwarmTextDelta('delta', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'Hello', 4, 'content-block-1'),
        new SwarmTextEnd('text-end', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'content-block-1', 5),
        new SwarmStepEnd(
            id: 'step-end',
            runId: 'swarm-run-1',
            stepIndex: 0,
            agentClass: 'App\\Agents\\Writer',
            agent: 'Writer',
            output: 'Hello',
            durationMs: 5,
            metadata: ['private' => true],
            timestamp: 6,
            nativeResult: new NativeStepResult(
                conversationId: $withRows ? 'conversation-row' : null,
                userMessageId: $withRows ? 'user-row' : null,
                assistantMessageId: $withRows ? 'assistant-row' : null,
            ),
        ),
        new SwarmStreamEnd('stream-end', 'swarm-run-1', 'Hello', [
            'input_tokens' => 2,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ], ['private' => true], 7),
    ];
}

test('native chat protocols are default off and final agent topology is rejected before iteration', function () {
    $iterations = 0;
    $disabled = new StreamableSwarmResponse('run', function () use (&$iterations): Generator {
        $iterations++;
        yield new SwarmStreamStart('start', 'run', 'Swarm', 'sequential', null, [], 1);
    }, topology: 'sequential');

    expect(fn () => $disabled->usingVercelDataProtocol('ui-message'))
        ->toThrow(SwarmException::class, 'disabled');

    $parallel = nativeProtocolStream([], 'parallel', function () use (&$iterations): void {
        $iterations++;
    });

    expect(fn () => $parallel->usingVercelDataProtocol('ui-message', NativeProtocolProjection::FinalAgent))
        ->toThrow(SwarmException::class, 'only for sequential');
    expect($iterations)->toBe(0);
});

test('workflow projection preserves branch-local identity without inventing standard message order', function () {
    $first = (new SwarmTextDelta('branch-a-0', 'swarm-run-1', 0, 'AgentA', 'A', 3, 'message-a'))
        ->withBranchIdentity('parallel:0', 'attempt-a', 0);
    $second = (new SwarmTextDelta('branch-b-0', 'swarm-run-1', 1, 'AgentB', 'B', 4, 'message-b'))
        ->withBranchIdentity('parallel:1', 'attempt-b', 0);
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'ParallelSwarm', 'parallel', null, [], 1),
        new SwarmStepStart('step-a', 'swarm-run-1', 0, 'AgentA', 'AgentA', null, 2),
        $first,
        $second,
        new SwarmStreamEnd('end', 'swarm-run-1', 'A B', ['input_tokens' => 2, 'output_tokens' => 2], [], 5),
    ];

    $parts = VercelDataStreamClient::consume(renderedProtocolContent(
        nativeProtocolStream($events, 'parallel')->usingVercelDataProtocol('ui-message'),
    ));
    $data = array_values(array_filter($parts, fn (array $part): bool => ($part['type'] ?? null) === 'data-swarm'));

    expect(array_column($parts, 'type'))->not->toContain('text-delta')
        ->and($data[2]['data'])->toMatchArray([
            'branch_id' => 'parallel:0',
            'attempt_id' => 'attempt-a',
            'branch_sequence' => 0,
            'message_id' => 'message-a',
            'delta' => 'A',
        ])
        ->and($data[3]['data'])->toMatchArray([
            'branch_id' => 'parallel:1',
            'attempt_id' => 'attempt-b',
            'branch_sequence' => 0,
            'message_id' => 'message-b',
            'delta' => 'B',
        ]);
});

test('final agent projection emits native frames and only real conversation row identities', function () {
    $events = AgentUserInteractionClient::consume(renderedProtocolContent(
        nativeProtocolStream(successfulNativeProtocolEvents(withRows: true))
            ->usingAgentUserInteractionProtocol('thread-1', projection: NativeProtocolProjection::FinalAgent),
    ));

    $types = array_column($events, 'type');
    $finished = $events[array_key_last($events)];

    expect($types)->toContain('TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END')
        ->not->toContain('REASONING_MESSAGE_CONTENT')
        ->and($finished)->toMatchArray([
            'type' => 'RUN_FINISHED',
            'threadId' => 'thread-1',
            'runId' => 'swarm-run-1',
            'messageId' => 'assistant-row',
            'userMessageId' => 'user-row',
        ]);
});

test('redacted and omitted tool payloads are never represented as genuine native tool values', function () {
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        new SwarmToolCall('call', 'swarm-run-1', 0, 'Agent', new ToolCallData('tool-1', 'lookup', [], null), 3, PayloadAvailability::Omitted),
        new SwarmToolResult('result', 'swarm-run-1', 0, 'Agent', new ToolResultData('tool-1', 'lookup', [], null), true, null, 4, payloadAvailability: PayloadAvailability::Omitted),
        new SwarmStepEnd('step-end', 'swarm-run-1', 0, 'Agent', 'Agent', null, 1, [], 5, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', null, ['input_tokens' => 1, 'output_tokens' => 0], [], 6),
    ];

    $parts = VercelDataStreamClient::consume(renderedProtocolContent(
        nativeProtocolStream($events)->usingVercelDataProtocol('ui-message', NativeProtocolProjection::FinalAgent),
    ));

    expect(array_column($parts, 'type'))->not->toContain('tool-input-available', 'tool-output-available')
        ->and(json_encode($parts))->not->toContain('arguments', 'output')
        ->and(json_encode($parts))->toContain('"payload_status":"omitted"');
});

test('unsupported native approval and incomplete replay produce terminal errors without success frames', function (string $protocol) {
    $approval = nativeProtocolStream([
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStreamError('error', 'swarm-run-1', 'Native tool approval is not supported.', UnsupportedNativeApprovalException::class, false, [], 2),
    ], after: fn () => throw new UnsupportedNativeApprovalException);
    $prefix = nativeProtocolStream([
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
    ]);

    if ($protocol === 'vercel') {
        $approvalFrames = VercelDataStreamClient::consume(renderedProtocolContent($approval->usingVercelDataProtocol('ui-message')));
        $prefixFrames = VercelDataStreamClient::consume(renderedProtocolContent($prefix->usingVercelDataProtocol('ui-message')));
        foreach ([$approvalFrames, $prefixFrames] as $frames) {
            expect(array_column($frames, 'type'))->toContain('error')->not->toContain('finish', 'finish-step', 'tool-approval-request');
        }
    } else {
        $approvalFrames = AgentUserInteractionClient::consume(renderedProtocolContent($approval->usingAgentUserInteractionProtocol('thread')));
        $prefixFrames = AgentUserInteractionClient::consume(renderedProtocolContent($prefix->usingAgentUserInteractionProtocol('thread')));
        foreach ([$approvalFrames, $prefixFrames] as $frames) {
            expect(array_column($frames, 'type'))->toContain('RUN_ERROR')->not->toContain('RUN_FINISHED', 'STEP_FINISHED');
        }
    }
})->with(['vercel', 'ag-ui']);

test('empty runs finish successfully and non-empty runs with unknown usage fail closed', function () {
    $empty = [
        new SwarmStreamStart('start', 'swarm-run-1', 'EmptySwarm', 'sequential', null, [], 1),
        new SwarmStreamEnd('end', 'swarm-run-1', '', [], [], 2),
    ];
    $unknown = successfulNativeProtocolEvents();
    $unknown[array_key_last($unknown)] = new SwarmStreamEnd('end', 'swarm-run-1', 'Hello', [], [], 7);

    $success = VercelDataStreamClient::consume(renderedProtocolContent(
        nativeProtocolStream($empty)->usingVercelDataProtocol('ui-message'),
    ));
    $failure = VercelDataStreamClient::consume(renderedProtocolContent(
        nativeProtocolStream($unknown)->usingVercelDataProtocol('ui-message'),
    ));

    expect(array_column($success, 'type'))->toContain('finish')
        ->and(array_column($failure, 'type'))->toContain('error')->not->toContain('finish', 'finish-step');
});

test('completed in-memory streams and equivalent replay streams produce the same protocol frames', function () {
    $events = successfulNativeProtocolEvents();
    $broadcasted = nativeProtocolStream($events);
    iterator_to_array($broadcasted);

    $fromMemory = renderedProtocolContent($broadcasted->usingVercelDataProtocol('ui-message'));
    $fromReplay = renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('ui-message'));

    expect(VercelDataStreamClient::consume($fromMemory))->toBe(VercelDataStreamClient::consume($fromReplay));
});

test('the adapter subclasses the installed Laravel AI encoders through their protected map seam', function () {
    $vercel = new ReflectionMethod(VercelDataProtocol::class, 'mapEvent');
    $agui = new ReflectionMethod(AgentUserInteractionProtocol::class, 'mapEvent');

    expect(is_subclass_of(VercelSwarmProtocol::class, VercelDataProtocol::class))->toBeTrue()
        ->and(is_subclass_of(AgentUserInteractionSwarmProtocol::class, AgentUserInteractionProtocol::class))->toBeTrue()
        ->and($vercel->isProtected())->toBeTrue()
        ->and($vercel->getNumberOfParameters())->toBe(1)
        ->and($agui->isProtected())->toBeTrue()
        ->and($agui->getNumberOfParameters())->toBe(1);
});
