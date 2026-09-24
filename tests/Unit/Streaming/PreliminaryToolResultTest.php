<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use BuiltByBerry\LaravelSwarm\Streaming\StreamStepAccumulator;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmExecutionState;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\RecordingSnapshotsMemory;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ToolResult as NativeToolResult;

covers(SwarmToolResult::class, StreamEventMapper::class);

it('strictly reads progress and independent denied flags with historical fallback', function (array $fields, mixed $nestedDenied, bool $preliminary, bool $denied) {
    $payload = [
        'type' => 'swarm_tool_result', 'id' => 'event', 'run_id' => 'run', 'step_index' => 0,
        'agent_class' => 'Agent', 'timestamp' => 1710000000, 'invocation_id' => 'parent', 'node_id' => 'worker',
        'successful' => true, 'error' => 'independent error',
        'tool_result' => ['id' => 'call', 'name' => 'lookup', 'arguments' => [], 'result' => 'partial', 'denied' => $nestedDenied, 'failed' => true],
        ...$fields,
    ];
    $event = SwarmStreamEvent::fromArray($payload);
    expect($event->preliminary)->toBe($preliminary)->and($event->denied)->toBe($denied)
        ->and($event->toolResult->denied)->toBe(is_bool($nestedDenied) ? $nestedDenied : false)
        ->and($event->toolResult->failed)->toBeTrue()->and($event->successful)->toBeTrue()
        ->and($event->error)->toBe('independent error')->and($event->invocationId)->toBe('parent')
        ->and($event->nodeId)->toBe('worker');
    $wire = json_decode(json_encode($event->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($wire['preliminary'])->toBe($preliminary)->and($wire['denied'])->toBe($denied)
        ->and(SwarmStreamEvent::fromArray($wire)->toArray())->toBe($wire);
})->with([
    'old denied' => [[], true, false, true],
    'old ordinary' => [[], false, false, false],
    'old malformed nested' => [[], 'true', false, false],
    'conflicting false' => [['preliminary' => true, 'denied' => false], true, true, false],
    'conflicting true' => [['preliminary' => false, 'denied' => true], false, false, true],
    'string' => [['preliminary' => 'true', 'denied' => 'false'], true, false, true],
    'number' => [['preliminary' => 1, 'denied' => 0], false, false, false],
    'null' => [['preliminary' => null, 'denied' => null], true, false, true],
    'array' => [['preliminary' => [true], 'denied' => []], 'false', false, false],
    'fraction' => [['preliminary' => 0.5, 'denied' => 0.5], true, false, true],
]);

it('keeps the old constructor source-compatible with default false event flags', function () {
    $event = new SwarmToolResult('event', 'run', 0, 'Agent', new ToolResult('call', 'lookup', [], 'result', denied: true), false, null, 1);
    expect($event->preliminary)->toBeFalse()->and($event->denied)->toBeFalse()
        ->and($event->toolResult->denied)->toBeTrue();
});

it('maps long interleaved preliminary results without retaining their payloads or completing snapshots', function () {
    $recorder = new RecordingSnapshotsMemory;
    app()->instance(SnapshotsMemory::class, $recorder);
    $context = RunContext::from('ordinary', 'direct-map');
    $state = new SwarmExecutionState(new FakeSequentialSwarm, Topology::Sequential, ExecutionMode::Run,
        hrtime(true) + 60_000_000_000, 10, 3600, null, null, null, $context,
        app(ContextStore::class), app(ArtifactRepository::class), app(RunHistoryStore::class), app('events'));
    $accumulator = new StreamStepAccumulator($recorder->snapshot('direct-map', 0, []));
    $mapper = app(StreamEventMapper::class);
    $pendingSize = null;
    foreach (PreliminaryResultAgent::events('ordinary') as $native) {
        $mapped = $mapper->map($native, $state, 0, new PreliminaryResultAgent, $accumulator);
        if ($native instanceof NativeToolResult && $native->preliminary) {
            $pendingSize ??= strlen(serialize($accumulator->pendingToolCalls));
            expect($mapped->preliminary)->toBeTrue()
                ->and($recorder->toolCallAppends)->toBe([])
                ->and($accumulator->snapshot->toolCalls)->toBe([])
                ->and(array_keys($accumulator->pendingToolCalls))->toBe(['a', 'b'])
                ->and(strlen(serialize($accumulator->pendingToolCalls)))->toBe($pendingSize)
                ->and(serialize($accumulator))->not->toContain('partial-secret');
        }
    }
    expect($recorder->toolCallAppends)->toHaveCount(2)
        ->and($accumulator->pendingToolCalls)->toBe([])
        ->and(array_column($accumulator->snapshot->toolCalls, 'result'))->toBe(['complete-secret-b', 'complete-secret-a']);
});
