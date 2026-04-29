<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\EmptyRunnableSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeRichStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStreamingFailureSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('sequential swarm stream yields ordered payloads and lifecycle events', function () {
    Event::fake();

    $stream = FakeSequentialSwarm::make()->stream('stream-task');

    expect($stream)->toBeInstanceOf(StreamableSwarmResponse::class);

    $events = iterator_to_array($stream);
    $completedEvent = Event::dispatched(SwarmCompleted::class)->first()[0];
    $history = app(RunHistoryStore::class)->find($completedEvent->runId);

    expect(array_map(fn ($event): string => $event->type(), $events))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
    expect($events[0])->toBeInstanceOf(SwarmStreamStart::class);
    expect($events[6])->toBeInstanceOf(SwarmTextDelta::class);
    expect($events[6]->delta)->toBe('editor-out');
    expect($events[9])->toBeInstanceOf(SwarmStreamEnd::class);

    Event::assertDispatched(SwarmStarted::class, fn (SwarmStarted $event) => $event->executionMode === 'stream');
    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 3);
    Event::assertDispatched(SwarmCompleted::class, fn (SwarmCompleted $event) => $event->output === 'editor-out');
    expect($completedEvent->metadata)
        ->toHaveKey('swarm_class', FakeSequentialSwarm::class)
        ->toHaveKey('last_agent', FakeEditor::class);
    expect($completedEvent->metadata['usage'])->toBeArray();
    expect($completedEvent->metadata['usage'])->not->toBe([]);
    expect($history['usage'])->toBe($completedEvent->metadata['usage']);
});

test('final streamed agent emits typed non-text upstream events', function () {
    $events = iterator_to_array(FakeRichStreamingSwarm::make()->stream('stream-task'));
    $types = array_map(fn ($event): string => $event->type(), $events);

    expect($types)->toContain('swarm_reasoning_delta');
    expect($types)->toContain('swarm_reasoning_end');
    expect($types)->toContain('swarm_tool_call');
    expect($types)->toContain('swarm_tool_result');
    expect($types)->toContain('swarm_text_end');
    expect(collect($events)->whereInstanceOf(SwarmReasoningDelta::class)->first()->delta)->toBe('thinking');
    expect(collect($events)->whereInstanceOf(SwarmToolCall::class)->first()->toolCall->name)->toBe('search_docs');
    expect(collect($events)->whereInstanceOf(SwarmToolResult::class)->first()->successful)->toBeTrue();
    expect(collect($events)->whereInstanceOf(SwarmTextEnd::class))->toHaveCount(1);
    expect(collect($events)->whereInstanceOf(SwarmReasoningEnd::class))->toHaveCount(1);
    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('editor-out');
});

test('persisted replay stores typed non-text upstream events in order', function () {
    $stream = FakeRichStreamingSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));
    $types = array_map(fn ($event): string => $event->type(), $stored);

    expect($types)->toContain('swarm_reasoning_delta');
    expect($types)->toContain('swarm_reasoning_end');
    expect($types)->toContain('swarm_tool_call');
    expect($types)->toContain('swarm_tool_result');
    expect($types)->toContain('swarm_text_end');

    $deltaIndex = array_search('swarm_text_delta', $types, true);
    $reasoningDeltaIndex = array_search('swarm_reasoning_delta', $types, true);
    $reasoningEndIndex = array_search('swarm_reasoning_end', $types, true);
    $toolCallIndex = array_search('swarm_tool_call', $types, true);
    $toolResultIndex = array_search('swarm_tool_result', $types, true);
    $textEndIndex = array_search('swarm_text_end', $types, true);
    $streamEndIndex = array_search('swarm_stream_end', $types, true);

    expect($deltaIndex)->toBeInt();
    expect($reasoningDeltaIndex)->toBeInt();
    expect($reasoningEndIndex)->toBeInt();
    expect($toolCallIndex)->toBeInt();
    expect($toolResultIndex)->toBeInt();
    expect($textEndIndex)->toBeInt();
    expect($streamEndIndex)->toBeInt();
    expect($deltaIndex)->toBeLessThan($reasoningDeltaIndex);
    expect($reasoningDeltaIndex)->toBeLessThan($reasoningEndIndex);
    expect($reasoningEndIndex)->toBeLessThan($toolCallIndex);
    expect($toolCallIndex)->toBeLessThan($toolResultIndex);
    expect($toolResultIndex)->toBeLessThan($textEndIndex);
    expect($textEndIndex)->toBeLessThan($streamEndIndex);
});

