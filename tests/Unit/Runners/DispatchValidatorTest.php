<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\ExecutionMode;
use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Exceptions\NonQueueableSwarmException;
use BuiltByBerry\LaravelSwarm\Exceptions\StructuredOutputStreamingException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\DispatchValidator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DurableSequentialStructuredWorkerStreamingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\HierarchicalDurableStreamingSwarm;
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

test('ensureStreamableTopology blocks parallel topology with a live-vs-durable error', function (): void {
    // The live stream() gate excludes parallel (no single ordered token stream); the
    // error must name the live API and point at the durable path that DOES support it,
    // so it never reads as contradicting durable parallel streaming (#312).
    expect(fn () => $this->validator->ensureStreamableTopology(new SwarmWithParallelTopologyAttribute))
        ->toThrow(SwarmException::class, 'The live stream() API only supports');

    expect(fn () => $this->validator->ensureStreamableTopology(new SwarmWithParallelTopologyAttribute))
        ->toThrow(SwarmException::class, '#[DurableStreaming] does support');
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

test('STREAMING_SUPPORTED_TOPOLOGIES wires every topology — a future unwired topology must fail loud (#310 forcing function)', function (): void {
    // #311 wired hierarchical + static_hierarchical and #312 wired parallel, so every
    // Topology now streams under #[DurableStreaming] and no production topology reaches
    // the fail-loud throw anymore. This completeness assertion preserves the guarantee
    // the per-topology negative cases used to give: if a NEW topology is ever added
    // without being wired into the allow-list, this fails in CI — forcing it to be wired
    // (with a positive streaming test) or consciously excluded, never silently pinning
    // the opt-in and no-op'ing at runtime. The throw branch in
    // ensureDurableStreamingInfrastructure remains as the runtime backstop for exactly
    // that future-topology case.
    $supported = (new ReflectionClassConstant(DispatchValidator::class, 'STREAMING_SUPPORTED_TOPOLOGIES'))->getValue();

    expect($supported)->toEqualCanonicalizing(Topology::cases());
});

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

test('ensureDurableStreamingInfrastructure allows parallel when the database causal log is ready (#312)', function (): void {
    config()->set('swarm.persistence.driver', 'database');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    app()->forgetInstance(DispatchValidator::class);

    app(DispatchValidator::class)->ensureDurableStreamingInfrastructure(true, Topology::Parallel);

    expect(true)->toBeTrue();
});

test('ensureDurableStreamingWorkersStreamable fails loud when a #[DurableStreaming] worker is structured-output (#321)', function (): void {
    // A structured-output worker cannot be streamed; caught at dispatch so the run
    // never fails mid-execution (and every retry after) at the worker's stream site.
    expect(fn () => $this->validator->ensureDurableStreamingWorkersStreamable(new DurableSequentialStructuredWorkerStreamingSwarm))
        ->toThrow(StructuredOutputStreamingException::class, 'cannot be streamed');
});

test('ensureDurableStreamingWorkersStreamable excludes the hierarchical coordinator (#321)', function (): void {
    // The coordinator (agents()[0]) legitimately implements HasStructuredOutput and
    // runs via prompt() — never streamed — so a swarm whose only structured agent is
    // the coordinator, with plain workers, must NOT fail the guard.
    $this->validator->ensureDurableStreamingWorkersStreamable(new HierarchicalDurableStreamingSwarm);

    expect(true)->toBeTrue();
});

test('ensureDurableStreamingWorkersStreamable is a no-op for a swarm that did not opt into #[DurableStreaming] (#321)', function (): void {
    // Not opted in → the dispatch guard short-circuits; the live stream() sites carry
    // their own guard for the non-durable path.
    $this->validator->ensureDurableStreamingWorkersStreamable(new FakeSequentialSwarm);

    expect(true)->toBeTrue();
});
