<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Events\NativeProtocolProjectionFailed;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\UnsupportedNativeApprovalException;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\NativeStepResult;
use BuiltByBerry\LaravelSwarm\Responses\ProviderToolData;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmCitation as CitationItem;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalVoidEdge;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolAttemptInvalidated;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;
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
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\AgentUserInteractionClient;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Protocols\VercelDataStreamClient;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
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
        new SwarmTextDelta('delta', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'Hello', 4, 'content-block-1', payloadAvailability: PayloadAvailability::Available),
        new SwarmTextEnd('text-end', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'content-block-1', 5, payloadAvailability: PayloadAvailability::Available),
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

test('native protocol selection cannot change while a stream is being iterated', function () {
    $stream = nativeProtocolStream(successfulNativeProtocolEvents());
    $iterator = $stream->getIterator();
    $iterator->rewind();

    expect(fn () => $stream->usingVercelDataProtocol('ui-message'))
        ->toThrow(SwarmException::class, 'while the stream is being iterated')
        ->and(fn () => $stream->usingAgentUserInteractionProtocol('thread'))
        ->toThrow(SwarmException::class, 'while the stream is being iterated');

    while ($iterator->valid()) {
        $iterator->next();
    }
});

test('production stream runners project successful workflows through both native protocols', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);

    $makeStream = function (string $topology): StreamableSwarmResponse {
        FakeResearcher::fake(['research']);
        FakeWriter::fake(['writer']);
        FakeEditor::fake(['editor']);
        FakeHierarchicalCoordinator::fake([
            HierarchicalTestPlan::make('writer_node', [
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => FakeWriter::class,
                    'prompt' => 'writer-task',
                ],
            ]),
        ]);

        return match ($topology) {
            'sequential' => FakeSequentialSwarm::make()->stream('task'),
            'hierarchical' => FakeHierarchicalStreamSwarm::make()->stream('task'),
            'static_hierarchical' => FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('task'),
        };
    };

    foreach (['sequential', 'hierarchical', 'static_hierarchical'] as $topology) {
        foreach (['vercel', 'ag-ui'] as $protocol) {
            $stream = $makeStream($topology);
            $frames = $protocol === 'vercel'
                ? VercelDataStreamClient::consume(renderedProtocolContent($stream->usingVercelDataProtocol('message')))
                : AgentUserInteractionClient::consume(renderedProtocolContent($stream->usingAgentUserInteractionProtocol('thread')));
            $types = array_column($frames, 'type');
            $payloads = array_values(array_filter(array_map(
                static fn (array $frame): ?array => match ($frame['type'] ?? null) {
                    'data-swarm' => $frame['data'] ?? null,
                    'CUSTOM' => $frame['value'] ?? null,
                    default => null,
                },
                $frames,
            )));
            $eventTypes = array_column($payloads, 'event_type');

            expect($types)->toContain($protocol === 'vercel' ? 'finish' : 'RUN_FINISHED')
                ->and($eventTypes)->toContain('workflow_completed');

            if ($topology !== 'sequential') {
                expect($eventTypes)->toContain('node_opened', 'node_children_decided', 'node_closed');
            }
        }
    }
});

