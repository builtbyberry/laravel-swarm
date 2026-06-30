<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithoutTopologyAttribute;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SwarmWithParallelTopologyAttribute;
use Illuminate\Support\Facades\Artisan;

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

test('ensureDurableStreamingInfrastructure rejects #[DurableStreaming] on an unsupported topology (#310 forcing function)', function (Topology $topology): void {
    // The topology guard runs BEFORE the database-causal-log check, so this fails
    // loud regardless of persistence setup — a swarm can never silently pin the
    // opt-in and then no-op on a topology that does not stream yet. Parallel is the
    // only remaining unsupported topology now that #311 wired hierarchical and
    // static_hierarchical; #312 wires parallel and deletes this case.
    expect(fn () => $this->validator->ensureDurableStreamingInfrastructure(true, $topology))
        ->toThrow(SwarmException::class, 'is not yet supported for');
})->with([
    'parallel' => Topology::Parallel,
]);

test('ensureDurableStreamingInfrastructure is a no-op for a non-streaming run on any topology', function (): void {
    // durableStreaming = false (the swarm did not opt in) short-circuits before the
    // topology guard, so a hierarchical durable run that never opted in is untouched.
    $this->validator->ensureDurableStreamingInfrastructure(false, Topology::Hierarchical);

    expect(true)->toBeTrue();
});

test('ensureDurableStreamingInfrastructure allows sequential when the database causal log is ready', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    app()->forgetInstance(DispatchValidator::class);

    app(DispatchValidator::class)->ensureDurableStreamingInfrastructure(true, Topology::Sequential);

    expect(true)->toBeTrue();
});
