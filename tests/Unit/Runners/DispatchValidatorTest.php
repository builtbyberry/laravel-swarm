<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithoutTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithParallelTopologyAttribute;

beforeEach(function (): void {
    $this->validator = app(DispatchValidator::class);
});

test('ensureSwarmHasAgents throws when the swarm has no agents', function (): void {
    expect(fn () => $this->validator->ensureSwarmHasAgents(new SwarmWithoutTopologyAttribute))
        ->toThrow(SwarmException::class, 'swarm has no agents');
});

test('ensureSwarmHasAgents passes when agents are present', function (): void {
    $this->validator->ensureSwarmHasAgents(new FakeSequentialSwarm);

    expect(true)->toBeTrue();
});

test('ensureStreamableTopology blocks parallel topology', function (): void {
    expect(fn () => $this->validator->ensureStreamableTopology(new SwarmWithParallelTopologyAttribute))
        ->toThrow(SwarmException::class, 'Streaming is only supported');
});

test('ensureStreamableTopology allows sequential topology', function (): void {
    $this->validator->ensureStreamableTopology(new FakeSequentialSwarm);

    expect(true)->toBeTrue();
});

test('ensureActiveContextCompatible throws for queue mode when active context capture is disabled', function (): void {
    config()->set('swarm.capture.active_context', false);

    expect(fn () => $this->validator->ensureActiveContextCompatible(ExecutionMode::Queue))
        ->toThrow(SwarmException::class, 'active runtime context persistence');
});

test('ensureActiveContextCompatible is a no-op for sync run mode', function (): void {
    config()->set('swarm.capture.active_context', false);

    $this->validator->ensureActiveContextCompatible(ExecutionMode::Run);

    expect(true)->toBeTrue();
});

test('ensureQueueable rejects swarms with non-container constructor parameters', function (): void {
    $swarm = new class('inline-state') implements Swarm
    {
        public function __construct(public string $state) {}

        public function agents(): array
        {
            return [];
        }
    };

    expect(fn () => $this->validator->ensureQueueable($swarm))
        ->toThrow(NonQueueableSwarmException::class, 'container-resolvable workflow definitions');
});

test('ensureQueueable accepts swarms with no constructor parameters', function (): void {
    $this->validator->ensureQueueable(new FakeSequentialSwarm);

    expect(true)->toBeTrue();
});

test('validateForDispatch composes agent + timeout + topology checks', function (): void {
    $this->validator->validateForDispatch(new FakeParallelSwarm);

    expect(true)->toBeTrue();
});