test('workflow projection maps every hierarchy and causal control event through both protocol clients', function (
    SwarmStreamEvent $controlEvent,
    string $eventType,
    array $expected,
    array $forbidden,
) {
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'StaticSwarm', 'static_hierarchical', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        $controlEvent,
        new SwarmStepEnd('step-end', 'swarm-run-1', 0, 'Agent', 'Agent', 'done', 1, [], 8, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', 'done', ['input_tokens' => 1, 'output_tokens' => 1], [], 9),
    ];

    foreach (['vercel', 'ag-ui'] as $protocol) {
        $stream = nativeProtocolStream($events, 'static_hierarchical');
        $frames = $protocol === 'vercel'
            ? VercelDataStreamClient::consume(renderedProtocolContent($stream->usingVercelDataProtocol('message')))
            : AgentUserInteractionClient::consume(renderedProtocolContent($stream->usingAgentUserInteractionProtocol('thread')));
        $payloads = array_values(array_filter(array_map(
            static fn (array $frame): ?array => match ($frame['type'] ?? null) {
                'data-swarm' => $frame['data'] ?? null,
                'CUSTOM' => $frame['value'] ?? null,
                default => null,
            },
            $frames,
        )));
        $payload = collect($payloads)->firstWhere('event_type', $eventType);

        expect($payload)->toMatchArray([
            'run_id' => 'swarm-run-1',
            'event_type' => $eventType,
            ...$expected,
        ])->not->toHaveKeys(array_keys($forbidden));

        foreach ($forbidden as $sentinel) {
            expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain($sentinel);
        }
    }
})->with([
    'node opened' => [
        (new SwarmNodeOpened('node-open', 'swarm-run-1', 'parent', 'worker', 'because', 3))->withNodeId('node'),
        'node_opened',
        ['node_id' => 'node', 'parent_node_id' => 'parent', 'role' => 'worker'],
        ['rationale' => 'because'],
    ],
    'node children decided' => [
        (new SwarmNodeChildrenDecided('children', 'swarm-run-1', ['child-a', 'child-b'], 'because', 4))->withNodeId('node'),
        'node_children_decided',
        ['node_id' => 'node', 'child_node_ids' => ['child-a', 'child-b']],
        ['rationale' => 'because'],
    ],
    'node closed' => [
        (new SwarmNodeClosed('node-close', 'swarm-run-1', 'private result', 5))->withNodeId('node'),
        'node_closed',
        ['node_id' => 'node'],
        ['result' => 'private result'],
    ],
    'causal void edge' => [
        (new SwarmCausalVoidEdge('void', 'swarm-run-1', CausalVoidEdgeType::Supersedes, 'target', 'private reason', 6, 'digest'))->withNodeId('node'),
        'causal_event_voided',
        ['node_id' => 'node', 'void_type' => 'supersedes', 'target_event_id' => 'target', 'digest_node_id' => 'digest'],
        ['reason' => 'private reason'],
    ],
    'provider tool attempt invalidated' => [
        new SwarmProviderToolAttemptInvalidated('invalidated', 'swarm-run-1', 'node', 2, 6),
        'provider_tool_attempt_invalidated',
        ['node_id' => 'node', 'before_epoch' => 2, 'attempt_epoch' => 2],
        [],
    ],
    'causal seal barrier' => [
        (new SwarmCausalSealBarrier('seal', 'swarm-run-1', 7))->withNodeId('node'),
        'causal_log_sealed',
        ['node_id' => 'node'],
        [],
    ],
]);

test('workflow projection preserves branch-local identity without inventing standard message order', function () {
    $first = (new SwarmTextDelta('branch-a-0', 'swarm-run-1', 0, 'AgentA', 'A', 3, 'message-a', payloadAvailability: PayloadAvailability::Available))
        ->withBranchIdentity('parallel:0', 'attempt-a', 0);
    $second = (new SwarmTextDelta('branch-b-0', 'swarm-run-1', 1, 'AgentB', 'B', 4, 'message-b', payloadAvailability: PayloadAvailability::Available))
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
    $vercelAdapter = new ReflectionClass(VercelSwarmProtocol::class);
    $aguiAdapter = new ReflectionClass(AgentUserInteractionSwarmProtocol::class);

    expect(is_subclass_of(VercelSwarmProtocol::class, VercelDataProtocol::class))->toBeTrue()
        ->and(is_subclass_of(AgentUserInteractionSwarmProtocol::class, AgentUserInteractionProtocol::class))->toBeTrue()
        ->and($vercelAdapter->getDocComment())->toContain('@internal')
        ->and($aguiAdapter->getDocComment())->toContain('@internal')
        ->and($vercel->isProtected())->toBeTrue()
        ->and($vercel->getNumberOfParameters())->toBe(1)
        ->and($agui->isProtected())->toBeTrue()
        ->and($agui->getNumberOfParameters())->toBe(1);
});

