<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Agent;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryToolScopeResolver;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Laravel\Ai\Promptable;

/**
 * MemoryToolScopeResolver maps a requested scope to the concrete (scope,
 * scopeId) address, taking the id from the active run rather than the caller.
 */
afterEach(function () {
    ActiveRunContext::flush();
});

function enterResolverRun(): void
{
    ActiveRunContext::enter('run-7', FakeSequentialSwarm::class, RunContext::fake(['run_id' => 'run-7', 'input' => 'go']));
}

test('it resolves the run scope to the active run id', function () {
    enterResolverRun();

    $resolved = (new MemoryToolScopeResolver)->resolve(MemoryScope::Run);

    expect($resolved->scope)->toBe(MemoryScope::Run);
    expect($resolved->scopeId)->toBe('run-7');
});

test('it resolves the swarm scope to the active swarm class', function () {
    enterResolverRun();

    $resolved = (new MemoryToolScopeResolver)->resolve(MemoryScope::Swarm);

    expect($resolved->scopeId)->toBe(FakeSequentialSwarm::class);
});

test('it resolves the agent scope to a bound agent class', function () {
    enterResolverRun();
    $agent = new FakeResearcher;

    $resolved = (new MemoryToolScopeResolver($agent))->resolve(MemoryScope::Agent);

    expect($resolved->scopeId)->toBe(FakeResearcher::class);
});

test('the agent scope is unresolvable without a bound agent', function () {
    enterResolverRun();

    expect((new MemoryToolScopeResolver)->resolve(MemoryScope::Agent))->toBeNull();
});

test('the conversation scope is never resolvable yet', function () {
    enterResolverRun();

    expect((new MemoryToolScopeResolver)->resolve(MemoryScope::Conversation))->toBeNull();
});

test('every scope is unresolvable outside an active run', function () {
    $resolver = new MemoryToolScopeResolver(new FakeResearcher);

    foreach (MemoryScope::cases() as $scope) {
        expect($resolver->resolve($scope))->toBeNull();
    }
});

test('it accepts a bound agent for agent-scope addressing', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'x';
        }
    };

    ActiveRunContext::enter('run-7', FakeSequentialSwarm::class, RunContext::fake(['run_id' => 'run-7', 'input' => 'go']));

    expect((new MemoryToolScopeResolver($agent))->resolve(MemoryScope::Agent)->scopeId)->toBe($agent::class);
});
