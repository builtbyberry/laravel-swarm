<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;
use BuiltByBerry\LaravelSwarm\Exceptions\ContextBudgetExceededException;
use BuiltByBerry\LaravelSwarm\Jobs\CompactSwarmRun;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Streaming\ContextGrowthGovernor;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a governor with mocked collaborators and a config block, returning the
 * governor plus its telemetry + logger spies for assertion.
 *
 * @param  array<string, mixed>  $contextGrowth
 * @return array{0: ContextGrowthGovernor, 1: MockInterface, 2: MockInterface, 3: MockInterface}
 */
function makeGovernor(array $contextGrowth, ?GrowthPolicy $policy = GrowthPolicy::DegradeToCold): array
{
    $resolver = Mockery::mock(SwarmAttributeResolver::class);
    if ($policy === null) {
        $resolver->shouldReceive('resolveGrowthPolicy')->andThrow(new RuntimeException('resolver boom'));
    } else {
        $resolver->shouldReceive('resolveGrowthPolicy')->andReturn($policy);
    }

    $telemetry = Mockery::spy(SwarmTelemetryDispatcher::class);
    $logger = Mockery::spy(LoggerInterface::class);
    $config = new ConfigRepository(['swarm' => ['context_growth' => $contextGrowth]]);

    return [new ContextGrowthGovernor($resolver, $config, $telemetry, $logger), $telemetry, $logger, $resolver];
}

test('governor is inert when neither budget nor hard cap is configured', function () {
    Queue::fake();
    [$governor, $telemetry, $logger] = makeGovernor([], GrowthPolicy::Refuse);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-1', 9_999, $state);

    $telemetry->shouldNotHaveReceived('emit');
    $logger->shouldNotHaveReceived('warning');
    Queue::assertNothingPushed();
});

test('governor takes no action while under budget', function () {
    Queue::fake();
    [$governor, $telemetry] = makeGovernor(['budget_events' => 10], GrowthPolicy::DegradeToCold);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-1', 5, $state);

    $telemetry->shouldNotHaveReceived('emit');
    Queue::assertNothingPushed();
});

test('warn rung emits telemetry per evaluation but logs once', function () {
    Queue::fake();
    [$governor, $telemetry, $logger] = makeGovernor(['budget_events' => 4], GrowthPolicy::Warn);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-1', 5, $state);
    $governor->evaluate(new FakeSequentialSwarm, 'run-1', 6, $state);

    $telemetry->shouldHaveReceived('emit')->with('context_growth.action', Mockery::on(
        fn (array $p): bool => $p['action'] === 'warn' && $p['declared_policy'] === 'warn'
    ))->twice();
    $logger->shouldHaveReceived('warning')->once();
    Queue::assertNothingPushed();
});

test('degrade-to-cold nudges compaction exactly once across evaluations', function () {
    Queue::fake();
    [$governor] = makeGovernor(['budget_events' => 4], GrowthPolicy::DegradeToCold);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-deg', 5, $state);
    $governor->evaluate(new FakeSequentialSwarm, 'run-deg', 6, $state);

    Queue::assertPushed(CompactSwarmRun::class, 1);
    Queue::assertPushed(CompactSwarmRun::class, fn (CompactSwarmRun $job): bool => $job->runId === 'run-deg');
});

test('backpressure rung degrades and returns without throwing', function () {
    Queue::fake();
    [$governor] = makeGovernor(['budget_events' => 4, 'backpressure_delay_ms' => 0], GrowthPolicy::Backpressure);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-bp', 5, $state);

    Queue::assertPushed(CompactSwarmRun::class, 1);
});

test('refuse rung throws a re-dispatchable ContextBudgetExceededException', function () {
    Queue::fake();
    [$governor] = makeGovernor(['budget_events' => 4], GrowthPolicy::Refuse);

    $state = [];
    expect(fn () => $governor->evaluate(new FakeSequentialSwarm, 'run-ref', 5, $state))
        ->toThrow(ContextBudgetExceededException::class, 'refuse policy');
});

test('hard cap clamps author intent: ignore still refuses on breach', function () {
    Queue::fake();
    [$governor] = makeGovernor(['hard_cap_events' => 4], GrowthPolicy::Ignore);

    $state = [];
    expect(fn () => $governor->evaluate(new FakeSequentialSwarm, 'run-cap', 5, $state))
        ->toThrow(ContextBudgetExceededException::class, 'hard cap');
});