test('typed replay preserves upstream event ids and timestamps', function () {
    $stream = FakeRichStreamingSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));

    $textDelta = collect($stored)->whereInstanceOf(SwarmTextDelta::class)->first();
    $reasoningDelta = collect($stored)->whereInstanceOf(SwarmReasoningDelta::class)->first();
    $reasoningEnd = collect($stored)->whereInstanceOf(SwarmReasoningEnd::class)->first();
    $toolCall = collect($stored)->whereInstanceOf(SwarmToolCall::class)->first();
    $toolResult = collect($stored)->whereInstanceOf(SwarmToolResult::class)->first();
    $textEnd = collect($stored)->whereInstanceOf(SwarmTextEnd::class)->first();

    expect($textDelta->id)->toBe('delta-1');
    expect($reasoningDelta->id)->toBe('reasoning-delta-1');
    expect($reasoningEnd->id)->toBe('reasoning-end-1');
    expect($toolCall->id)->toBe('tool-call-1');
    expect($toolResult->id)->toBe('tool-result-1');
    expect($textEnd->id)->toBe('text-end-1');

    expect($textDelta->timestamp)->toBe(1_710_000_000);
    expect($reasoningDelta->timestamp)->toBe(1_710_000_000);
    expect($reasoningEnd->timestamp)->toBe(1_710_000_000);
    expect($toolCall->timestamp)->toBe(1_710_000_000);
    expect($toolResult->timestamp)->toBe(1_710_000_000);
    expect($textEnd->timestamp)->toBe(1_710_000_000);
});

test('stream construction is lazy and does not start execution before iteration', function () {
    Event::fake();

    $stream = FakeSequentialSwarm::make()->stream('lazy-stream-task');

    expect(app(RunHistoryStore::class)->find($stream->runId))->toBeNull();
    Event::assertNotDispatched(SwarmStarted::class);
    Event::assertNotDispatched(SwarmStepStarted::class);
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertNeverPrompted();
    FakeEditor::assertNeverPrompted();
});

test('sequential swarm stream accepts structured task input', function () {
    $events = iterator_to_array(FakeSequentialSwarm::make()->stream([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]));

    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('editor-out');
});

test('sequential swarm stream accepts explicit run contexts', function () {
    $events = iterator_to_array(FakeSequentialSwarm::make()->stream(RunContext::from([
        'input' => 'Draft a response for the customer.',
        'data' => ['ticket_id' => 'TKT-1234'],
        'metadata' => ['tenant_id' => 'acme'],
    ], 'stream-run-id')));

    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('editor-out');
});

test('stream response replays completed events in memory without re-executing', function () {
    $stream = FakeSequentialSwarm::make()->stream('stream-task');

    $first = iterator_to_array($stream);
    $second = iterator_to_array($stream);

    expect($second)->toBe($first);
    FakeResearcher::assertPrompted('stream-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');
});

test('then callbacks before and after completion receive the same streamed response', function () {
    $responses = [];
    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->then(function (StreamedSwarmResponse $response) use (&$responses): void {
            $responses[] = $response;
        });

    iterator_to_array($stream);

    $stream->then(function (StreamedSwarmResponse $response) use (&$responses): void {
        $responses[] = $response;
    });

    expect($responses)->toHaveCount(2);
    expect($responses[0])->toBe($responses[1]);
    expect($responses[0]->output)->toBe('editor-out');
});

test('stream response renders laravel ai style server sent events by default', function () {
    $_SERVER['LARAVEL_OCTANE'] = true;

    try {
        $response = FakeSequentialSwarm::make()->stream('stream-task')->toResponse(request());
    } finally {
        unset($_SERVER['LARAVEL_OCTANE']);
    }

    $callback = $response->getCallback();
    $content = implode('', iterator_to_array($callback()));

    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    expect($content)->toContain('data: {');
    expect($content)->toContain('"type":"swarm_text_delta"');
    expect($content)->toContain('data: [DONE]');
});

