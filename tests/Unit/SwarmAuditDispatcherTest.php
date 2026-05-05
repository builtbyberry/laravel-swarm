<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\NoOpSwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmAuditSink;
use Illuminate\Support\Facades\Log;

test('default container binding resolves to NoOpSwarmAuditSink', function (): void {
    $sink = app(SwarmAuditSink::class);

    expect($sink)->toBeInstanceOf(NoOpSwarmAuditSink::class);
});

test('dispatcher is registered as a singleton', function (): void {
    $a = app(SwarmAuditDispatcher::class);
    $b = app(SwarmAuditDispatcher::class);

    expect($a)->toBe($b);
});

test('dispatcher enriches payloads with schema_version category and occurred_at', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);

    app(SwarmAuditDispatcher::class)->emit('run.started', [
        'run_id' => 'test-run',
        'status' => 'started',
    ]);

    $records = $sink->allRecords();
    expect($records)->toHaveCount(1);

    $record = $records[0];
    expect($record['schema_version'])->toBe(SwarmAuditDispatcher::SCHEMA_VERSION);
    expect($record['category'])->toBe('run.started');
    expect($record)->toHaveKey('occurred_at');
    expect($record['run_id'])->toBe('test-run');
    expect($record['status'])->toBe('started');
});

test('dispatcher swallows sink exceptions by default', function (): void {
    $throwingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink exploded');
        }
    };

    app()->instance(SwarmAuditSink::class, $throwingSink);
    config(['swarm.audit.failure_policy' => 'swallow']);

    expect(fn () => app(SwarmAuditDispatcher::class)->emit('run.started', ['run_id' => 'x']))
        ->not->toThrow(RuntimeException::class);
});

test('dispatcher logs sink exceptions when failure_policy is log', function (): void {
    $throwingSink = new class implements SwarmAuditSink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink error details');
        }
    };

    app()->instance(SwarmAuditSink::class, $throwingSink);
    config(['swarm.audit.failure_policy' => 'log']);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Swarm audit sink failed.'
                && $context['category'] === 'run.failed'
                && str_contains($context['exception'], 'sink error details');
        });

    app(SwarmAuditDispatcher::class)->emit('run.failed', ['run_id' => 'x']);
});

test('no-op sink discards all emitted payloads silently', function (): void {
    $noop = new NoOpSwarmAuditSink;

    expect(fn () => $noop->emit('run.started', ['run_id' => 'x', 'status' => 'started']))
        ->not->toThrow(Throwable::class);
});

test('dispatcher forwards multiple sequential emissions without cross-contamination', function (): void {
    $sink = new RecordingSwarmAuditSink;
    app()->instance(SwarmAuditSink::class, $sink);
    $dispatcher = app(SwarmAuditDispatcher::class);

    $dispatcher->emit('run.started', ['run_id' => 'run-1']);
    $dispatcher->emit('step.started', ['run_id' => 'run-1', 'step_index' => 0]);
    $dispatcher->emit('run.completed', ['run_id' => 'run-1']);

    $records = $sink->allRecords();
    expect($records)->toHaveCount(3);
    expect($records[0]['category'])->toBe('run.started');
    expect($records[1]['category'])->toBe('step.started');
    expect($records[2]['category'])->toBe('run.completed');

    foreach ($records as $record) {
        expect($record['run_id'])->toBe('run-1');
        expect($record['schema_version'])->toBe(SwarmAuditDispatcher::SCHEMA_VERSION);
    }
});
