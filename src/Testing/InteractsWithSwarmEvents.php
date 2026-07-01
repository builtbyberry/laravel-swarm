<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Testing;

use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;

/**
 * Activates swarm lifecycle-event recording for a test case so `assertEventFired()`
 * has something to assert against.
 *
 * `use` this trait on a Laravel/Testbench `TestCase` (the same way you would
 * `RefreshDatabase` or `WithFaker`): the framework auto-invokes the
 * `setUp{TraitName}` / `tearDown{TraitName}` hooks below, so there is nothing to
 * call by hand. On setup it activates the {@see SwarmEventRecorder} and registers a
 * forwarding listener for each of {@see SwarmEventRecorder::recordableEvents()}; on
 * teardown it deactivates and clears the recorder, so state never leaks between
 * tests.
 *
 * ```php
 * use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;
 *
 * class ArticlePipelineTest extends TestCase
 * {
 *     use InteractsWithSwarmEvents;
 *
 *     public function test_it_starts(): void
 *     {
 *         ArticlePipeline::make()->prompt('...');
 *
 *         ArticlePipeline::assertEventFired(SwarmStarted::class);
 *     }
 * }
 * ```
 *
 * @property Application $app
 */
trait InteractsWithSwarmEvents
{
    protected function setUpInteractsWithSwarmEvents(): void
    {
        $recorder = $this->app->make(SwarmEventRecorder::class);
        $recorder->resetRecorder();
        $recorder->activate();

        $events = $this->app->make(Dispatcher::class);

        foreach (SwarmEventRecorder::recordableEvents() as $eventClass) {
            $events->listen($eventClass, fn (object $event) => $recorder->record($event));
        }
    }

    protected function tearDownInteractsWithSwarmEvents(): void
    {
        // Invoked via beforeApplicationDestroyed (the framework's tearDown{Trait}
        // hook), so the application is still alive here.
        $recorder = $this->app->make(SwarmEventRecorder::class);
        $recorder->deactivate();
        $recorder->resetRecorder();
    }
}
