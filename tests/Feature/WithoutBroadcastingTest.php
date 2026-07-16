<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Telemetry\SwarmTelemetryDispatcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\RecordingSwarmTelemetrySink;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\WithoutBroadcastingSequentialSwarm;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;

/**
 * Collect the type strings (`broadcastAs()`) of every anonymous broadcast the
 * run dispatched, in order.
 *
 * @return array<int, string>
 */
function broadcastTypes(): array
{
    return Event::dispatched(AnonymousEvent::class)
        ->map(fn (array $event): AnonymousEvent => $event[0])
        ->map(fn (AnonymousEvent $event): string => $event->broadcastAs())
        ->values()
        ->all();
}

beforeEach(function () {
    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('sync broadcast suppresses the excluded stream-event type but keeps the rest', function () {
    Event::fake([AnonymousEvent::class]);

    $response = WithoutBroadcastingSequentialSwarm::make()
        ->broadcast('broadcast-task', new Channel('swarm.run'));

    // The stream itself is untouched: the text delta still flows through, so the
    // final output is fully assembled even though the delta is never broadcast.
    expect($response->streamedResponse?->output)->toBe('editor-out');

    $types = broadcastTypes();

    // The excluded type is gone from the broadcast...
    expect($types)->not->toContain('swarm_text_delta');
    // ...while every other event type still broadcasts, in order.
    expect($types)->toContain('swarm_stream_start', 'swarm_text_end', 'swarm_stream_end');
});

test('a swarm without the attribute still broadcasts the delta (control)', function () {
    Event::fake([AnonymousEvent::class]);

    FakeSequentialSwarm::make()->broadcast('broadcast-task', new Channel('swarm.run'));

    // Guards against over-suppression: absent the attribute, nothing is filtered.
    expect(broadcastTypes())->toContain('swarm_text_delta');
});

test('queued broadcast suppresses the excluded type from both broadcast and broadcast.event telemetry', function () {
    config(['queue.default' => 'sync']);
    Event::fake([AnonymousEvent::class]);

    $sink = new RecordingSwarmTelemetrySink;
    app()->instance(SwarmTelemetrySink::class, $sink);
    app()->forgetInstance(SwarmTelemetryDispatcher::class);

    WithoutBroadcastingSequentialSwarm::make()
        ->broadcastOnQueue('broadcast-task', new Channel('swarm.run'));

    // Not broadcast...
    expect(broadcastTypes())
        ->not->toContain('swarm_text_delta')
        ->toContain('swarm_stream_start', 'swarm_stream_end');

    // ...and not recorded as a broadcast.event (nothing was broadcast for it).
    $broadcastEventTypes = collect($sink->recordsForCategory('broadcast.event'))
        ->map(fn (array $r): string => (string) ($r['event_type'] ?? ''))
        ->all();

    expect($broadcastEventTypes)
        ->not->toContain('swarm_text_delta')
        ->toContain('swarm_stream_start', 'swarm_stream_end');

    // The suppressed event still advances the broadcast sequence, so the indices
    // of the events that DO broadcast stay strictly increasing (gaps allowed).
    $indices = collect($sink->recordsForCategory('broadcast.event'))
        ->map(fn (array $r): int => (int) ($r['sequence_index'] ?? -1))
        ->all();

    expect($indices)->toBe(array_values(collect($indices)->sort()->values()->all()));
    expect(count($indices))->toBe(count(array_unique($indices)));
});
