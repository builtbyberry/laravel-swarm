<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * F2 drift guard for the laravel/ai ^0.8 floor (issue #255).
 *
 * SwarmToolCall::toArray() is deliberately pinned to a swarm-owned subset of
 * the provider ToolCall (id, name, arguments, result_id, reasoning_id,
 * reasoning_summary) and intentionally drops reasoning_encrypted_content (the
 * opaque ZDR blob). That pin is a safe default, but it has a blind spot: if a
 * future laravel/ai release adds a field swarm *should* carry, the pin would
 * silently drop it — the inverse of the silent-drop this release is hardening
 * against in the stream-event set and the snapshot normalizer.
 *
 * This test fails loud when Data\ToolCall's property set changes, forcing a
 * deliberate carry-vs-ignore triage for SwarmToolCall::toArray() rather than a
 * silent omission. The expected list is a HARDCODED LITERAL — never derived
 * from reflection — so the assertion cannot drift in lockstep with the DTO it
 * guards.
 *
 * @see SwarmToolCall::toArray()
 */
test('Data\\ToolCall property set is pinned so upstream additions force a triage', function (): void {
    // Hardcoded snapshot of the laravel/ai 0.8 ToolCall surface. Bump this list
    // ONLY after deciding whether each new field belongs in SwarmToolCall::toArray().
    $expected = [
        'id',
        'name',
        'arguments',
        'resultId',
        'reasoningId',
        'reasoningSummary',
        'reasoningEncryptedContent',
    ];

    $actual = collect((new ReflectionClass(ToolCall::class))->getProperties())
        ->map(fn (ReflectionProperty $p): string => $p->getName())
        ->sort()
        ->values()
        ->all();

    expect($actual)->toBe(
        collect($expected)->sort()->values()->all(),
        'laravel/ai Data\\ToolCall changed its property set. Decide whether each added/removed field '
        .'belongs in SwarmToolCall::toArray() (carry it) or stays excluded (e.g. another opaque blob like '
        .'reasoning_encrypted_content), then update this hardcoded list to match.',
    );
});
