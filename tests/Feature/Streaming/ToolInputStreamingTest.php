<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Audit\ResolvedCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepStart;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolInputStart;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ToolInputStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksOutputWhenContains;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\CountingStepGuardrail;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\UnknownStreamEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclaresGuardrailsToolInputStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StaticHierarchicalToolInputStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ToolInputStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\WithoutToolInputDeltaBroadcastingSwarm;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * #649 (widened): laravel/ai v1.4.2 streams a tool call's input as the model writes
 * it (tool_input_start, tool_input_delta) and reports one that never completed
 * (tool_input_error). Swarm streams them live as swarm_tool_input_* through both
 * mapping chains (StreamEventMapper, and StaticHierarchicalStreamRunner's own),
 * persists them for replay, and no longer breadcrumbs the lifecycle starts it
 * deliberately skips. On laravel/ai before v1.4.2 the fixture stands in with the
 * same wire shape (WireToolInputEvent), so every case runs on both lanes.
 */
final class ToolInputCollectingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }

    /** @return list<list<string>> */
    public function breadcrumbEventTypes(): array
    {
        return array_values(array_map(
            fn (array $record): array => $record['context']['event_types'],
            array_filter($this->records, fn (array $record): bool => str_contains($record['message'], 'unrecognized stream event type')),
        ));
    }
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ToolInputStreamEditor::$withUnknown = false;

    $this->logger = new ToolInputCollectingLogger;
    app()->instance(LoggerInterface::class, $this->logger);
});

afterEach(function () {
    ToolInputStreamEditor::$withUnknown = false;
});

/**
 * @param  list<SwarmStreamEvent>  $events
 * @return list<SwarmStreamEvent>
 */
function toolInputToolEvents(array $events): array
{
    return array_values(array_filter($events, fn (SwarmStreamEvent $event): bool => str_starts_with($event->type(), 'swarm_tool_')));
}

/**
 * @param  class-string  $swarm
 * @return list<SwarmStreamEvent>
 */
function toolInputStream(string $swarm): array
{
    return iterator_to_array($swarm::make()->stream('tool-input-task'), false);
}

dataset('tool input chains', [
    'sequential (StreamEventMapper)' => [ToolInputStreamSwarm::class, false],
    'static hierarchical (own chain)' => [StaticHierarchicalToolInputStreamSwarm::class, true],
]);

test('tool input streams live as swarm events, in order, through both chains', function (string $swarm, bool $tagsNode) {
    $events = toolInputToolEvents(toolInputStream($swarm));

    expect(array_map(fn (SwarmStreamEvent $event): string => $event->type(), $events))->toBe([
        'swarm_tool_input_start',
        'swarm_tool_input_delta',
        'swarm_tool_input_delta',
        'swarm_tool_call',
        'swarm_tool_result',
        'swarm_tool_input_start',
        'swarm_tool_input_delta',
        'swarm_tool_input_error',
    ]);

    [$start, $first, $second, , , $cutStart, $cutDelta, $error] = $events;

    expect($start)->toBeInstanceOf(SwarmToolInputStart::class)
        ->and($start->toolId)->toBe('tool-1')
        ->and($start->toolName)->toBe('lookup')
        ->and($start->id)->toBe('tool-input-start-1')
        ->and($first)->toBeInstanceOf(SwarmToolInputDelta::class)
        ->and($first->delta.$second->delta)->toBe('{"query":"'.ToolInputStreamEditor::ARGUMENT_SECRET.'"}')
        ->and($cutStart->toolName)->toBe('search')
        ->and($cutDelta->toolId)->toBe('tool-2')
        ->and($error)->toBeInstanceOf(SwarmToolInputError::class)
        ->and($error->toolId)->toBe('tool-2')
        ->and($error->toolName)->toBe('search')
        ->and($error->input)->toBe('{"q":"'.ToolInputStreamEditor::ARGUMENT_SECRET)
        ->and($error->error)->toBe(ToolInputStreamEditor::ERROR_SECRET)
        ->and($error->toArray()['payload_status'])->toBe('available');

    foreach ([$start, $first, $error] as $event) {
        expect($event->nodeId)->toBe($tagsNode ? 'editor_node' : null);
    }
})->with('tool input chains');

