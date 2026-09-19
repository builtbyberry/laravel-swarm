<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Tests\Unit\Streaming\Fixtures\LegacyToolResult;
use Laravel\Ai\Responses\Data\ToolResult;

// Attribute coverage to the C3 mapping boundary, not incidental application boot.
covers(SwarmToolResult::class);

it('reads additive native result flags without changing historical defaults', function (array $flags, bool $denied, bool $failed) {
    $payload = [
        'type' => 'swarm_tool_result', 'id' => 'native-event', 'invocation_id' => 'native-invocation',
        'node_id' => 'worker', 'run_id' => 'workflow', 'step_index' => 2, 'agent_class' => 'Agent', 'timestamp' => 1710000000,
        'tool_result' => ['id' => 'call', 'name' => 'tool', 'arguments' => [], 'result' => 'message', 'result_id' => 'result', ...$flags],
        'successful' => ! $denied && ! $failed, 'error' => $denied || $failed ? 'message' : null,
    ];
    $event = SwarmStreamEvent::fromArray(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($event->toolResult->denied)->toBe($denied)->and($event->toolResult->failed)->toBe($failed)
        ->and($event->invocationId)->toBe('native-invocation')->and($event->nodeId)->toBe('worker')
        ->and($event->toolResult->resultId)->toBe('result');
})->with([
    'historical' => [[], false, false],
    'denied' => [['denied' => true], true, false],
    'failed' => [['failed' => true], false, true],
    'both' => [['denied' => true, 'failed' => true], true, true],
    'explicit false' => [['denied' => false, 'failed' => false], false, false],
    'malformed is not truthy' => [['denied' => 'false', 'failed' => 1], false, false],
]);

it('demonstrates old-reader parsing is not correction-preserving downgrade safety', function (bool $denied, bool $failed) {
    $data = new ToolResult('call', 'tool', ['input' => 'x'], 'not executed', denied: $denied, failed: $failed);
    $candidate = new SwarmToolResult('event', 'run', 0, 'Agent', $data, false, 'not executed', 1710000000);
    $wire = json_decode(json_encode($candidate->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $old = LegacyToolResult::fromArray($wire);
    $current = SwarmToolResult::fromArray($wire);
    expect($old->id)->toBe('event')->and($old->successful)->toBeFalse()
        ->and($old->toolResult->successful())->toBeTrue()
        ->and($old->toolResult->denied)->toBeFalse()->and($old->toolResult->failed)->toBeFalse()
        ->and($current->toolResult->successful())->toBeFalse()
        ->and($current->toolResult->denied)->toBe($denied)->and($current->toolResult->failed)->toBe($failed);
})->with([[true, false], [false, true], [true, true]]);
