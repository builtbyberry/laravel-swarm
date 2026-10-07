<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryToolAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SecondDeclinedMemoryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemorySequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\WideViewPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarm\Tools\Remember;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * The HasSwarmMemoryTools concern exposes the Recall/Remember tools on an agent
 * only when `swarm.memory.tools.enabled` is true, and honours the per-tool
 * toggles — the "optional default-on registration via config" surface.
 */
function memoryToolClasses(): array
{
    return array_map(
        static fn (object $tool): string => $tool::class,
        [...(new MemoryToolAgent)->tools()],
    );
}

test('it exposes no tools when disabled (the default)', function () {
    config()->set('swarm.memory.tools.enabled', false);

    expect([...(new MemoryToolAgent)->tools()])->toBe([]);
});

test('it exposes both tools when enabled', function () {
    config()->set('swarm.memory.tools.enabled', true);

    expect(memoryToolClasses())->toBe([Recall::class, Remember::class]);
});

test('it honours the per-tool toggles', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.recall', true);
    config()->set('swarm.memory.tools.remember', false);

    expect(memoryToolClasses())->toBe([Recall::class]);
});

test('a bound subclass is resolved in place of the default tool', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.remember', false);

    app()->bind(Recall::class, fn () => new class extends Recall {});

    $tools = [...(new MemoryToolAgent)->tools()];

    // Locate the Recall-assignable tool by type rather than position, so the
    // assertion does not depend on registration order.
    $recall = collect($tools)->first(static fn (object $tool): bool => $tool instanceof Recall);

    expect($recall)->toBeInstanceOf(Recall::class);
    expect($recall::class)->not->toBe(Recall::class);
});

test('trait tools bind agent scope across later runs when propagation includes it', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.propagation_policy', WideViewPropagationPolicy::class);
    DeclinedMemoryAgent::fake([
        new ToolCall('call-1', 'remember', ['key' => 'preference', 'value' => 'concise', 'scope' => 'agent']),
        'stored',
    ]);

    DeclinedMemorySequentialSwarm::make()->prompt('remember');

    expect(app(SwarmMemory::class)->get(MemoryScope::Agent, DeclinedMemoryAgent::class, 'preference'))
        ->toBe('concise');

    DeclinedMemoryAgent::fake([
        new ToolCall('call-2', 'recall', ['key' => 'preference', 'scope' => 'agent']),
        'recalled',
    ]);
    $events = collect(iterator_to_array(DeclinedMemorySequentialSwarm::make()->stream('recall')));

    expect($events->whereInstanceOf(SwarmToolResult::class)->sole()->toolResult->result)
        ->toBe('preference: concise');
});

test('the default propagation policy does not surface bound agent memory', function () {
    config()->set('swarm.memory.tools.enabled', true);
    app(SwarmMemory::class)->put(MemoryScope::Agent, DeclinedMemoryAgent::class, 'preference', 'concise');
    DeclinedMemoryAgent::fake([
        new ToolCall('call-1', 'recall', ['key' => 'preference', 'scope' => 'agent']),
        'done',
    ]);

    $events = collect(iterator_to_array(DeclinedMemorySequentialSwarm::make()->stream('recall')));

    expect($events->whereInstanceOf(SwarmToolResult::class)->sole()->toolResult->result)
        ->toBe('No memory found for key [preference].');
});

test('a container-bound subclass is honoured and bound to the trait agent', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.remember', false);

    app()->bind(Recall::class, fn () => new class extends Recall
    {
        public function boundAgentClass(): ?string
        {
            $agent = $this->agent();

            return $agent === null ? null : $agent::class;
        }
    });

    $recall = collect((new MemoryToolAgent)->tools())->sole();

    expect($recall::class)->not->toBe(Recall::class)
        ->and($recall->boundAgentClass())->toBe(MemoryToolAgent::class);
});

test('a subclass agent override still wins after trait binding', function () {
    config()->set('swarm.memory.tools.enabled', true);
    config()->set('swarm.memory.tools.remember', false);

    app()->bind(Recall::class, fn () => new class extends Recall
    {
        public function agent(): ?Agent
        {
            return new SecondDeclinedMemoryAgent;
        }
    });

    $recall = collect((new MemoryToolAgent)->tools())->sole();

    expect($recall->agent())->toBeInstanceOf(SecondDeclinedMemoryAgent::class);
});

test('a model-supplied scope id is ignored for trait-bound agent memory', function () {
    config()->set('swarm.memory.tools.enabled', true);
    DeclinedMemoryAgent::fake([
        new ToolCall('call-1', 'remember', [
            'key' => 'preference',
            'value' => 'concise',
            'scope' => 'agent',
            'scope_id' => 'spoofed-agent',
        ]),
        'done',
    ]);

    DeclinedMemorySequentialSwarm::make()->prompt('remember');

    expect(app(SwarmMemory::class)->get(MemoryScope::Agent, DeclinedMemoryAgent::class, 'preference'))
        ->toBe('concise')
        ->and(app(SwarmMemory::class)->get(MemoryScope::Agent, 'spoofed-agent', 'preference'))
        ->toBeNull();
});

test('trait conversation scope fails without a run conversation and stores with one', function () {
    config()->set('swarm.memory.tools.enabled', true);
    $arguments = ['key' => 'topic', 'value' => 'launch', 'scope' => 'conversation'];
    DeclinedMemoryAgent::fake([new ToolCall('call-1', 'remember', $arguments), 'done']);

    $events = collect(iterator_to_array(DeclinedMemorySequentialSwarm::make()->stream('remember')));
    $result = $events->whereInstanceOf(SwarmToolResult::class)->sole();
    $stepEnd = $events->whereInstanceOf(SwarmStepEnd::class)->sole();

    expect($result->successful)->toBeFalse()
        ->and($stepEnd->nativeResult->toArray()['tools'][0]['status'])->toBe('failed');

    DeclinedMemoryAgent::fake([new ToolCall('call-2', 'remember', $arguments), 'done']);
    DeclinedMemorySequentialSwarm::make()->prompt(RunContext::fake([
        'run_id' => 'conversation-run',
        'input' => 'remember',
        'conversation_id' => 'conversation-1',
    ]));

    expect(app(SwarmMemory::class)->get(MemoryScope::Conversation, 'conversation-1', 'topic'))
        ->toBe('launch');
});