test('the lifecycle starts Swarm skips leave no breadcrumb, and a genuinely unknown event still does', function (string $swarm) {
    toolInputStream($swarm);

    expect($this->logger->breadcrumbEventTypes())->toBe([]);

    ToolInputStreamEditor::$withUnknown = true;
    toolInputStream($swarm);

    expect($this->logger->breadcrumbEventTypes())->toBe([[UnknownStreamEvent::class]]);
})->with('tool input chains');

test('persisted replay reproduces the live tool input events exactly', function (string $swarm) {
    $stream = $swarm::make()->stream('tool-input-task');
    $live = toolInputToolEvents(iterator_to_array($stream, false));

    $replayed = toolInputToolEvents(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId), false));

    expect(array_map(fn (SwarmStreamEvent $event): string => $event::class, $replayed))
        ->toBe(array_map(fn (SwarmStreamEvent $event): string => $event::class, $live))
        ->and(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $replayed))
        ->toBe(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $live));
})->with('tool input chains');

test('withheld capture keeps every tool input event but never its fragments, live and replayed', function (string $swarm, bool $tagsNode, CaptureDecision $decision, string $status, ?string $error) {
    app()->instance(CapturePolicy::class, new ResolvedCapturePolicy(CaptureDecision::Full, $decision));

    $stream = $swarm::make()->stream('tool-input-task');
    $live = toolInputToolEvents(iterator_to_array($stream, false));
    $replayed = toolInputToolEvents(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId), false));

    foreach (['live' => $live, 'replayed' => $replayed] as $events) {
        $inputs = array_values(array_filter($events, fn (SwarmStreamEvent $event): bool => str_starts_with($event->type(), 'swarm_tool_input_')));

        expect(array_map(fn (SwarmStreamEvent $event): string => $event->type(), $inputs))->toBe([
            'swarm_tool_input_start', 'swarm_tool_input_delta', 'swarm_tool_input_delta',
            'swarm_tool_input_start', 'swarm_tool_input_delta', 'swarm_tool_input_error',
        ]);

        foreach ($inputs as $event) {
            expect($event->toArray()['payload_status'])->toBe($status)
                ->and($event->toArray()['tool_id'])->toBeIn(['tool-1', 'tool-2']);
        }

        expect($inputs[1]->delta)->toBeNull()
            ->and($inputs[0]->toolName)->toBe('lookup')
            ->and($inputs[5]->toolName)->toBe('search')
            ->and($inputs[5]->input)->toBeNull()
            ->and($inputs[5]->error)->toBe($error);

        $encoded = json_encode(array_map(fn (SwarmStreamEvent $event): array => $event->toArray(), $inputs));

        expect($encoded)->not->toContain(ToolInputStreamEditor::ARGUMENT_SECRET)
            ->and($encoded)->not->toContain(ToolInputStreamEditor::ERROR_SECRET);
    }
})->with('tool input chains')->with([
    'redact' => [CaptureDecision::Redact, 'redacted', '[redacted]'],
    'skip' => [CaptureDecision::Skip, 'omitted', null],
]);

test('the frozen snapshot records only the tool call that ran, not the cut-off one', function (string $swarm) {
    $stream = $swarm::make()->stream('tool-input-task');
    iterator_to_array($stream, false);

    $snapshot = app(SnapshotsMemory::class)->find($stream->runId, 0);

    expect($snapshot)->not->toBeNull()
        ->and(array_column($snapshot->toolCalls, 'name'))->toBe(['lookup']);
})->with('tool input chains');

/**
 * @return list<AnonymousEvent>
 */
function toolInputBroadcasts(): array
{
    return Event::dispatched(AnonymousEvent::class)
        ->map(fn (array $event): AnonymousEvent => $event[0])
        ->values()
        ->all();
}

test('tool input events broadcast under their swarm type with a capture-shaped body', function () {
    Event::fake([AnonymousEvent::class]);

    ToolInputStreamSwarm::make()->broadcast('tool-input-task', new Channel('swarm.run'));

    $byType = collect(toolInputBroadcasts())->groupBy(fn (AnonymousEvent $event): string => $event->broadcastAs());

    expect($byType->keys()->all())->toContain('swarm_tool_input_start', 'swarm_tool_input_delta', 'swarm_tool_input_error')
        ->and($byType['swarm_tool_input_delta'])->toHaveCount(3)
        ->and($byType['swarm_tool_input_error'][0]->broadcastWith())->toMatchArray([
            'type' => 'swarm_tool_input_error',
            'tool_id' => 'tool-2',
            'tool_name' => 'search',
            'error' => ToolInputStreamEditor::ERROR_SECRET,
            'payload_status' => 'available',
        ]);
});

