<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\ConversationPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\SwarmMemoryKeys;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

function runEntry(string $key, mixed $value): MemoryEntry
{
    return new MemoryEntry(MemoryScope::Run, 'run-1', $key, $value);
}

/**
 * @param  array<int, MemoryEntry>  $entries
 * @return array<int, string>
 */
function presentedKeys(ConversationPropagationPolicy $policy, array $entries): array
{
    return array_map(
        static fn (MemoryEntry $entry): string => $entry->key,
        $policy->present($entries, RunContext::fake(), null),
    );
}

test('it declares the Run scope', function () {
    expect((new ConversationPropagationPolicy)->scopes())->toBe([MemoryScope::Run]);
});

test('it surfaces step outputs ordered by step index regardless of insertion order', function () {
    $policy = new ConversationPropagationPolicy;

    $keys = presentedKeys($policy, [
        runEntry(SwarmMemoryKeys::stepOutput(2), 'c'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
        runEntry(SwarmMemoryKeys::stepOutput(10), 'k'),
        runEntry(SwarmMemoryKeys::stepOutput(1), 'b'),
    ]);

    expect($keys)->toBe([
        'swarm:step.0.output',
        'swarm:step.1.output',
        'swarm:step.2.output',
        'swarm:step.10.output',
    ]);
});

test('by default it shows the transcript only, hiding other Run keys', function () {
    $policy = new ConversationPropagationPolicy(includeRunMemory: false);

    $keys = presentedKeys($policy, [
        runEntry('last_output', 'final'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
        runEntry('brief', 'do the thing'),
        runEntry(SwarmMemoryKeys::stepOutput(1), 'b'),
    ]);

    expect($keys)->toBe(['swarm:step.0.output', 'swarm:step.1.output']);
});

test('with include_run_memory it appends the remaining Run keys after the transcript', function () {
    $policy = new ConversationPropagationPolicy(includeRunMemory: true);

    $keys = presentedKeys($policy, [
        runEntry('last_output', 'final'),
        runEntry(SwarmMemoryKeys::stepOutput(1), 'b'),
        runEntry('brief', 'do the thing'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
    ]);

    // Transcript first (ordered), then the rest in their candidate order.
    expect($keys)->toBe(['swarm:step.0.output', 'swarm:step.1.output', 'last_output', 'brief']);
});

test('it drops non-Run candidates', function () {
    $policy = new ConversationPropagationPolicy(includeRunMemory: true);

    $keys = presentedKeys($policy, [
        new MemoryEntry(MemoryScope::Swarm, 'SwarmClass', 'shared', 'x'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
    ]);

    expect($keys)->toBe(['swarm:step.0.output']);
});

test('the container resolves the policy with the configured include_run_memory flag', function () {
    config()->set('swarm.memory.conversation_view.include_run_memory', true);

    $keys = presentedKeys(app(ConversationPropagationPolicy::class), [
        runEntry('last_output', 'final'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
    ]);

    expect($keys)->toBe(['swarm:step.0.output', 'last_output']);

    config()->set('swarm.memory.conversation_view.include_run_memory', false);
    app()->forgetInstance(ConversationPropagationPolicy::class);

    $keys = presentedKeys(app(ConversationPropagationPolicy::class), [
        runEntry('last_output', 'final'),
        runEntry(SwarmMemoryKeys::stepOutput(0), 'a'),
    ]);

    expect($keys)->toBe(['swarm:step.0.output']);
});
