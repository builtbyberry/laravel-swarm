<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Jobs\NoOpQueuedJob;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

function preventQueuedBroadcastSwarmRedispatch(object $response): void
{
    $dispatchableProperty = new ReflectionProperty($response, 'dispatchable');
    $dispatchableProperty->setAccessible(true);

    $dispatchable = $dispatchableProperty->getValue($response);

    $jobProperty = new ReflectionProperty($dispatchable, 'job');
    $jobProperty->setAccessible(true);
    $jobProperty->setValue($dispatchable, new NoOpQueuedJob);
}

function dispatchedAnonymousBroadcasts(): Collection
{
    return Event::dispatched(AnonymousEvent::class)
        ->map(fn (array $event): AnonymousEvent => $event[0])
        ->values();
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('broadcast consumes stream and broadcasts ordered swarm stream events', function () {
    Event::fake([AnonymousEvent::class]);

    $response = FakeSequentialSwarm::make()->broadcast('broadcast-task', new Channel('swarm.run'));

    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);
    expect($response->streamedResponse)->toBeInstanceOf(StreamedSwarmResponse::class);
    expect($response->streamedResponse?->output)->toBe('editor-out');

    $broadcasts = dispatchedAnonymousBroadcasts();

    expect($broadcasts->map(fn (AnonymousEvent $event): string => $event->broadcastAs())->all())->toBe([
        'swarm_stream_start',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_step_end',
        'swarm_step_start',
        'swarm_text_delta',
        'swarm_text_end',
        'swarm_step_end',
        'swarm_stream_end',
    ]);
    expect($broadcasts->every(fn (AnonymousEvent $event): bool => $event->shouldBroadcastNow() === false))->toBeTrue();

    $firstBroadcast = $broadcasts->first();
    $firstChannel = $firstBroadcast->broadcastOn()[0];

    expect($firstChannel)->toBeInstanceOf(Channel::class);
    expect($firstChannel->name)->toBe('swarm.run');
    expect($firstBroadcast->broadcastWith())
        ->toHaveKey('type', 'swarm_stream_start')
        ->toHaveKey('run_id', $response->runId);
});

test('broadcast now uses immediate broadcast delivery', function () {
    Event::fake([AnonymousEvent::class]);

    $response = FakeSequentialSwarm::make()->broadcastNow('broadcast-now-task', new Channel('swarm.run'));

    expect($response)->toBeInstanceOf(StreamableSwarmResponse::class);

    $broadcasts = dispatchedAnonymousBroadcasts();

    expect($broadcasts)->toHaveCount(10);
    expect($broadcasts->every(fn (AnonymousEvent $event): bool => $event->shouldBroadcastNow()))->toBeTrue();
    expect($broadcasts->last()->broadcastAs())->toBe('swarm_stream_end');
});

test('broadcast on queue dispatches broadcast job and preserves pending dispatch fluency', function () {
    config()->set('swarm.queue.connection', 'redis');
    config()->set('swarm.queue.name', 'swarm-broadcasts');

    $configured = FakeSequentialSwarm::make()
        ->broadcastOnQueue('configured-queued-broadcast-task', new Channel('swarm.run'));

    expect($configured->getJob())->toBeInstanceOf(BroadcastSwarm::class);
    expect($configured->getJob()->connection)->toBe('redis');
    expect($configured->getJob()->queue)->toBe('swarm-broadcasts');

    preventQueuedBroadcastSwarmRedispatch($configured);

    $queued = FakeSequentialSwarm::make()
        ->broadcastOnQueue('queued-broadcast-task', new Channel('swarm.run'))
        ->onQueue('priority-broadcasts');

    expect($queued)->toBeInstanceOf(QueuedSwarmResponse::class);
    expect($queued->runId)->not->toBeNull();
    expect($queued->getJob())->toBeInstanceOf(BroadcastSwarm::class);
    expect($queued->getJob()->queue)->toBe('priority-broadcasts');

    preventQueuedBroadcastSwarmRedispatch($queued);
});

test('broadcast job streams once broadcasts immediately and passes streamed response to queued callbacks', function () {
    Event::fake([AnonymousEvent::class]);
    $state = (object) ['response' => null];

    $queued = FakeSequentialSwarm::make()
        ->broadcastOnQueue('queued-broadcast-task', new Channel('swarm.run'))
        ->then(function (StreamedSwarmResponse $response) use ($state): void {
            $state->response = $response;
        });

    $job = $queued->getJob();
    preventQueuedBroadcastSwarmRedispatch($queued);

    $job->handle(app(SwarmRunner::class));

    expect($state->response)->toBeInstanceOf(StreamedSwarmResponse::class);
    expect($state->response?->output)->toBe('editor-out');

    FakeResearcher::assertPrompted('queued-broadcast-task');
    FakeWriter::assertPrompted('research-out');
    FakeEditor::assertPrompted('writer-out');

    $history = app(SwarmHistory::class)->find($queued->runId);

    expect($history['status'])->toBe('completed');
    expect($history['output'])->toBe('editor-out');

    $broadcasts = dispatchedAnonymousBroadcasts();

    expect($broadcasts)->toHaveCount(10);
    expect($broadcasts->every(fn (AnonymousEvent $event): bool => $event->shouldBroadcastNow()))->toBeTrue();
    expect($broadcasts->last()->broadcastWith())
        ->toHaveKey('type', 'swarm_stream_end')
        ->toHaveKey('output', 'editor-out');
});

test('broadcast helpers fail clearly for non sequential swarms', function () {
    $message = 'Streaming is only supported for sequential swarms. parallel topology does not support streaming.';

    expect(fn () => FakeParallelSwarm::make()->broadcast('broadcast-task', new Channel('swarm.run')))
        ->toThrow(SwarmException::class, $message);
    expect(fn () => FakeParallelSwarm::make()->broadcastNow('broadcast-task', new Channel('swarm.run')))
        ->toThrow(SwarmException::class, $message);
    expect(fn () => FakeParallelSwarm::make()->broadcastOnQueue('broadcast-task', new Channel('swarm.run')))
        ->toThrow(SwarmException::class, $message);
});
