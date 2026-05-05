<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Broadcasting\Channel;

test('stream emits stream.event telemetry with monotonic sequence indices', function (): void {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    iterator_to_array(FakeSequentialSwarm::make()->stream('stream-boundary-task'));

    $streamEvents = $sink->recordsForCategory('stream.event');
    expect($streamEvents)->not->toBeEmpty();

    $indices = array_map(static fn (array $r): int => (int) ($r['sequence_index'] ?? -1), $streamEvents);
    expect($indices)->toBe(array_values(range(0, count($indices) - 1)));

    $types = array_map(static fn (array $r): string => (string) ($r['event_type'] ?? ''), $streamEvents);
    expect($types)->toContain('swarm_stream_start', 'swarm_stream_end');
});

test('broadcast on queue emits broadcast.event telemetry with channel names', function (): void {
    config(['queue.default' => 'sync']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);

    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    FakeSequentialSwarm::make()->broadcastOnQueue('broadcast-task', new Channel('telemetry-test-channel'));

    $broadcastEvents = $sink->recordsForCategory('broadcast.event');
    expect($broadcastEvents)->not->toBeEmpty();

    $first = $broadcastEvents[0];
    expect($first['channel_names'])->toBe(['telemetry-test-channel'])
        ->and($first)->toHaveKey('event_type')
        ->and($first)->toHaveKey('sequence_index');
});
