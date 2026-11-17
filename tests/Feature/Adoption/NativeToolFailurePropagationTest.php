<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Enums\NativeToolFailurePolicy;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\NativeCapabilityWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure\FailingTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure\SiblingTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\ToolFailed;

/**
 * The streamed path is covered by tests/Feature/Streaming/NativeHttpParityTest.php
 * (the `tool` dataset), which this component must keep green: stream tool calls
 * execute lazily while the consumer iterates, so that test is what proves the
 * run frame propagation is scoped to is still on the stack at that moment.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('ai.providers.openai.key', 'test-key');
    config()->set('ai.conversations.generate_title', false);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    FailingTool::$calls = 0;
    SiblingTool::$calls = 0;
});

/**
 * Fake the outer agent's text turns: request 1 calls the named tools in one
 * step, every later request settles the turn with a final message.
 *
 * @param  list<string>  $tools
 */
function nativeToolFailureTurns(array $tools): void
{
    $turns = 0;

    Http::fake(function (Request $request) use ($tools, &$turns) {
        $turns++;

        return Http::response($turns === 1
            ? NativeCapabilityWire::toolCalls($tools)
            : NativeCapabilityWire::message());
    });
}

function nativeToolFailureRecoveryExists(): bool
{
    return class_exists('Laravel\Ai\Exceptions\ToolExecutionException');
}

it('propagates the tool handler exception itself, not laravel/ai 1.4 internal wrapper', function () {
    nativeToolFailureTurns([class_basename(FailingTool::class)]);

    $thrown = null;
    $runId = null;
    Event::listen(ToolFailed::class, function () use (&$runId) {
        $runId = ActiveRunContext::current()?->runId;
    });

    try {
        app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task');
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // The handler's own exception, by class and message. laravel/ai >= 1.4 wraps
    // a throwing handler in an @internal ToolExecutionException before handing
    // the model a failed result; Swarm must surface neither the wrapper nor a
    // Swarm exception of its own, because 1.0-1.3 surfaced the handler's.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown::class)->toBe(RuntimeException::class)
        ->and($thrown->getMessage())->toBe(FailingTool::MESSAGE)
        ->and(FailingTool::$calls)->toBe(1);

    // The model is never handed the failure: the run dies inside the step
    // rather than taking a second turn to answer around it.
    Http::assertSentCount(1);

    // And the run takes Swarm's terminal failure path, as the docs promise.
    expect($runId)->not->toBeNull()
        ->and(app(RunHistoryStore::class)->find($runId)['status'])->toBe('failed');
});

it('still dispatches ToolFailed to the application before the run fails', function () {
    // Swarm propagates by rethrowing from a WILDCARD listener registered at
    // booted(), which Illuminate\Events\Dispatcher merges AFTER every
    // exact-name listener. This pins that ordering: an application listener
    // registered the ordinary way must still see the failure. Registering
    // Swarm's rethrow by exact name instead would pre-empt this listener and
    // leave $failures at 0.
    $failures = 0;
    $seen = null;
    app('events')->listen(ToolFailed::class, function (ToolFailed $event) use (&$failures, &$seen) {
        $failures++;
        $seen = $event->exception->getMessage();
    });

    nativeToolFailureTurns([class_basename(FailingTool::class)]);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
    expect($failures)->toBe(1)->and($seen)->toBe(FailingTool::MESSAGE);
});

it('does not run a sibling tool call from the same step once one tool has failed', function () {
    // laravel/ai executes EVERY tool call in a step before yielding any result,
    // so this is what distinguishes rethrowing from inside laravel/ai's own
    // catch block -- where 1.0-1.3 died, and the sibling never ran -- from
    // deferring the throw to a later Swarm boundary, which would let the
    // sibling's side effect happen first.
    nativeToolFailureTurns([class_basename(FailingTool::class), class_basename(SiblingTool::class)]);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool, new SiblingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
    expect(FailingTool::$calls)->toBe(1)
        ->and(SiblingTool::$calls)->toBe(0);
    Http::assertSentCount(1);
});

it('falls back to propagating when the configured policy cannot be read as a policy', function (mixed $configured) {
    // The policy is read from inside the tool-failure path, so no value may
    // throw there and mask the tool's own exception. A typo'd string is the
    // obvious case; the non-string ones are the reachable ones --
    // Illuminate\Support\Env casts `false`/`true` in .env to real booleans, and
    // the published config file imports the enum, inviting the enum case itself.
    config()->set('swarm.native_tool_failure_policy', $configured);

    nativeToolFailureTurns([class_basename(FailingTool::class)]);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
})->with([
    'typo' => 'not-a-policy',
    'bool false' => false,
    'bool true' => true,
    'int' => 1,
    'the enum itself, not its value' => NativeToolFailurePolicy::Recover,
]);

it('leaves a non-swarm laravel/ai agent run to laravel/ai', function () {
    // The listener is scoped to swarm runs: an agent the application prompts
    // directly is not Swarm's to govern, so on >= 1.4 it keeps upstream's
    // recovery. Below 1.4 upstream has none, so the raw exception propagates
    // there with or without Swarm -- version-asymmetric by nature.
    nativeToolFailureTurns([class_basename(FailingTool::class)]);

    $call = fn () => (new CapabilityAgent([new FailingTool]))->prompt('task');

    if (nativeToolFailureRecoveryExists()) {
        expect($call()->text)->toBe('workflow-complete');
        expect(FailingTool::$calls)->toBe(1);
        Http::assertSentCount(2);

        return;
    }

    expect($call)->toThrow(RuntimeException::class, FailingTool::MESSAGE);
});

it('defers to laravel/ai when the policy is recover', function () {
    config()->set('swarm.native_tool_failure_policy', NativeToolFailurePolicy::Recover->value);

    nativeToolFailureTurns([class_basename(FailingTool::class), class_basename(SiblingTool::class)]);

    $run = fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool, new SiblingTool]))->prompt('task');

    if (! nativeToolFailureRecoveryExists()) {
        // laravel/ai only grew tool-failure recovery in v1.4.0, so below it
        // there is nothing to defer to and `recover` is documented as
        // indistinguishable from `propagate`. Assert that, rather than skipping:
        // it is the floor behaviour the docs promise.
        expect($run)->toThrow(RuntimeException::class, FailingTool::MESSAGE);
        expect(SiblingTool::$calls)->toBe(0);

        return;
    }

    $response = $run();

    // The run settles normally, the sibling ran, and the model was genuinely
    // handed the failure -- the exact inverse of the propagate guards above.
    expect($response->output)->toBe('workflow-complete')
        ->and(FailingTool::$calls)->toBe(1)
        ->and(SiblingTool::$calls)->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(
        $request->body(),
        'The tool call failed: '.FailingTool::MESSAGE,
    ));
});

