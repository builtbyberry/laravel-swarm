<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\NativeToolFailurePolicy;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeCapabilities\CapabilityAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure\FailingTool;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure\NativeToolFailureWire;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\NativeToolFailure\SiblingTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\ToolFailed;

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
function nativeToolFailureWire(array $tools, ?int &$turns = null): void
{
    $turns = 0;

    Http::fake(function (Request $request) use ($tools, &$turns) {
        $turns++;

        return Http::response($turns === 1
            ? NativeToolFailureWire::toolCalls($tools)
            : NativeToolFailureWire::message());
    });
}

it('propagates the tool handler exception itself, not laravel/ai 1.4 internal wrapper', function () {
    nativeToolFailureWire(['FailingTool']);

    $thrown = null;

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
});

it('still dispatches ToolFailed to the application before the run fails', function () {
    // Swarm propagates by rethrowing from a WILDCARD ToolFailed listener, which
    // Illuminate\Events\Dispatcher merges AFTER every exact-name listener. This
    // pins that ordering: an application listener registered the ordinary way
    // must still see the failure. Registering Swarm's rethrow by exact name
    // instead would pre-empt this listener and leave $failures at 0.
    $failures = 0;
    $seen = null;
    app('events')->listen(ToolFailed::class, function (ToolFailed $event) use (&$failures, &$seen) {
        $failures++;
        $seen = $event->exception->getMessage();
    });

    nativeToolFailureWire(['FailingTool']);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
    expect($failures)->toBe(1)->and($seen)->toBe(FailingTool::MESSAGE);
});

it('does not run a sibling tool call from the same step once one tool has failed', function () {
    // laravel/ai executes EVERY tool call in a step before yielding any result,
    // so this is what distinguishes rethrowing from inside laravel/ai's own
    // catch block — where 1.0-1.3 died, and the sibling never ran — from
    // deferring the throw to a later Swarm boundary, which would let the
    // sibling's side effect happen first.
    nativeToolFailureWire(['FailingTool', 'SiblingTool']);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool, new SiblingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
    expect(FailingTool::$calls)->toBe(1)
        ->and(SiblingTool::$calls)->toBe(0);
});

it('falls back to propagating when the configured policy is not recognised', function () {
    // The policy is read from inside the tool-failure path, so an unrecognised
    // value must not throw a ValueError there and mask the tool's own
    // exception. NativeToolFailurePolicy::tryFromConfig() defaults silently.
    config()->set('swarm.native_tool_failures', 'not-a-policy');

    nativeToolFailureWire(['FailingTool']);

    expect(fn () => app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool]))->prompt('task'))
        ->toThrow(RuntimeException::class, FailingTool::MESSAGE);
});

it('defers to laravel/ai and lets the run continue when the policy is recover', function () {
    // laravel/ai only grew tool-failure recovery in v1.4.0 (the release that
    // added the internal ToolExecutionException), so there is nothing to defer
    // to below it and this guard is 1.4+ only. Feature-detected rather than
    // version-pinned, and only ever in a test — never in src/.
    if (! class_exists('Laravel\Ai\Exceptions\ToolExecutionException')) {
        expect(true)->toBeTrue();

        return;
    }

    config()->set('swarm.native_tool_failures', NativeToolFailurePolicy::Recover->value);

    nativeToolFailureWire(['FailingTool', 'SiblingTool'], $turns);

    $response = app(SwarmRunner::class)->agent(new CapabilityAgent([new FailingTool, new SiblingTool]))->prompt('task');

    // The run settles normally, the model was handed the failure and answered,
    // and the sibling ran — the exact inverse of the propagate guards above.
    expect($response->output)->toBe('workflow-complete')
        ->and($turns)->toBe(2)
        ->and(FailingTool::$calls)->toBe(1)
        ->and(SiblingTool::$calls)->toBe(1);
});