test('WithoutBroadcasting can drop tool input deltas from broadcast while replay keeps them', function () {
    Event::fake([AnonymousEvent::class]);

    $response = WithoutToolInputDeltaBroadcastingSwarm::make()->broadcast('tool-input-task', new Channel('swarm.run'));
    $types = array_map(fn (AnonymousEvent $event): string => $event->broadcastAs(), toolInputBroadcasts());

    expect($types)->not->toContain('swarm_tool_input_delta')
        ->and($types)->toContain('swarm_tool_input_start', 'swarm_tool_input_error');

    $replayed = toolInputToolEvents(iterator_to_array(app(SwarmHistory::class)->replay($response->runId), false));

    expect(array_map(fn (SwarmStreamEvent $event): string => $event->type(), $replayed))->toContain('swarm_tool_input_delta');
});

test('the sequential runner marks only its final step live, and a live stream projects it live while replay buffers (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $stream = FakeSequentialSwarm::make()->stream('task');
    // Live only before its first iteration; a consumed stream replays buffered.
    $liveBeforeIteration = $stream->streamsFinalAgentLive();
    $starts = array_values(array_filter(iterator_to_array($stream, false), fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart));

    expect(array_map(fn (SwarmStepStart $start): bool => $start->liveFinalStep, $starts))->toBe([false, false, true])
        ->and($liveBeforeIteration)->toBeTrue()
        ->and($stream->streamsFinalAgentLive())->toBeFalse()
        ->and(app(SwarmHistory::class)->replay($stream->runId)->streamsFinalAgentLive())->toBeFalse();

    config()->set('swarm.streaming.native_protocols.final_agent_live', false);

    expect(FakeSequentialSwarm::make()->stream('task')->streamsFinalAgentLive())->toBeFalse();
});

test('a step or output guardrail keeps the final step buffered, so a rejected answer never reaches the client (#649)', function (string $phase, string $guardrail) {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    config()->set("swarm.guardrails.{$phase}", [$guardrail]);
    app()->bind(BlocksOutputWhenContains::class, fn (): BlocksOutputWhenContains => new BlocksOutputWhenContains('looking up'));
    app()->bind(BlocksStepWhenIndex::class, fn (): BlocksStepWhenIndex => new BlocksStepWhenIndex(0));

    $stream = ToolInputStreamSwarm::make()->stream('tool-input-task');
    $events = [];

    try {
        foreach ($stream as $event) {
            $events[] = $event;
        }
    } catch (Throwable) {
    }

    $start = collect($events)->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart);

    expect($start->liveFinalStep)->toBeFalse();
})->with([
    'output guardrail' => ['output', BlocksOutputWhenContains::class],
    'step guardrail' => ['step', BlocksStepWhenIndex::class],
]);

test('an output guardrail that rejects the answer leaves a FinalAgent client with no answer frames, only an error (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    config()->set('swarm.guardrails.output', [BlocksOutputWhenContains::class]);
    app()->bind(BlocksOutputWhenContains::class, fn (): BlocksOutputWhenContains => new BlocksOutputWhenContains('looking up'));

    $_SERVER['LARAVEL_OCTANE'] = true;

    try {
        $response = ToolInputStreamSwarm::make()->stream('tool-input-task')
            ->usingVercelDataProtocol('ui-message', NativeProtocolProjection::FinalAgent)
            ->toResponse(request());
        $body = '';

        try {
            foreach (($response->getCallback())() as $chunk) {
                $body .= $chunk;
            }
        } catch (Throwable) {
        }
    } finally {
        unset($_SERVER['LARAVEL_OCTANE']);
    }

    expect($body)->not->toContain('looking up')
        ->and($body)->not->toContain('"tool-input-start"')
        ->and($body)->toContain('"type":"error"');
});

test('the final step is marked live only with native protocols and the live switch both on (#649)', function (bool $protocols, bool $live, bool $expected) {
    config()->set('swarm.streaming.native_protocols.enabled', $protocols);
    config()->set('swarm.streaming.native_protocols.final_agent_live', $live);

    $start = collect(iterator_to_array(ToolInputStreamSwarm::make()->stream('tool-input-task'), false))
        ->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart);

    expect($start->liveFinalStep)->toBe($expected);
})->with([
    'native protocols off' => [false, true, false],
    'live projection off' => [true, false, false],
    'live projection possible' => [true, true, true],
]);

