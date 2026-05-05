<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\EvidenceEnvelope;
use BuiltByBerry\LaravelSwarm\Telemetry\NoOpSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use Illuminate\Support\Facades\Log;

test('default container binding resolves to NoOpSwarmTelemetrySink', function (): void {
    $sink = app(SwarmTelemetrySink::class);

    expect($sink)->toBeInstanceOf(NoOpSwarmTelemetrySink::class);
});

test('dispatcher is registered as a singleton', function (): void {
    $a = app(SwarmTelemetryDispatcher::class);
    $b = app(SwarmTelemetryDispatcher::class);

    expect($a)->toBe($b);
});

test('dispatcher enriches payloads with schema_version category and occurred_at', function (): void {
    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    app(SwarmTelemetryDispatcher::class)->emit('run.started', [
        'run_id' => 'test-run',
        'status' => 'started',
    ]);

    $records = $sink->allRecords();
    expect($records)->toHaveCount(1);

    $record = $records[0];
    expect($record['schema_version'])->toBe(EvidenceEnvelope::SCHEMA_VERSION);
    expect($record['category'])->toBe('run.started');
    expect($record)->toHaveKey('occurred_at');
    expect($record['run_id'])->toBe('test-run');
    expect($record['status'])->toBe('started');
});

test('dispatcher swallows sink exceptions by default', function (): void {
    $throwingSink = new class implements SwarmTelemetrySink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink exploded');
        }
    };

    app()->instance(SwarmTelemetrySink::class, $throwingSink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    config(['swarm.observability.failure_policy' => 'swallow']);

    expect(fn () => app(SwarmTelemetryDispatcher::class)->emit('run.started', ['run_id' => 'x']))
        ->not->toThrow(RuntimeException::class);
});

test('dispatcher logs sink exceptions when failure_policy is log', function (): void {
    $throwingSink = new class implements SwarmTelemetrySink
    {
        public function emit(string $category, array $payload): void
        {
            throw new RuntimeException('sink error details');
        }
    };

    app()->instance(SwarmTelemetrySink::class, $throwingSink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    config(['swarm.observability.failure_policy' => 'log']);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Swarm telemetry sink failed.'
                && $context['category'] === 'run.failed'
                && str_contains($context['exception'], 'sink error details');
        });

    app(SwarmTelemetryDispatcher::class)->emit('run.failed', ['run_id' => 'x']);
});

test('dispatcher does not emit when observability is disabled', function (): void {
    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);
    config(['swarm.observability.enabled' => false]);

    app(SwarmTelemetryDispatcher::class)->emit('run.started', ['run_id' => 'x']);

    expect($sink->allRecords())->toHaveCount(0);
});

test('dispatcher metadata helper includes keys and omits values by default', function (): void {
    config(['swarm.observability.metadata_allowlist' => []]);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    $metadata = app(SwarmTelemetryDispatcher::class)->metadata([
        'secret_note' => 'do-not-export',
        'tenant_id' => 'acme',
    ]);

    expect($metadata['metadata_keys'])->toBe(['secret_note', 'tenant_id']);
    expect($metadata['metadata'])->toBe([]);
});

test('dispatcher metadata helper emits allowlisted top-level values', function (): void {
    config(['swarm.observability.metadata_allowlist' => 'tenant_id, workflow']);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    $metadata = app(SwarmTelemetryDispatcher::class)->metadata([
        'secret_note' => 'do-not-export',
        'tenant_id' => 'acme',
        'workflow' => 'approval',
    ]);

    expect($metadata['metadata_keys'])->toBe(['secret_note', 'tenant_id', 'workflow']);
    expect($metadata['metadata'])->toBe([
        'tenant_id' => 'acme',
        'workflow' => 'approval',
    ]);
});
