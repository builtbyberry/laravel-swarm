<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamError;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ZdrStreamEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalZdrSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeZdrStreamingSwarm;

/**
 * Exercise the synthetic opaque-field fixture through both streaming paths.
 *
 * @see ZdrStreamEditor
 */
dataset('zdr_swarms', [
    'sequential runner' => [fn () => FakeZdrStreamingSwarm::make()],
    'static-hierarchical runner' => [fn () => FakeStaticHierarchicalZdrSwarm::make()],
]);

test('a ZDR reasoning stream (null summary + encrypted tool call) completes without crashing', function (callable $makeSwarm): void {
    $events = iterator_to_array($makeSwarm()->stream('zdr-task'));

    // The stream drained to its terminal event with no provider/runner error —
    // i.e. the reasoning-summary and tool-call capture paths tolerated the ZDR
    // shape rather than throwing.
    expect(collect($events)->whereInstanceOf(SwarmStreamError::class))->toHaveCount(0);
    expect(collect($events)->whereInstanceOf(SwarmStreamEnd::class))->toHaveCount(1);

    // Assert the reasoning events are PRESENT before inspecting them, so a path
    // that silently drops them fails loud here rather than passing vacuously on
    // a null-safe summary check.
    $reasoningDeltas = collect($events)->whereInstanceOf(SwarmReasoningDelta::class);
    $reasoningEnds = collect($events)->whereInstanceOf(SwarmReasoningEnd::class);
    $toolCalls = collect($events)->whereInstanceOf(SwarmToolCall::class);
    expect($reasoningDeltas)->toHaveCount(1);
    expect($reasoningEnds)->toHaveCount(1);
    expect($toolCalls)->toHaveCount(1);

    // A non-null delta still flows through capture and produces its value...
    expect($reasoningDeltas->first()->delta)->toBe('thinking');
    // ...while the null ZDR summary survives capture as null on both events.
    expect($reasoningDeltas->first()->summary)->toBeNull();
    expect($reasoningEnds->first()->summary)->toBeNull();

    // The opaque encrypted-reasoning blob must never reach the serialized swarm
    // event contract (broadcast/persistence).
    expect(json_encode($toolCalls->first()->toArray()))
        ->not->toContain('OPAQUE_ENCRYPTED_REASONING')
        ->not->toContain('OPAQUE_THOUGHT_SIGNATURE')
        ->not->toContain('thought_signature');
})->with('zdr_swarms');
