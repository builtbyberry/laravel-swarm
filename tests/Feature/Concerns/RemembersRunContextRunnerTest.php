<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RememberingWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FailingSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RememberingSequentialSwarm;
use Laravel\Ai\Messages\Message;

/**
 * Each runner publishes the active run around its agent invocations, so an
 * agent using RemembersRunContext sees the run's propagation-policy memory view
 * via messages(). After the run the ambient context must be clear.
 */
beforeEach(function () {
    RememberingWriter::resetCaptured();
    RememberingWriter::fake(['writer-out']);
});

afterEach(function () {
    ActiveRunContext::exit();
});

/**
 * @return array<int, string|null>
 */
function firstCapturedContents(): array
{
    expect(RememberingWriter::$capturedMessages)->not->toBeEmpty();

    return array_map(
        static fn (Message $message): ?string => $message->content,
        RememberingWriter::$capturedMessages[0],
    );
}

test('the sequential runner exposes run memory to the agent', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'seq-run', 'brief', 'ship it');

    RememberingSequentialSwarm::make()->run(RunContext::fake(['run_id' => 'seq-run', 'input' => 'go']));

    expect(firstCapturedContents())->toContain('brief: ship it');
    expect(ActiveRunContext::current())->toBeNull();
});

test('the parallel runner exposes run memory to the worker closure', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'par-run', 'brief', 'in parallel');

    RememberingParallelSwarm::make()->run(RunContext::fake(['run_id' => 'par-run', 'input' => 'go']));

    expect(firstCapturedContents())->toContain('brief: in parallel');
    expect(ActiveRunContext::current())->toBeNull();
});

test('the streaming runner exposes run memory to the agent', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'stream-run', 'brief', 'stream it');

    $response = RememberingSequentialSwarm::make()->stream(RunContext::fake(['run_id' => 'stream-run', 'input' => 'go']));
    iterator_to_array($response);

    expect(firstCapturedContents())->toContain('brief: stream it');
    expect(ActiveRunContext::current())->toBeNull();
});

test('the active run context is cleared even when the agent throws', function () {
    try {
        FailingSequentialSwarm::make()->run('go');
    } catch (Throwable) {
        // The agent throws by design; we only care that the finally cleared the
        // ambient context.
    }

    expect(ActiveRunContext::current())->toBeNull();
});
