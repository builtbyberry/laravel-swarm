<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;

beforeEach(function (): void {
    // Script the agents so the run does not make a real provider call (matches the
    // rest of the suite; without it the end-to-end test throws a RequestException
    // in CI, where no provider credentials are configured).
    config()->set('swarm.persistence.driver', 'cache');

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FakeEditor::fake(['editor-out']);
});

test('the base test case uses InteractsWithSwarmEvents and the trait activates the recorder', function (): void {
    // The trait is the public seam a consumer `use`s; the package dogfoods it on
    // its own base TestCase, so its setUp hook is what activates the recorder here.
    expect(class_uses_recursive($this::class))->toContain(InteractsWithSwarmEvents::class)
        ->and(app(SwarmEventRecorder::class)->isActive())->toBeTrue();
});

test('recordableEvents is the single source for the captured lifecycle events (#324)', function (): void {
    expect(SwarmEventRecorder::recordableEvents())
        ->toContain(SwarmStarted::class, SwarmCompleted::class);
});

test('the documented flow works end to end: a real run fires events assertEventFired can see (#324)', function (): void {
    FakeSequentialSwarm::make()->run('event-task');

    FakeSequentialSwarm::assertEventFired(SwarmStarted::class);
    FakeSequentialSwarm::assertEventFired(SwarmCompleted::class);
});