test('the live check resolves no guardrail: a configured one is validated once, as before (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    config()->set('swarm.guardrails.step', [CountingStepGuardrail::class]);
    $resolutions = 0;
    app()->bind(CountingStepGuardrail::class, function () use (&$resolutions): CountingStepGuardrail {
        $resolutions++;

        return new CountingStepGuardrail;
    });

    iterator_to_array(ToolInputStreamSwarm::make()->stream('tool-input-task'), false);

    expect($resolutions)->toBe(1);
});

test('a swarm that declares guardrails through its hook stays buffered, even if the hook returns none yet (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    DeclaresGuardrailsToolInputStreamSwarm::$outputGuardrails = [];

    try {
        $start = collect(iterator_to_array(DeclaresGuardrailsToolInputStreamSwarm::make()->stream('tool-input-task'), false))
            ->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart);
    } finally {
        DeclaresGuardrailsToolInputStreamSwarm::$outputGuardrails = [];
    }

    // The hook is asked again at validation time and could add an output guardrail
    // by then, so its content is never released before that answer.
    expect($start->liveFinalStep)->toBeFalse();
});

test('a faked stream marks its last step live like a real sequential stream, under the same switch (#649)', function (bool $live) {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', $live);
    FakeSequentialSwarm::fake(['faked answer']);

    $stream = FakeSequentialSwarm::make()->stream('task');
    $starts = array_values(array_filter(iterator_to_array($stream, false), fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart));

    expect(end($starts)->liveFinalStep)->toBe($live);
})->with([true, false]);

test('an output guardrail a SwarmStepStarted listener adds keeps the final step buffered, and its rejection never reaches the client (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    app()->bind(BlocksOutputWhenContains::class, fn (): BlocksOutputWhenContains => new BlocksOutputWhenContains('looking up'));
    Event::listen(SwarmStepStarted::class, function (): void {
        config()->set('swarm.guardrails.output', [BlocksOutputWhenContains::class]);
    });

    $_SERVER['LARAVEL_OCTANE'] = true;

    try {
        $response = ToolInputStreamSwarm::make()->stream('tool-input-task')
            ->usingVercelDataProtocol('ui-message', NativeProtocolProjection::FinalAgent)
            ->toResponse(request());
        $body = '';

        try {
            foreach (($response->getCallback())() as $chunk) {
                $body .= $chunk;
            }
        } catch (Throwable) {
        }
    } finally {
        unset($_SERVER['LARAVEL_OCTANE']);
    }

    expect($body)->not->toContain('live_final_step')
        ->and($body)->not->toContain('looking up')
        ->and($body)->toContain('"type":"error"');
});

test('a child run that inherits its parent guardrails keeps the final step buffered; one that does not may stream live (#649)', function (string $inheritance, bool $expected) {
    config()->set('swarm.streaming.native_protocols.enabled', true);
    config()->set('swarm.streaming.native_protocols.final_agent_live', true);
    config()->set('swarm.guardrails.child_inheritance', $inheritance);

    $context = RunContext::from('tool-input-task');
    $context->mergeMetadata(['parent_run_id' => 'parent-run-649']);

    $start = collect(iterator_to_array(ToolInputStreamSwarm::make()->stream($context), false))
        ->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart);

    expect($start->liveFinalStep)->toBe($expected);
})->with([
    'inherits the parent' => ['own_global_and_parent', false],
    'does not inherit' => ['own_and_global', true],
]);

test('live FinalAgent answers are opt-in: with native protocols on and nothing else set, the final step is buffered (#649)', function () {
    config()->set('swarm.streaming.native_protocols.enabled', true);

    $stream = ToolInputStreamSwarm::make()->stream('tool-input-task');
    $liveBeforeIteration = $stream->streamsFinalAgentLive();
    $start = collect(iterator_to_array($stream, false))
        ->first(fn (SwarmStreamEvent $event): bool => $event instanceof SwarmStepStart);

    expect(config('swarm.streaming.native_protocols.final_agent_live'))->toBeFalse()
        ->and($liveBeforeIteration)->toBeFalse()
        ->and($start->liveFinalStep)->toBeFalse();
});