test('caller-owned protocol identities are validated before source iteration', function (string $protocol, string $identity) {
    $iterations = 0;
    $stream = nativeProtocolStream([], after: function () use (&$iterations): void {
        $iterations++;
    });

    $configure = fn () => $protocol === 'vercel'
        ? $stream->usingVercelDataProtocol($identity)
        : $stream->usingAgentUserInteractionProtocol($identity);

    expect($configure)->toThrow(SwarmException::class)
        ->and($iterations)->toBe(0);
})->with([
    'Vercel blank' => ['vercel', '   '],
    'Vercel oversized' => ['vercel', str_repeat('a', 513)],
    'Vercel invalid UTF-8' => ['vercel', "\xC3\x28"],
    'Vercel control byte' => ['vercel', "message\nrow"],
    'AG-UI blank' => ['ag-ui', '   '],
    'AG-UI oversized' => ['ag-ui', str_repeat('a', 513)],
    'AG-UI invalid UTF-8' => ['ag-ui', "\xC3\x28"],
    'AG-UI control byte' => ['ag-ui', "thread\rrow"],
]);

test('AG-UI refuses a caller run identity that differs from the Swarm run identity', function () {
    expect(fn () => nativeProtocolStream([])->usingAgentUserInteractionProtocol('thread', 'different-run'))
        ->toThrow(SwarmException::class, 'must be the Swarm run ID');
});

test('final agent projection stays lazy enough for protocol disconnect abandonment', function (string $protocol) {
    $abandoned = false;
    $completed = false;
    $stream = new StreamableSwarmResponse(
        runId: 'disconnect-run',
        generator: function () use (&$completed): Generator {
            yield new SwarmStreamStart('start', 'disconnect-run', 'Swarm', 'sequential', null, [], 1);
            yield new SwarmStepStart('step', 'disconnect-run', 0, 'Agent', 'Agent', null, 2);
            $completed = true;
            yield new SwarmStreamEnd('end', 'disconnect-run', '', ['input_tokens' => 0, 'output_tokens' => 0], [], 3);
        },
        onAbandoned: function () use (&$abandoned): void {
            $abandoned = true;
        },
        topology: 'sequential',
        nativeChatProtocolsEnabled: true,
    );

    $configured = $protocol === 'vercel'
        ? $stream->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent)
        : $stream->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent);
    $_SERVER['LARAVEL_OCTANE'] = true;
    $chunks = ($configured->toResponse(request())->getCallback())();
    $chunks->current();
    unset($chunks);
    gc_collect_cycles();
    unset($_SERVER['LARAVEL_OCTANE']);

    expect($completed)->toBeFalse()
        ->and($abandoned)->toBeTrue();
})->with(['vercel', 'ag-ui']);

test('final agent projection never reuses a stale completed step when the final step is incomplete', function (string $protocol) {
    $events = successfulNativeProtocolEvents();
    array_pop($events);
    $events[] = new SwarmStepStart('second-step', 'swarm-run-1', 1, 'SecondAgent', 'Second', null, 7);
    $events[] = new SwarmTextDelta('second-delta', 'swarm-run-1', 1, 'SecondAgent', 'unfinished', 8, 'second-message', PayloadAvailability::Available);
    $events[] = new SwarmStreamEnd('end', 'swarm-run-1', 'unfinished', ['input_tokens' => 2, 'output_tokens' => 1], [], 9);

    if ($protocol === 'vercel') {
        $frames = VercelDataStreamClient::consume(renderedProtocolContent(
            nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent),
        ));
        expect(array_column($frames, 'type'))->toContain('error')->not->toContain('finish', 'finish-step')
            ->and(json_encode($frames))->not->toContain('Hello', 'unfinished');
    } else {
        $frames = AgentUserInteractionClient::consume(renderedProtocolContent(
            nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent),
        ));
        expect(array_column($frames, 'type'))->toContain('RUN_ERROR')->not->toContain('RUN_FINISHED', 'STEP_FINISHED')
            ->and(json_encode($frames))->not->toContain('Hello', 'unfinished');
    }
})->with(['vercel', 'ag-ui']);