test('sequential swarm stream marks history failed and dispatches failure when the final agent throws mid stream', function () {
    Event::fake();

    $stream = FakeStreamingFailureSwarm::make()->stream('stream-task');
    $received = [];

    try {
        foreach ($stream as $event) {
            $received[] = $event;
        }

        $this->fail('Expected the streamed swarm to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Final agent stream failed.');
    }

    expect(array_map(fn ($event): string => $event->type(), $received))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_stream_error',
    ]);
    expect($received[6])->toBeInstanceOf(SwarmTextDelta::class);
    expect($received[6]->delta)->toBe('partial');
    expect($received[7])->toBeInstanceOf(SwarmStreamError::class);
    expect($received[7]->message)->toBe('Final agent stream failed.');

    Event::assertDispatchedTimes(SwarmStepStarted::class, 3);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 2);
    Event::assertDispatched(SwarmFailed::class, fn (SwarmFailed $event) => $event->exception->getMessage() === 'Final agent stream failed.');
    Event::assertNotDispatched(SwarmCompleted::class);

    $failedEvent = Event::dispatched(SwarmFailed::class)->first()[0];
    $history = app(RunHistoryStore::class)->find($failedEvent->runId);

    expect($history['status'])->toBe('failed');
    expect($history['error'])->toBe([
        'message' => 'Final agent stream failed.',
        'class' => RuntimeException::class,
    ]);
    expect($history['steps'])->toHaveCount(2);
});

test('then callback does not run when live stream fails', function () {
    $called = false;
    $stream = FakeStreamingFailureSwarm::make()
        ->stream('stream-task')
        ->then(function () use (&$called): void {
            $called = true;
        });

    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class);
    expect($called)->toBeFalse();
});

test('sequential swarm stream redacts failure events and history when capture is disabled', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);
    Event::fake();

    $stream = FakeStreamingFailureSwarm::make()->stream('sensitive-stream-task');

    try {
        foreach ($stream as $event) {
            //
        }

        $this->fail('Expected the streamed swarm to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Final agent stream failed.');
    }

    $failedEvent = Event::dispatched(SwarmFailed::class)->first()[0];
    $history = app(RunHistoryStore::class)->find($failedEvent->runId);

    expect($failedEvent->exception->getMessage())->toBe('[redacted]');
    expect($history['error'])->toBe([
        'message' => '[redacted]',
        'class' => RuntimeException::class,
    ]);
});

test('store for replay persists ordered stream events as they are yielded', function () {
    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));

    expect(array_map(fn ($event): string => $event->type(), $stored))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
});

test('globally enabled replay persists stream events without per response opt in', function () {
    config()->set('swarm.streaming.replay.enabled', true);

    $stream = FakeSequentialSwarm::make()->stream('stream-task');

    iterator_to_array($stream);

    expect(iterator_to_array(app(StreamEventStore::class)->events($stream->runId)))->not->toBeEmpty();
});

test('swarm history replay lazily replays persisted stream events', function () {
    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $replay = app(SwarmHistory::class)->replay($stream->runId);
    $events = iterator_to_array($replay);

    expect($replay)->toBeInstanceOf(StreamableSwarmResponse::class);
    expect(array_map(fn ($event): string => $event->type(), $events))->toContain('swarm_text_delta');
    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('editor-out');
});

test('swarm history replay throws clearly when no persisted stream events exist', function () {
    $replay = app(SwarmHistory::class)->replay('missing-run-id');

    expect(fn () => iterator_to_array($replay))
        ->toThrow(SwarmException::class, 'No persisted stream replay events exist for run [missing-run-id].');
});

test('replaying a failed stream yields through the error event without rethrowing', function () {
    $stream = FakeStreamingFailureSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class);

    $events = iterator_to_array(app(SwarmHistory::class)->replay($stream->runId));

    expect(array_map(fn ($event): string => $event->type(), $events))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_stream_error',
    ]);
    expect($events[array_key_last($events)])->toBeInstanceOf(SwarmStreamError::class);
});

