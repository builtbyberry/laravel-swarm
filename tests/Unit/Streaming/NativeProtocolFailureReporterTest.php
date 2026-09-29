<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Events\NativeProtocolProjectionFailed;
use BuiltByBerry\LaravelSwarm\Streaming\NativeProtocolFailureReporter;
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