test('final agent projection does not let a stale step end close a newer step', function (string $protocol) {
    $events = successfulNativeProtocolEvents();
    array_pop($events);
    $events[] = new SwarmStepStart('second-step', 'swarm-run-1', 1, 'SecondAgent', 'Second', null, 7);
    $events[] = new SwarmTextDelta('second-delta', 'swarm-run-1', 1, 'SecondAgent', 'unfinished', 8, 'second-message', PayloadAvailability::Available);
    $events[] = new SwarmStepEnd('stale-step-end', 'swarm-run-1', 0, 'App\\Agents\\Writer', 'Writer', 'Hello', 1, [], 9, nativeResult: new NativeStepResult);
    $events[] = new SwarmStreamEnd('end', 'swarm-run-1', 'unfinished', ['input_tokens' => 2, 'output_tokens' => 1], [], 10);

    if ($protocol === 'vercel') {
        $frames = VercelDataStreamClient::consume(renderedProtocolContent(
            nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent),
        ));
        expect(array_column($frames, 'type'))->toContain('error')->not->toContain('finish', 'finish-step')
            ->and(json_encode($frames))->not->toContain('Hello', 'unfinished');
    } else {
        $frames = AgentUserInteractionClient::consume(renderedProtocolContent(
            nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent),
        ));
        expect(array_column($frames, 'type'))->toContain('RUN_ERROR')->not->toContain('RUN_FINISHED', 'STEP_FINISHED')
            ->and(json_encode($frames))->not->toContain('Hello', 'unfinished');
    }
})->with(['vercel', 'ag-ui']);

test('final agent projection selects only the last completed step without fabricating message-row identity', function (string $protocol) {
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step-1', 'swarm-run-1', 0, 'AgentOne', 'One', null, 2),
        new SwarmTextDelta('delta-1', 'swarm-run-1', 0, 'AgentOne', 'discard-me', 3, 'block-1', PayloadAvailability::Available),
        new SwarmTextEnd('end-1', 'swarm-run-1', 0, 'AgentOne', 'block-1', 4, PayloadAvailability::Available),
        new SwarmStepEnd('step-end-1', 'swarm-run-1', 0, 'AgentOne', 'One', 'discard-me', 1, [], 5, nativeResult: new NativeStepResult),
        new SwarmStepStart('step-2', 'swarm-run-1', 1, 'AgentTwo', 'Two', null, 6),
        new SwarmTextDelta('delta-2', 'swarm-run-1', 1, 'AgentTwo', 'keep-me', 7, 'block-2', PayloadAvailability::Available),
        new SwarmTextEnd('end-2', 'swarm-run-1', 1, 'AgentTwo', 'block-2', 8, PayloadAvailability::Available),
        new SwarmStepEnd('step-end-2', 'swarm-run-1', 1, 'AgentTwo', 'Two', 'keep-me', 1, [], 9, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', 'keep-me', ['input_tokens' => 2, 'output_tokens' => 1], [], 10),
    ];

    $frames = $protocol === 'vercel'
        ? VercelDataStreamClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent)))
        : AgentUserInteractionClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent)));
    $encoded = json_encode($frames);

    expect($encoded)->toContain('keep-me')->not->toContain('discard-me', 'assistantMessageId', 'userMessageId');
})->with(['vercel', 'ag-ui']);