test('persisted replay payloads honor capture redaction', function () {
    config()->set('swarm.capture.inputs', false);
    config()->set('swarm.capture.outputs', false);

    $stream = FakeSequentialSwarm::make()
        ->stream('sensitive-stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $events = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));

    expect($events[0])->toBeInstanceOf(SwarmStreamStart::class);
    expect($events[0]->input)->toBe('[redacted]');
    expect(collect($events)->whereInstanceOf(SwarmTextDelta::class)->first()->delta)->toBe('[redacted]');
});

test('persisted replay redacts reasoning deltas when output capture is disabled', function () {
    config()->set('swarm.capture.outputs', false);

    $stream = FakeRichStreamingSwarm::make()
        ->stream('sensitive-stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $events = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));
    $reasoningDelta = collect($events)->whereInstanceOf(SwarmReasoningDelta::class)->first();
    $toolCall = collect($events)->whereInstanceOf(SwarmToolCall::class)->first();

    expect($reasoningDelta)->not->toBeNull();
    expect($reasoningDelta->delta)->toBe('[redacted]');
    expect($reasoningDelta->summary)->toBe([0 => '[redacted]']);
    expect($toolCall)->not->toBeNull();
    expect($toolCall->toolCall->name)->toBe('search_docs');
    expect($toolCall->toolCall->arguments)->toBe(['query' => '[redacted]']);
    expect($toolCall->toolCall->reasoningSummary)->toBe([0 => '[redacted]']);

    $toolResult = collect($events)->whereInstanceOf(SwarmToolResult::class)->first();
    expect($toolResult)->not->toBeNull();
    expect($toolResult->toolResult->arguments)->toBe(['query' => '[redacted]']);
    expect($toolResult->toolResult->result)->toBe(['matches' => '[redacted]']);
});

test('database replay store preserves insertion order', function () {
    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $storedTypes = DB::table('swarm_stream_events')
        ->where('run_id', $stream->runId)
        ->orderBy('id')
        ->pluck('event_type')
        ->all();

    expect($storedTypes)->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
});

test('cache replay store preserves event order', function () {
    config()->set('swarm.streaming.replay.driver', 'cache');

    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));

    expect(array_map(fn ($event): string => $event->type(), $stored))->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
});

test('prune removes expired stream events while preserving active run stream events', function () {
    $expired = now()->subMinute();
    $future = now()->addMinute();

    DB::table('swarm_run_histories')->insert([
        [
            'run_id' => 'completed-run',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'completed',
            'context' => json_encode(RunContext::from('completed', 'completed-run')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => 'done',
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => $expired,
            'expires_at' => $expired,
            'created_at' => $expired,
            'updated_at' => $expired,
        ],
        [
            'run_id' => 'active-run',
            'swarm_class' => FakeSequentialSwarm::class,
            'topology' => 'sequential',
            'status' => 'running',
            'context' => json_encode(RunContext::from('active', 'active-run')->toArray()),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'output' => null,
            'usage' => json_encode([]),
            'error' => null,
            'artifacts' => json_encode([]),
            'finished_at' => null,
            'expires_at' => $future,
            'created_at' => $expired,
            'updated_at' => $expired,
        ],
    ]);
    DB::table('swarm_stream_events')->insert([
        [
            'run_id' => 'completed-run',
            'event_type' => 'swarm_stream_start',
            'payload' => json_encode(['type' => 'swarm_stream_start']),
            'expires_at' => $expired,
            'created_at' => $expired,
            'updated_at' => $expired,
        ],
        [
            'run_id' => 'active-run',
            'event_type' => 'swarm_stream_start',
            'payload' => json_encode(['type' => 'swarm_stream_start']),
            'expires_at' => $expired,
            'created_at' => $expired,
            'updated_at' => $expired,
        ],
    ]);

    Artisan::call('swarm:prune');

    expect(DB::table('swarm_stream_events')->where('run_id', 'completed-run')->exists())->toBeFalse();
    expect(DB::table('swarm_stream_events')->where('run_id', 'active-run')->exists())->toBeTrue();
});

test('non sequential swarms cannot be streamed', function () {
    $stream = fn () => iterator_to_array(FakeParallelSwarm::make()->stream('stream-task'));

    expect($stream)->toThrow(
        SwarmException::class,
        'Streaming is only supported for sequential swarms. parallel topology does not support streaming.',
    );
});

test('sequential swarm stream rejects empty agent lists', function () {
    $stream = fn () => iterator_to_array(EmptyRunnableSwarm::make()->stream('stream-task'));

    expect($stream)->toThrow(
        SwarmException::class,
        'EmptyRunnableSwarm: swarm has no agents. Add at least one agent to agents().',
    );
});

test('final streaming agent usage is captured in stream end event, completed event, and step metadata', function () {
    Event::fake();

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('stream-task'));

    $streamEnd = collect($events)->whereInstanceOf(SwarmStreamEnd::class)->first();
    $lastStepEnd = collect($events)->whereInstanceOf(SwarmStepEnd::class)->last();

    expect($streamEnd)->not->toBeNull();
    expect(array_key_exists('prompt_tokens', $streamEnd->usage))->toBeTrue();

    expect($lastStepEnd)->not->toBeNull();
    expect(array_key_exists('prompt_tokens', $lastStepEnd->metadata['usage']))->toBeTrue();

    Event::assertDispatched(
        SwarmCompleted::class,
        fn (SwarmCompleted $event) => array_key_exists('prompt_tokens', $event->metadata['usage'] ?? []),
    );
});

