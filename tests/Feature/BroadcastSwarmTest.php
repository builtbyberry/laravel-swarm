<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Responses\QueuedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\StreamedSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
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
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

final class FailingSwarmBroadcastTransport extends AnonymousEvent
{
    /**
     * @var array<int, string>
     */
    public array $events = [];

    public function __construct(
        protected string $failOnType = 'swarm_text_delta',
    ) {
        parent::__construct(new Channel('swarm.transport-test'));
    }

    public function send(): void
    {
        $this->recordAndMaybeFail();
    }

    public function sendNow(): void
    {
        $this->shouldBroadcastNow = true;

        $this->recordAndMaybeFail();
    }

    protected function recordAndMaybeFail(): void
    {
        $type = $this->broadcastAs();
        $this->events[] = $type;

        if ($type === $this->failOnType) {
            throw new RuntimeException('Simulated broadcast transport failure.');
        }
    }
}

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

function fakeFailingBroadcastTransport(string $failOnType = 'swarm_text_delta'): FailingSwarmBroadcastTransport
{
    $transport = new FailingSwarmBroadcastTransport($failOnType);

    Broadcast::shouldReceive('on')->andReturn($transport);

    return $transport;
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

test('broadcast transport failures fail live runs and omit terminal replay events', function () {
    config()->set('swarm.streaming.replay.enabled', true);
    $transport = fakeFailingBroadcastTransport();
    $runId = 'broadcast-transport-failure-run-id';

    expect(fn () => FakeSequentialSwarm::make()->broadcast(
        RunContext::from('broadcast-failure-task', $runId),
        new Channel('swarm.run'),
    ))->toThrow(RuntimeException::class, 'Simulated broadcast transport failure.');

    $history = app(SwarmHistory::class)->find($runId);
    $storedTypes = collect(app(StreamEventStore::class)->events($runId))
        ->map(fn ($event): string => $event->type())
        ->all();

    expect($history['status'])->toBe('failed');
    expect($transport->events)->toContain('swarm_text_delta');
    expect($transport->events)->not->toContain('swarm_stream_end');
    expect($storedTypes)->not->toContain('swarm_stream_end');
});

test('broadcast now transport failures fail live runs before terminal completion', function () {
    $transport = fakeFailingBroadcastTransport();
    $runId = 'broadcast-now-transport-failure-run-id';

    expect(fn () => FakeSequentialSwarm::make()->broadcastNow(
        RunContext::from('broadcast-now-failure-task', $runId),
        new Channel('swarm.run'),
    ))->toThrow(RuntimeException::class, 'Simulated broadcast transport failure.');

    $history = app(SwarmHistory::class)->find($runId);

    expect($history['status'])->toBe('failed');
    expect($transport->events)->toContain('swarm_text_delta');
    expect($transport->events)->not->toContain('swarm_stream_end');
});

test('queued broadcast transport failures fail the job and skip then callbacks', function () {
    $transport = fakeFailingBroadcastTransport();
    $runId = 'queued-broadcast-transport-failure-run-id';
    $state = (object) ['thenCalled' => false];

    $queued = FakeSequentialSwarm::make()
        ->broadcastOnQueue(
            RunContext::from('queued-broadcast-failure-task', $runId),
            new Channel('swarm.run'),
        )
        ->then(function () use ($state): void {
            $state->thenCalled = true;
        });

    $job = $queued->getJob();
    preventQueuedBroadcastSwarmRedispatch($queued);

    expect(fn () => $job->handle(app(SwarmRunner::class)))
        ->toThrow(RuntimeException::class, 'Simulated broadcast transport failure.');

    $history = app(SwarmHistory::class)->find($runId);

    expect($history['status'])->toBe('failed');
    expect($state->thenCalled)->toBeFalse();
    expect($transport->events)->toContain('swarm_text_delta');
    expect($transport->events)->not->toContain('swarm_stream_end');
});