test('text capture availability governs native conversation output without leaking withheld values', function (string $protocol, PayloadAvailability $availability) {
    $secret = 'secret-'.$availability->value;
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        new SwarmTextDelta('delta', 'swarm-run-1', 0, 'Agent', $secret, 3, 'block', $availability),
        new SwarmTextEnd('text-end', 'swarm-run-1', 0, 'Agent', 'block', 4, $availability),
        new SwarmStepEnd('step-end', 'swarm-run-1', 0, 'Agent', 'Agent', $availability === PayloadAvailability::Available ? $secret : null, 1, [], 5, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', $availability === PayloadAvailability::Available ? $secret : null, ['input_tokens' => 1, 'output_tokens' => 1], [], 6),
    ];

    $frames = $protocol === 'vercel'
        ? VercelDataStreamClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent)))
        : AgentUserInteractionClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent)));
    $encoded = json_encode($frames);
    $standardType = $protocol === 'vercel' ? 'text-delta' : 'TEXT_MESSAGE_CONTENT';

    if ($availability === PayloadAvailability::Available) {
        expect(array_column($frames, 'type'))->toContain($standardType)
            ->and($encoded)->toContain($secret);
    } else {
        expect(array_column($frames, 'type'))->not->toContain($standardType)
            ->and($encoded)->not->toContain($secret)
            ->and($encoded)->toContain('"content_status":"'.$availability->value.'"');
    }
})->with([
    'Vercel full' => ['vercel', PayloadAvailability::Available],
    'Vercel redact' => ['vercel', PayloadAvailability::Redacted],
    'Vercel skip' => ['vercel', PayloadAvailability::Omitted],
    'Vercel legacy' => ['vercel', PayloadAvailability::Unknown],
    'AG-UI full' => ['ag-ui', PayloadAvailability::Available],
    'AG-UI redact' => ['ag-ui', PayloadAvailability::Redacted],
    'AG-UI skip' => ['ag-ui', PayloadAvailability::Omitted],
    'AG-UI legacy' => ['ag-ui', PayloadAvailability::Unknown],
]);

test('tool payload availability and preliminary results retain their confidentiality contract', function (string $protocol, PayloadAvailability $availability) {
    $secret = 'tool-secret-'.$availability->value;
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        new SwarmToolCall('call', 'swarm-run-1', 0, 'Agent', new ToolCallData('tool-1', 'lookup', ['query' => $secret], null), 3, $availability),
        new SwarmToolResult('result', 'swarm-run-1', 0, 'Agent', new ToolResultData('tool-1', 'lookup', ['answer' => $secret], null), true, null, 4, preliminary: true, payloadAvailability: $availability),
        new SwarmStepEnd('step-end', 'swarm-run-1', 0, 'Agent', 'Agent', null, 1, [], 5, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', null, ['input_tokens' => 1, 'output_tokens' => 0], [], 6),
    ];

    $frames = $protocol === 'vercel'
        ? VercelDataStreamClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent)))
        : AgentUserInteractionClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent)));
    $encoded = json_encode($frames);

    if ($availability === PayloadAvailability::Available) {
        expect($encoded)->toContain($secret);
    } else {
        expect($encoded)->not->toContain($secret)
            ->and($encoded)->toContain('"payload_status":"'.$availability->value.'"');
    }
})->with([
    'Vercel full' => ['vercel', PayloadAvailability::Available],
    'Vercel redact' => ['vercel', PayloadAvailability::Redacted],
    'Vercel skip' => ['vercel', PayloadAvailability::Omitted],
    'Vercel legacy' => ['vercel', PayloadAvailability::Unknown],
    'AG-UI full' => ['ag-ui', PayloadAvailability::Available],
    'AG-UI redact' => ['ag-ui', PayloadAvailability::Redacted],
    'AG-UI skip' => ['ag-ui', PayloadAvailability::Omitted],
    'AG-UI legacy' => ['ag-ui', PayloadAvailability::Unknown],
]);

