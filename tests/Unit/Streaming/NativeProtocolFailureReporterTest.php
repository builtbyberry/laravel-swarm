<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Events\NativeProtocolProjectionFailed;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Streaming\NativeProtocolFailureReporter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialSwarm;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

test('native protocol failure callbacks retain only the dispatcher they need', function () {
    Event::fake([NativeProtocolProjectionFailed::class]);

    $callback = NativeProtocolFailureReporter::callback(app(Dispatcher::class));

    expect((new ReflectionFunction($callback))->getClosureThis())->toBeNull();

    $callback('run-1', 'vercel', NativeProtocolProjection::Workflow, 'swarm_stream_failed');

    Event::assertDispatched(
        NativeProtocolProjectionFailed::class,
        fn (NativeProtocolProjectionFailed $event): bool => $event->runId === 'run-1'
            && $event->protocol === 'vercel'
            && $event->projection === NativeProtocolProjection::Workflow
            && $event->reason === 'swarm_stream_failed',
    );
});

test('every production stream runner installs a callback that retains only the dispatcher', function () {
    config()->set('swarm.streaming.parallel.enabled', true);
    config()->set('concurrency.default', 'process');

    $responses = [
        FakeSequentialSwarm::make()->stream('sequential'),
        FakeParallelSwarm::make()->stream('parallel'),
        FakeHierarchicalStreamSwarm::make()->stream('hierarchical'),
        FakeStaticHierarchicalStreamSequentialSwarm::make()->stream('static-hierarchical'),
    ];
    $property = new ReflectionProperty(StreamableSwarmResponse::class, 'onNativeProtocolFailure');
    $dispatcher = app(Dispatcher::class);

    foreach ($responses as $response) {
        $callback = $property->getValue($response);
        expect($callback)->toBeInstanceOf(Closure::class);

        /** @var Closure $callback */
        $reflection = new ReflectionFunction($callback);

        expect($reflection->getClosureThis())->toBeNull()
            ->and($reflection->getStaticVariables())->toBe(['events' => $dispatcher]);
    }
});
