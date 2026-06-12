<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeWideViewPropagationSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\ConversationDeclaringPropagationPolicy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Unit-level proof that the view builder gathers exactly the scopes a policy
 * declares via scopes() — no more — and honours the null-agent contract on the
 * durable / hierarchical-parallel paths. Covers the read-minimisation behaviour
 * without standing up a full runner.
 */
function memorySpy(): SwarmMemory
{
    return new class implements SwarmMemory
    {
        /** @var array<int, MemoryScope> */
        public array $scopesRead = [];

        public function get(MemoryScope $scope, string $scopeId, string $key): mixed
        {
            throw new BadMethodCallException('not exercised');
        }

        public function entry(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
        {
            throw new BadMethodCallException('not exercised');
        }

        public function put(MemoryScope $scope, string $scopeId, string $key, mixed $value, array $metadata = []): MemoryEntry
        {
            throw new BadMethodCallException('not exercised');
        }

        public function forget(MemoryScope $scope, string $scopeId, string $key): bool
        {
            throw new BadMethodCallException('not exercised');
        }

        public function all(MemoryScope $scope, string $scopeId): array
        {
            $this->scopesRead[] = $scope;

            return [];
        }
    };
}

function makeView(SwarmMemory $memory): AgentVisibleMemoryView
{
    return new AgentVisibleMemoryView(
        $memory,
        new SwarmAttributeResolver(app(ConfigRepository::class)),
        app(),
    );
}

test('the default policy reads only the Run scope', function () {
    $spy = memorySpy();

    makeView($spy)->present(new FakeSequentialSwarm, RunContext::fake(['run_id' => 'r1']), new FakeResearcher);

    expect($spy->scopesRead)->toBe([MemoryScope::Run]);
});

test('a wide policy reads every scope it declares when the agent is known', function () {
    $spy = memorySpy();

    makeView($spy)->present(new FakeWideViewPropagationSwarm, RunContext::fake(['run_id' => 'r1']), new FakeResearcher);

    expect($spy->scopesRead)->toBe([MemoryScope::Run, MemoryScope::Agent, MemoryScope::Swarm]);
});

test('the Agent scope is skipped when only the class-string is known (null agent)', function () {
    $spy = memorySpy();

    // Mirrors scope-driven callers (e.g. the Recall tool) that have no bound agent.
    makeView($spy)->present(new FakeWideViewPropagationSwarm, RunContext::fake(['run_id' => 'r1']), null);

    expect($spy->scopesRead)->toBe([MemoryScope::Run, MemoryScope::Swarm]);
});

test('a policy class that does not implement the contract throws', function () {
    config()->set('swarm.memory.propagation_policy', stdClass::class);

    expect(fn () => makeView(memorySpy())->present(new FakeSequentialSwarm, RunContext::fake(['run_id' => 'r1']), null))
        ->toThrow(SwarmException::class, 'must implement '.MemoryPropagationPolicy::class);
});

test('the Conversation scope is skipped when the run carries no conversation id', function () {
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);
    $spy = memorySpy();

    makeView($spy)->present(new FakeSequentialSwarm, RunContext::fake(['run_id' => 'r1']), new FakeResearcher);

    // Policy declared Conversation; view resolved the scope_id to null and skipped it.
    expect($spy->scopesRead)->not->toContain(MemoryScope::Conversation)
        ->and($spy->scopesRead)->toContain(MemoryScope::Run);
});

test('the Conversation scope is gathered when the run is bound to a conversation id', function () {
    config()->set('swarm.memory.propagation_policy', ConversationDeclaringPropagationPolicy::class);
    $spy = memorySpy();

    makeView($spy)->present(
        new FakeSequentialSwarm,
        RunContext::fake(['run_id' => 'r1', 'conversation_id' => 'conv-42']),
        new FakeResearcher,
    );

    expect($spy->scopesRead)->toContain(MemoryScope::Conversation);
});