test('provider payloads and citation message identities are never fabricated', function (string $protocol) {
    $availableCitation = new CitationEvidence([
        new CitationItem(
            url: 'https://example.com/identified',
            title: 'Identified source',
            runId: 'swarm-run-1',
            stepIndex: 0,
            agentClass: 'Agent',
            messageId: 'block',
            eventId: 'citation-item-1',
            timestamp: 5,
        ),
    ], CitationEvidence::AVAILABLE);
    $unidentifiedCitation = new CitationEvidence([
        new CitationItem(
            url: 'https://example.com/unidentified',
            title: 'Unidentified source',
            runId: 'swarm-run-1',
            stepIndex: 0,
            agentClass: 'Agent',
        ),
    ], CitationEvidence::AVAILABLE);
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        new SwarmProviderToolEvent('provider-full', 'swarm-run-1', 0, 'Agent', 'provider-1', 'search_call', 'searching', 'fixture', 3, ProviderToolData::capture(['query' => 'visible-provider-value'])),
        new SwarmProviderToolEvent('provider-hidden', 'swarm-run-1', 0, 'Agent', 'provider-2', 'search_call', 'searching', 'fixture', 4, ProviderToolData::withheld('redacted')),
        new SwarmCitation('citation-full', 'swarm-run-1', 0, 'Agent', 'block', 5, $availableCitation),
        new SwarmCitation('citation-no-row', 'swarm-run-1', 0, 'Agent', null, 6, $unidentifiedCitation),
        new SwarmStepEnd('step-end', 'swarm-run-1', 0, 'Agent', 'Agent', null, 1, [], 7, nativeResult: new NativeStepResult),
        new SwarmStreamEnd('end', 'swarm-run-1', null, ['input_tokens' => 1, 'output_tokens' => 0], [], 8),
    ];

    $frames = $protocol === 'vercel'
        ? VercelDataStreamClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('message', NativeProtocolProjection::FinalAgent)))
        : AgentUserInteractionClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread', projection: NativeProtocolProjection::FinalAgent)));
    $encoded = json_encode($frames);

    expect($encoded)->toContain('visible-provider-value', 'Identified source', 'Unidentified source', '"payload_status":"redacted"', '"message_id":null')
        ->and($encoded)->not->toContain('"messageId":"citation-no-row"');
    if ($protocol === 'vercel') {
        expect(array_values(array_filter($frames, fn (array $frame): bool => ($frame['type'] ?? null) === 'source-url')))->toHaveCount(1);
    } else {
        expect(array_values(array_filter($frames, fn (array $frame): bool => ($frame['type'] ?? null) === 'CUSTOM' && ($frame['name'] ?? null) === 'citation')))->toHaveCount(1);
    }
})->with(['vercel', 'ag-ui']);

test('workflow projection preserves visible progress before a terminal partial failure', function (string $protocol) {
    $events = [
        new SwarmStreamStart('start', 'swarm-run-1', 'Swarm', 'sequential', null, [], 1),
        new SwarmStepStart('step', 'swarm-run-1', 0, 'Agent', 'Agent', null, 2),
        new SwarmTextDelta('delta', 'swarm-run-1', 0, 'Agent', 'visible-prefix', 3, 'block', PayloadAvailability::Available),
        new SwarmStreamError('error', 'swarm-run-1', 'provider failed', RuntimeException::class, false, [], 4),
    ];

    $frames = $protocol === 'vercel'
        ? VercelDataStreamClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingVercelDataProtocol('message')))
        : AgentUserInteractionClient::consume(renderedProtocolContent(nativeProtocolStream($events)->usingAgentUserInteractionProtocol('thread')));
    $types = array_column($frames, 'type');

    expect(json_encode($frames))->toContain('visible-prefix')
        ->and($types)->toContain($protocol === 'vercel' ? 'error' : 'RUN_ERROR')
        ->not->toContain($protocol === 'vercel' ? 'finish' : 'RUN_FINISHED');
})->with(['vercel', 'ag-ui']);

