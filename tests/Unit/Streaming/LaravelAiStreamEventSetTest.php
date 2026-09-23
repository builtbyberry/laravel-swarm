<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Concerns\RecordsUnknownStreamEvents;
use BuiltByBerry\LaravelSwarm\Runners\NativeOutcomeValidator;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * Pin the upstream event inventory so additions and removals require explicit triage.
 *
 * @see StreamEventMapper
 * @see StaticHierarchicalStreamRunner
 * @see NativeOutcomeValidator
 * @see RecordsUnknownStreamEvents
 */

/** @var array<int, string> */
const HANDLED_AI_STREAM_EVENTS = [
    'Citation',
    'ProviderToolEvent',
    'Error',
    'ReasoningDelta',
    'ReasoningEnd',
    'StreamEnd',
    'TextDelta',
    'TextEnd',
    'ToolCall',
    'ToolResult',
];

/** @var array<int, string> */
const IGNORED_AI_STREAM_EVENTS = [
    'ReasoningStart',
    'StreamStart',
    'TextStart',
];

/** @var array<int, string> */
const REJECTED_AI_STREAM_EVENTS = ['ToolApprovalRequest'];

test('laravel/ai stream event set stays in lock-step with the runners triage', function (): void {
    $directory = dirname((new ReflectionClass(StreamEvent::class))->getFileName());

    $discovered = collect(glob($directory.'/*.php'))
        ->map(fn (string $path): string => basename($path, '.php'))
        // StreamEvent is the abstract base contract, not a concrete event.
        ->reject(fn (string $name): bool => $name === 'StreamEvent')
        ->sort()
        ->values()
        ->all();

    $triaged = collect(HANDLED_AI_STREAM_EVENTS)
        ->merge(IGNORED_AI_STREAM_EVENTS)
        ->merge(REJECTED_AI_STREAM_EVENTS)
        ->sort()
        ->values()
        ->all();

    expect($triaged)->toBe(
        $discovered,
        'A laravel/ai Streaming\\Events\\* class is not triaged. Review the owning mapper and validator '
        .'before adding it to HANDLED_AI_STREAM_EVENTS, IGNORED_AI_STREAM_EVENTS, or REJECTED_AI_STREAM_EVENTS.',
    );
});
