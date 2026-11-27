<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Concerns\RecordsUnknownStreamEvents;
use BuiltByBerry\LaravelSwarm\Runners\NativeOutcomeValidator;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use Composer\InstalledVersions;
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

/**
 * Handled since laravel/ai v1.4.2, which added them: the tool-input events
 * (mapped by ToolInputEventMapper into swarm_tool_input_*). Expected only when
 * the installed laravel/ai is at least that version, then compared with the
 * discovered directory like every other name, so a later removal still fails.
 *
 * @var array<int, string>
 */
const HANDLED_AI_STREAM_EVENTS_SINCE_1_4_2 = [
    'ToolInputDelta',
    'ToolInputError',
    'ToolInputStart',
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

    // A branch install (dev-main, 1.x-dev) is newer than every tagged release.
    $laravelAi = (string) InstalledVersions::getVersion('laravel/ai');
    $isBranch = str_starts_with($laravelAi, 'dev-') || str_ends_with($laravelAi, '-dev');
    $sinceToolInput = $isBranch || version_compare(ltrim($laravelAi, 'v'), '1.4.2', '>=')
        ? HANDLED_AI_STREAM_EVENTS_SINCE_1_4_2
        : [];

    $triaged = collect(HANDLED_AI_STREAM_EVENTS)
        ->merge($sinceToolInput)
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