it('leaves laravel/ai recovery in place when the dispatch does not reach Swarm', function (Closure $arrange) {
    // The preconditions documented in docs/error-handling.md#when-propagate-does-not-apply.
    // Below 1.4 the floor branch asserts the documented floor outcome rather
    // than the precondition itself -- with no upstream recovery to leave in
    // place there is nothing for an arrangement to change, so that lane proves
    // the floor behaviour, not these cases.
    nativeToolFailureTurns([class_basename(FailingTool::class)]);
    $arrange();

    $run = fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task');

    if (! nativeToolFailureRecoveryExists()) {
        // Below 1.4 there is no recovery to leave in place, so the handler's
        // exception propagates whether or not Swarm's listener was reached.
        // Assert that rather than nothing: the lane must not run empty.
        expect($run)->toThrow(RuntimeException::class, FailingTool::MESSAGE);
        expect(FailingTool::$calls)->toBe(1);

        return;
    }

    expect($run()->output)->toBe('workflow-complete')
        ->and(FailingTool::$calls)->toBe(1);
})->with([
    'a bare Event::fake() dispatches nothing' => [fn () => Event::fake()],
    'a listener reached first that returns false halts the dispatch' => [
        fn () => app('events')->listen(ToolFailed::class, fn () => false),
    ],
]);

it('leaves a non-swarm agent alone while a swarm run frame names no invocation', function () {
    // End-to-end half of the scope gate. A run frame outlives the invocation
    // that opened it -- a runner enters it before calling the invoker, and a
    // streamed run keeps it up for the whole time a consumer iterates, because
    // that is when the stream's tool calls run -- so a frame on its own must
    // never make an application's own agent look in-run.
    //
    // The other half, where an invocation IS live and the gate must compare the
    // agent rather than the frame, is pinned in tests/Unit/ActiveRunContextInvokingTest.php
    // (identity not class, and a match in a frame below the top one). Reaching
    // that window end-to-end needs a streamed swarm over an SSE wire, which this
    // file's fixture does not provide.
    if (! nativeToolFailureRecoveryExists()) {
        // Below 1.4 there is no recovery to leave in place.
        expect(true)->toBeTrue();

        return;
    }

    $askedForTool = false;
    Http::fake(function (Request $request) use (&$askedForTool) {
        if (! $askedForTool && str_contains($request->body(), class_basename(FailingTool::class))) {
            $askedForTool = true;

            return Http::response(NativeCapabilityWire::toolCalls([class_basename(FailingTool::class)]));
        }

        return Http::response(NativeCapabilityWire::message());
    });

    ActiveRunContext::enter('left-behind-run', 'LeftBehindSwarm', new RunContext('left-behind-run', 'task'));

    try {
        expect(ActiveRunContext::current())->not->toBeNull()
            ->and(ActiveRunContext::current()?->invokingAgent)->toBeNull();

        FailingTool::$calls = 0;

        $response = (new CapabilityAgent([new FailingTool]))->prompt('task');

        expect($response->text)->toBe('workflow-complete')
            ->and(FailingTool::$calls)->toBe(1);
    } finally {
        ActiveRunContext::exit();
        ActiveRunContext::flush();
    }
});