test('a throwing then callback does not set failedException and allows clean replay', function () {
    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->then(function (): void {
            throw new RuntimeException('callback failed');
        });

    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'callback failed');

    expect($stream->streamedResponse)->not->toBeNull();

    $replay = iterator_to_array($stream);

    expect(array_map(fn ($e) => $e->type(), $replay))->toContain('swarm_stream_end');
    expect(collect($replay)->last())->toBeInstanceOf(SwarmStreamEnd::class);
});

test('streamed step end output respects max output bytes limit', function () {
    config()->set('swarm.limits.max_output_bytes', 8);
    config()->set('swarm.limits.overflow', 'truncate');

    FakeEditor::fake(['editor-out']); // 10 bytes, exceeds 8-byte limit

    $events = iterator_to_array(FakeSequentialSwarm::make()->stream('stream-task'));

    /** @var SwarmStepEnd|null $editorStepEnd */
    $editorStepEnd = collect($events)
        ->whereInstanceOf(SwarmStepEnd::class)
        ->first(fn (SwarmStepEnd $event): bool => $event->agentClass === FakeEditor::class);

    expect($editorStepEnd)->not->toBeNull();
    expect(strlen($editorStepEnd->output))->toBeLessThanOrEqual(8);
    expect($editorStepEnd->output)->not->toBe('editor-out');

    $history = app(RunHistoryStore::class)->find(
        collect($events)->whereInstanceOf(SwarmStreamEnd::class)->first()->runId,
    );

    $historyEditorStep = collect($history['steps'] ?? [])
        ->first(fn (array $step): bool => ($step['agentClass'] ?? $step['agent_class'] ?? null) === FakeEditor::class);

    expect($historyEditorStep)->toBeArray();
    expect(strlen((string) ($historyEditorStep['output'] ?? '')))->toBeLessThanOrEqual(8);
});

test('persisted replay step and terminal payloads respect max output bytes limit', function () {
    config()->set('swarm.limits.max_output_bytes', 8);
    config()->set('swarm.limits.overflow', 'truncate');

    FakeEditor::fake(['editor-out']); // 10 bytes, exceeds 8-byte limit

    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    iterator_to_array($stream);

    $storedEvents = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));

    /** @var SwarmStepEnd|null $editorStepEnd */
    $editorStepEnd = collect($storedEvents)
        ->whereInstanceOf(SwarmStepEnd::class)
        ->first(fn (SwarmStepEnd $event): bool => $event->agentClass === FakeEditor::class);
    $streamEnd = collect($storedEvents)->whereInstanceOf(SwarmStreamEnd::class)->first();

    expect($editorStepEnd)->not->toBeNull();
    expect(strlen($editorStepEnd->output))->toBeLessThanOrEqual(8);
    expect($streamEnd)->not->toBeNull();
    expect(strlen($streamEnd->output))->toBeLessThanOrEqual(8);
});

test('stream replay persists emitted deltas before output overflow failure and omits terminal completion', function () {
    config()->set('swarm.limits.max_output_bytes', 8);
    config()->set('swarm.limits.overflow', 'fail');

    FakeResearcher::fake(['r']);
    FakeWriter::fake(['w']);
    FakeEditor::fake(['editor-out']); // 10 bytes, exceeds 8-byte limit

    $stream = FakeSequentialSwarm::make()
        ->stream('stream-task')
        ->storeForReplay();

    expect(fn () => iterator_to_array($stream))
        ->toThrow(SwarmException::class, 'exceeds the configured 8 byte limit.');

    $storedEvents = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));
    $storedTypes = array_map(fn ($event): string => $event->type(), $storedEvents);
    $editorStepEndCount = collect($storedEvents)
        ->whereInstanceOf(SwarmStepEnd::class)
        ->filter(fn (SwarmStepEnd $event): bool => $event->agentClass === FakeEditor::class)
        ->count();

    expect($storedTypes)->toContain('swarm_text_delta');
    expect($storedTypes)->toContain('swarm_stream_error');
    expect($storedTypes)->not->toContain('swarm_stream_end');
    expect($editorStepEndCount)->toBe(0);
});