test('persisted replay and reconnect reproduce live protocol frames for both clients', function (string $protocol) {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    $store = app(StreamEventStore::class);
    $runId = 'persisted-native-'.$protocol;
    $store->forget($runId);
    $events = array_map(function (SwarmStreamEvent $event) use ($runId): SwarmStreamEvent {
        $payload = $event->toArray();
        $payload['run_id'] = $runId;

        return SwarmStreamEvent::fromArray($payload);
    }, successfulNativeProtocolEvents());
    $source = new StreamableSwarmResponse(
        runId: $runId,
        generator: function () use ($events): Generator {
            foreach ($events as $event) {
                yield $event;
            }
        },
        streamEvents: $store,
        topology: 'sequential',
        nativeChatProtocolsEnabled: true,
    );
    $source->storeForReplay();
    iterator_to_array($source);

    $live = $protocol === 'vercel'
        ? renderedProtocolContent($source->usingVercelDataProtocol('message'))
        : renderedProtocolContent($source->usingAgentUserInteractionProtocol('thread'));
    $replay = app(SwarmHistory::class)->replay($runId);
    $reconnected = $protocol === 'vercel'
        ? renderedProtocolContent($replay->usingVercelDataProtocol('message'))
        : renderedProtocolContent($replay->usingAgentUserInteractionProtocol('thread'));

    expect($reconnected)->toBe($live);
})->with(['vercel', 'ag-ui']);

test('adapter-only terminal failures invoke the operational correlation hook', function () {
    $failures = [];
    $events = successfulNativeProtocolEvents();
    $events[array_key_last($events)] = new SwarmStreamEnd('end', 'swarm-run-1', 'Hello', [], [], 7);
    $stream = new StreamableSwarmResponse(
        runId: 'swarm-run-1',
        generator: function () use ($events): Generator {
            foreach ($events as $event) {
                yield $event;
            }
        },
        topology: 'sequential',
        nativeChatProtocolsEnabled: true,
        onNativeProtocolFailure: function (string $runId, string $protocol, NativeProtocolProjection $projection, string $reason) use (&$failures): void {
            $failures[] = new NativeProtocolProjectionFailed($runId, $protocol, $projection, $reason, 1);
        },
    );

    VercelDataStreamClient::consume(renderedProtocolContent($stream->usingVercelDataProtocol('message')));

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->runId)->toBe('swarm-run-1')
        ->and($failures[0]->protocol)->toBe('vercel')
        ->and($failures[0]->projection)->toBe(NativeProtocolProjection::Workflow)
        ->and($failures[0]->reason)->toBe('swarm_usage_unavailable');
});

test('a failing operational correlation hook cannot suppress the terminal projection error', function () {
    $events = successfulNativeProtocolEvents();
    $events[array_key_last($events)] = new SwarmStreamEnd('end', 'swarm-run-1', 'Hello', [], [], 7);
    $stream = new StreamableSwarmResponse(
        runId: 'swarm-run-1',
        generator: function () use ($events): Generator {
            foreach ($events as $event) {
                yield $event;
            }
        },
        topology: 'sequential',
        nativeChatProtocolsEnabled: true,
        onNativeProtocolFailure: function (): void {
            throw new LogicException('listener failed');
        },
    );

    $frames = VercelDataStreamClient::consume(renderedProtocolContent($stream->usingVercelDataProtocol('message')));
    $diagnostics = array_values(array_filter($frames, fn (array $frame): bool => ($frame['type'] ?? null) === 'data-swarm'));
    $terminal = $frames[array_key_last($frames)];

    expect($diagnostics[array_key_last($diagnostics)]['data'])->toMatchArray([
        'event_type' => 'projection_error',
        'run_id' => 'swarm-run-1',
        'projection' => NativeProtocolProjection::Workflow->value,
        'reason' => 'swarm_usage_unavailable',
    ])->and($terminal)->toBe([
        'type' => 'error',
        'errorText' => 'The swarm stream failed.',
    ]);
});
