<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\HaltsSwarmExecution;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Exceptions\AuditSinkHaltedException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

class CountingThrowingSink implements SwarmAuditSink
{
    public int $attempts = 0;

    public function __construct(private readonly int $failFirstN = PHP_INT_MAX) {}

    public function emit(string $category, array $payload): void
    {
        $this->attempts++;

        if ($this->attempts <= $this->failFirstN) {
            throw new RuntimeException("sink failure #{$this->attempts}");
        }
    }
}

class StubFailureHandler implements SinkFailureHandler
{
    public int $calls = 0;

    /**
     * @param  array<int, SinkFailureDecision>  $decisions
     */
    public function __construct(public array $decisions = []) {}

    public function handle(SwarmAuditSink $sink, string $category, array $payload, Throwable $exception): SinkFailureDecision
    {
        $this->calls++;

        return array_shift($this->decisions) ?? SinkFailureDecision::Swallow;
    }
}

test('container default binding resolves to ConfiguredSinkFailureHandler', function (): void {
    expect(app(SinkFailureHandler::class))->toBeInstanceOf(ConfiguredSinkFailureHandler::class);
});

test('ConfiguredSinkFailureHandler returns Swallow for the swallow policy', function (): void {
    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'swallow']]]),
        new NullLogger,
    );

    $decision = $handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::Swallow);
});

test('ConfiguredSinkFailureHandler logs and returns Swallow for the log policy', function (): void {
    Log::shouldReceive('error')->once()->withArgs(
        fn ($message, $context) => $message === 'Swarm audit sink failed.'
            && $context['category'] === 'run.failed'
    );

    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'log']]]),
        Log::getFacadeRoot(),
    );

    $decision = $handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::Swallow);
});

test('ConfiguredSinkFailureHandler logs and returns Halt for the halt policy', function (): void {
    Log::shouldReceive('error')->once()->withArgs(
        fn ($message, $context) => str_contains($message, 'halting run')
            && $context['category'] === 'run.failed'
    );

    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'halt']]]),
        Log::getFacadeRoot(),
    );

    $decision = $handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::Halt);
});

test('ConfiguredSinkFailureHandler logs a warning and Swallows for unknown policies', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn ($message, $context) => str_contains($message, 'Unknown')
            && $context['configured_policy'] === 'bogus'
    );

    $handler = new ConfiguredSinkFailureHandler(
        new ConfigRepository(['swarm' => ['audit' => ['failure_policy' => 'bogus']]]),
        Log::getFacadeRoot(),
    );

    $decision = $handler->handle(new NoOpSwarmAuditSink, 'run.failed', [], new RuntimeException('x'));

    expect($decision)->toBe(SinkFailureDecision::Swallow);
});

test('dispatcher swallows sink failure when handler returns Swallow', function (): void {
    $sink = new CountingThrowingSink(failFirstN: 1);
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SinkFailureHandler::class, new StubFailureHandler([SinkFailureDecision::Swallow]));

    expect(fn () => app(SwarmAuditDispatcher::class)->emit('x', []))->not->toThrow(Throwable::class);
    expect($sink->attempts)->toBe(1);
});

test('dispatcher retries when handler returns RetryInline and stops when it Swallows', function (): void {
    $sink = new CountingThrowingSink(failFirstN: 2);  // fails twice, then succeeds
    $handler = new StubFailureHandler([
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,
    ]);
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SinkFailureHandler::class, $handler);

    app(SwarmAuditDispatcher::class)->emit('x', []);

    expect($sink->attempts)->toBe(3);  // two failures + one success
    expect($handler->calls)->toBe(2);
});

test('dispatcher throws AuditSinkHaltedException when handler returns Halt', function (): void {
    app()->instance(SwarmAuditSink::class, new CountingThrowingSink(failFirstN: 1));
    app()->instance(SinkFailureHandler::class, new StubFailureHandler([SinkFailureDecision::Halt]));

    try {
        app(SwarmAuditDispatcher::class)->emit('run.failed', []);
        $this->fail('Expected AuditSinkHaltedException to be thrown.');
    } catch (AuditSinkHaltedException $halt) {
        expect($halt)->toBeInstanceOf(HaltsSwarmExecution::class);
        expect($halt->category)->toBe('run.failed');
        expect($halt->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

test('dispatcher throws a runaway-guard exception when the handler exceeds MAX_HANDLER_ITERATIONS', function (): void {
    $sink = new CountingThrowingSink(failFirstN: PHP_INT_MAX);
    app()->instance(SwarmAuditSink::class, $sink);
    app()->instance(SinkFailureHandler::class, new StubFailureHandler([
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,
        SinkFailureDecision::RetryInline,  // 6th attempt -> over the cap
    ]));

    try {
        app(SwarmAuditDispatcher::class)->emit('x', []);
        $this->fail('Expected SwarmException for runaway handler.');
    } catch (SwarmException $e) {
        expect($e->getMessage())->toContain('exceeded the maximum');
        expect($e->getMessage())->toContain((string) SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS);
        expect($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }

    expect($sink->attempts)->toBe(SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS + 1);
});
