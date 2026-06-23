<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Runners\Concerns\RecordsUnknownStreamEvents;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * F1 guard for the laravel/ai floor (issue #255).
 *
 * The two streaming runners (SequentialRunner, StaticHierarchicalStreamRunner)
 * map a fixed set of laravel/ai `Streaming\Events\*` types into swarm events and
 * the durable snapshot; everything else falls into a log-once `else` breadcrumb
 * that — by design — never throws. That is the right failure mode for a benign
 * lifecycle event, but it means a NEW content-bearing event introduced by an
 * upstream bump would be silently dropped from the snapshot with only a log
 * line as evidence. A green suite does not catch that on its own.
 *
 * This test pins the upstream event set so any addition (or removal) fails the
 * suite loudly and forces a deliberate re-triage of the runner match chains,
 * rather than letting a new event slip through the breadcrumb unnoticed.
 *
 * @see RecordsUnknownStreamEvents
 */

/**
 * Event types the runner match chains explicitly handle (mapped to a
 * SwarmStreamEvent and/or recorded in the durable snapshot).
 *
 * @var array<int, string>
 */
const HANDLED_AI_STREAM_EVENTS = [
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
 * Event types the runners deliberately do NOT map: provider lifecycle and
 * non-content-bearing markers that carry nothing the snapshot needs. They flow
 * through the breadcrumb `else` today without loss of durable content. Listed
 * here so the set is triaged, not ignored — promote one to HANDLED if a future
 * release starts capturing it.
 *
 * @var array<int, string>
 */
const IGNORED_AI_STREAM_EVENTS = [
    'Citation',
    'ProviderToolEvent',
    'ReasoningStart',
    'StreamStart',
    'TextStart',
];

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
        ->sort()
        ->values()
        ->all();

    // A laravel/ai bump that adds (or removes) a Streaming\Events\* class fails
    // here until it is triaged into HANDLED_AI_STREAM_EVENTS (map it in both
    // runners) or IGNORED_AI_STREAM_EVENTS (confirmed safe to let it breadcrumb).
    expect($triaged)->toBe(
        $discovered,
        'A laravel/ai Streaming\\Events\\* class is not triaged. Add it to HANDLED_AI_STREAM_EVENTS '
        .'(and map it in SequentialRunner + StaticHierarchicalStreamRunner) or to IGNORED_AI_STREAM_EVENTS '
        .'(confirmed it carries no durable content and may fall through the breadcrumb).',
    );
});