test('ignore policy over a soft budget with no hard cap takes no action', function () {
    Queue::fake();
    [$governor, $telemetry] = makeGovernor(['budget_events' => 4], GrowthPolicy::Ignore);

    $state = [];
    $governor->evaluate(new FakeSequentialSwarm, 'run-ign', 5, $state);

    $telemetry->shouldNotHaveReceived('emit');
    Queue::assertNothingPushed();
});

test('fail-safe: a throwing policy resolution never wedges the run', function () {
    Queue::fake();
    [$governor, $telemetry, $logger] = makeGovernor(['budget_events' => 4], policy: null);

    $state = [];
    // Must not throw, despite resolveGrowthPolicy() raising.
    $governor->evaluate(new FakeSequentialSwarm, 'run-fail', 5, $state);
    $governor->evaluate(new FakeSequentialSwarm, 'run-fail', 6, $state);

    $logger->shouldHaveReceived('warning')->once(); // fail-safe logged once
    Queue::assertNothingPushed();
});

// ─── Octane frame-scoping (#290): per-run throttle state never leaks ──────────

test('the governor holds no mutable per-run instance state (Octane singleton-safe)', function () {
    // The governor's only per-run memory is the caller-owned `&$state` array; the
    // instance itself must carry only stateless collaborators, so the SAME singleton
    // serving two runs in one Octane worker cannot leak warn/nudge bookkeeping.
    $properties = (new ReflectionClass(ContextGrowthGovernor::class))->getProperties();

    foreach ($properties as $property) {
        $type = $property->getType();
        $isStatelessCollaborator = $type instanceof ReflectionNamedType && ! $type->isBuiltin();
        expect($isStatelessCollaborator)->toBeTrue(
            ContextGrowthGovernor::class."::\${$property->getName()} must be a typed object collaborator; scalar/array/untyped/mixed/union is a per-run leak surface under Octane."
        );
    }
});

test('two runs in one worker keep independent throttle state: each warns once, neither suppresses the other', function () {
    // Per-run throttle memory is threaded via the caller's generator-local `&$state`.
    // Two runs on one long-lived worker pass SEPARATE arrays, so run B warning does
    // not consume run A's warn-once budget (or vice versa) — no cross-run suppression.
    Queue::fake();
    [$governor, , $logger] = makeGovernor(['budget_events' => 4], GrowthPolicy::Warn);

    $stateA = [];
    $stateB = [];

    // Interleave A and B on the SAME governor instance. Each run's first over-budget
    // evaluation warns; its second must be suppressed by its OWN state, not the peer's.
    $governor->evaluate(new FakeSequentialSwarm, 'run-a', 5, $stateA); // A warns
    $governor->evaluate(new FakeSequentialSwarm, 'run-b', 5, $stateB); // B warns (NOT suppressed by A)
    $governor->evaluate(new FakeSequentialSwarm, 'run-a', 6, $stateA); // A suppressed by A's own state
    $governor->evaluate(new FakeSequentialSwarm, 'run-b', 6, $stateB); // B suppressed by B's own state

    // Each run's warn-once memory lives in its own array.
    expect($stateA['growth_warned'] ?? false)->toBeTrue()
        ->and($stateB['growth_warned'] ?? false)->toBeTrue();

    // Exactly two warnings total — one per run — proving no shared/leaked counter.
    $logger->shouldHaveReceived('warning')->twice();
});

test('a degrade-to-cold nudge fires once per run, isolated across two runs in one worker', function () {
    // The compaction nudge (degrade_to_cold) is likewise gated by per-run `&$state`.
    // Two runs each dispatch exactly one CompactSwarmRun; one run's nudge must not
    // consume the other's nudge-once budget.
    Queue::fake();
    [$governor] = makeGovernor(['budget_events' => 4], GrowthPolicy::DegradeToCold);

    $stateA = [];
    $stateB = [];

    $governor->evaluate(new FakeSequentialSwarm, 'run-a', 5, $stateA);
    $governor->evaluate(new FakeSequentialSwarm, 'run-a', 6, $stateA); // nudge suppressed for A
    $governor->evaluate(new FakeSequentialSwarm, 'run-b', 5, $stateB);
    $governor->evaluate(new FakeSequentialSwarm, 'run-b', 6, $stateB); // nudge suppressed for B

    // Exactly one compaction nudge per run — two total, independently gated.
    Queue::assertPushed(CompactSwarmRun::class, 2);
    expect($stateA['growth_nudged'] ?? false)->toBeTrue()
        ->and($stateB['growth_nudged'] ?? false)->toBeTrue();
});
