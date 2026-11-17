<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeChildAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeFailoverAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeHttpAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeLimitedAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeTool;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\RateLimitedException;

// Attribute coverage to the C3 mapping boundary, not incidental application boot.
covers(StreamEventMapper::class, SwarmToolResult::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('ai.providers.openai.key', 'test-key');
    foreach (['primary', 'fallback'] as $provider) {
        config()->set('ai.providers.'.$provider, ['driver' => 'openai', 'key' => $provider.'-key']);
    }
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    NativeTool::$calls = 0;
    Event::fake([SwarmCompleted::class, SwarmFailed::class, SwarmStepCompleted::class]);
});

/**
 * Whether laravel/ai's AgentTool still degrades a child-run failure to a result.
 *
 * Up to and including v1.4.0, BOTH `AgentTool::handle()` and `AgentTool::stream()`
 * wrapped the child prompt in `catch (Throwable)` and returned
 * `'Agent failed: '.$e->getMessage()`, so a failing sub-agent never reached the
 * parent's tool-failure path at all. v1.4.1 removed both catches. This test
 * drives the streamed path, so `stream()` is the one that matters here.
 *
 * The expectation below 1.4.1 is therefore upstream's behaviour, and it is
 * upstream's removal that makes the original assertions unsatisfiable on 1.4.1 --
 * they fail at the release base too, with no Swarm listener installed. What Swarm
 * decides is the OUTCOME: a child-run failure is now an ordinary tool failure
 * carrying the PARENT agent, so the default `propagate` policy rethrows it and
 * the parent run fails, instead of the parent's model being handed a failed tool
 * result and carrying on. Swarm follows upstream rather than re-creating the
 * degradation; see docs/error-handling.md#a-sub-agents-own-run-failure.
 *
 * Not to be confused with a tool throwing INSIDE the child: that ToolFailed
 * carries the child agent, which Swarm is not invoking, so laravel/ai recovers
 * it inside the child and v1.4.1 changed nothing there.
 */
function nativeAgentToolDegradesChildFailure(): bool
{
    return ! InstalledVersions::satisfies(new VersionParser, 'laravel/ai', '>=1.4.1');
}

function nativeParitySse(?string $tool = null): string
{
    $call = ['type' => 'function_call', 'id' => 'item', 'call_id' => 'provider-call', 'name' => $tool, 'arguments' => $tool === 'NativeChildAgent' ? '{"task":"child-task"}' : '{"value":"argument-secret"}'];
    $message = ['type' => 'message', 'id' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'native-output']]];
    $response = ['id' => 'response', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$tool ? $call : $message], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    $events = [['type' => 'response.created', 'response' => $response]];
    if ($tool !== null) {
        $events[] = ['type' => 'response.output_item.added', 'item' => $call, 'output_index' => 0];
        $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => 'item', 'arguments' => $call['arguments']];
    } else {
        $events[] = ['type' => 'response.output_item.added', 'item' => $message, 'output_index' => 0];
        $events[] = ['type' => 'response.output_text.delta', 'item_id' => 'message', 'delta' => 'native-output'];
        $events[] = ['type' => 'response.output_text.done', 'item_id' => 'message', 'text' => 'native-output'];
    }
    $events[] = ['type' => 'response.completed', 'response' => $response];

    return implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
}

it('retains native pre-output failover identity and counts usage once', function () {
    $invocations = [];
    $failovers = [];
    app('events')->listen(StreamingAgent::class, function ($event) use (&$invocations) {
        $invocations[] = $event->invocationId;
    });
    app('events')->listen(AgentFailedOver::class, function ($event) use (&$failovers) {
        $failovers[] = $event;
    });
    Http::fake(function (Request $request) {
        expect(DB::connection()->transactionLevel())->toBe(0);

        return $request->hasHeader('Authorization', 'Bearer primary-key')
            ? Http::response(['error' => ['message' => 'rate limited', 'type' => 'rate_limit_exceeded']], 429)
            : Http::response(nativeParitySse());
    });
    $stream = app(SwarmRunner::class)->agent(new NativeFailoverAgent)->stream('task');
    $events = collect(iterator_to_array($stream));
    expect($invocations)->toHaveCount(2)->and(array_unique($invocations))->toHaveCount(1)
        ->and($failovers)->toHaveCount(1)->and($failovers[0]->invocationId)->toBe($invocations[0]);
    expect($events->whereInstanceOf(SwarmTextDelta::class)->sole()->invocationId)->toBe($invocations[0])
        ->and($stream->streamedResponse->output)->toBe('native-output')
        ->and($stream->streamedResponse->usage['input_tokens'])->toBe(2)
        ->and($stream->streamedResponse->usage['output_tokens'])->toBe(3);
    Event::assertDispatchedTimes(SwarmCompleted::class, 1);
    Event::assertDispatchedTimes(SwarmStepCompleted::class, 1);
    Http::assertSentCount(2);
});

it('preserves released validation and nested AgentTool result semantics', function (string $mode) {
    config()->set('tests.native_parity.tool', $mode);
    $native = [];
    app('events')->listen('Laravel\\Ai\\Events\\*', function ($name, $arguments) use (&$native) {
        $native[$name][] = $arguments[0];
    });
    $parentRequests = 0;
    Http::fake(function (Request $request) use (&$parentRequests, $mode) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        if (($request['input'][0]['content'] ?? null) === 'child-native-parity') {
            return Http::response(['error' => ['message' => 'child denied', 'type' => 'rate_limit_exceeded']], 429);
        }
        $parentRequests++;

        return Http::response(nativeParitySse($parentRequests === 1 ? ($mode === 'child' ? 'NativeChildAgent' : 'NativeTool') : null));
    });
    $stream = app(SwarmRunner::class)->agent(new NativeHttpAgent)->stream('task');

    if ($mode === 'child' && ! nativeAgentToolDegradesChildFailure()) {
        // laravel/ai >= 1.4.1: the child's own RateLimitedException escapes
        // AgentTool::stream() and reaches the parent's tool-failure path, so the
        // default propagate policy ends the parent run with the child's
        // exception rather than handing the parent's model a failed tool result.
        expect(fn () => iterator_to_array($stream))->toThrow(RateLimitedException::class);
        // This recorder is a wildcard registered in the test body, so it sits
        // after Swarm's own `booted()` wildcard and the rethrow ends the
        // dispatch before it runs -- ToolFailed stays unseen here. Empty for a
        // different reason than below 1.4.1, where no tool failure happens at
        // all. docs/error-handling.md#native-tool-failures carries the cost.
        expect($native[ToolFailed::class] ?? [])->toHaveCount(0);

        // Both agents now fail, in order: the child on its own 429, then the
        // parent once the rethrow leaves its tool loop. On <= 1.4.0 only the
        // child failed, because the catch absorbed it into a parent result.
        // The parent fails with the child's SAME exception object, which is what
        // shows it was delegated back rather than re-wrapped on the way out.
        expect($native[AgentFailed::class])->toHaveCount(2)
            ->and($native[AgentFailed::class][0]->prompt->agent)->toBeInstanceOf(NativeChildAgent::class)
            ->and($native[AgentFailed::class][1]->prompt->agent)->toBeInstanceOf(NativeHttpAgent::class)
            ->and($native[AgentFailed::class][1]->exception)->toBeInstanceOf(RateLimitedException::class)
            ->and($native[AgentFailed::class][1]->exception)->toBe($native[AgentFailed::class][0]->exception)
            ->and(app(RunHistoryStore::class)->find($stream->runId)['status'])->toBe('failed');

        // The parent never reaches a second turn, so there is no third request.
        Http::assertSentCount(2);
        Event::assertDispatchedTimes(SwarmFailed::class, 1);
        Event::assertNotDispatched(SwarmCompleted::class);

        return;
    }

    $events = collect(iterator_to_array($stream));
    $result = $events->whereInstanceOf(SwarmToolResult::class)->where('preliminary', false)->sole();
    expect($result->successful)->toBeTrue()->and($result->toolResult->failed)->toBeFalse()
        ->and($result->toolResult->denied)->toBeFalse()->and($result->error)->toBeNull()
        ->and($result->toolResult->result)->toContain($mode === 'child' ? 'Agent failed:' : 'validation-secret')
        ->and($native[ToolFailed::class] ?? [])->toBe([])
        ->and($native[InvokingTool::class])->toHaveCount(1)
        ->and($native[ToolInvoked::class])->toHaveCount(1)
        ->and($stream->streamedResponse->usage['input_tokens'])->toBe(4)
        ->and($stream->streamedResponse->usage['output_tokens'])->toBe(6);
    $toolInvocation = $native[ToolInvoked::class][0]->toolInvocationId;
    expect($toolInvocation)->not->toBe('provider-call')
        ->and($result->toolResult->id)->toBe('item')
        ->and($result->toolResult->resultId)->toBe('provider-call')
        ->and($result->toArray())->not->toHaveKeys(['tool_invocation_id', 'generation_id', 'parent_invocation_id']);
    if ($mode === 'child') {
        $child = collect($native[StreamingAgent::class])->first(fn ($event) => $event->prompt->agent instanceof NativeChildAgent);
        expect($child->prompt->parentInvocationId)->toBe($result->invocationId)
            ->and($child->prompt->parentToolInvocationId)->toBe($toolInvocation)
            ->and($native[AgentFailed::class])->toHaveCount(1)
            ->and(NativeTool::$calls)->toBe(0);
    } else {
        expect(NativeTool::$calls)->toBe(1);
    }
    Event::assertNotDispatched(SwarmFailed::class);
    Event::assertDispatchedTimes(SwarmCompleted::class, 1);
    Http::assertSentCount($mode === 'child' ? 3 : 2);
})->with(['validation', 'child']);

it('preserves a failed max-step result without claiming the tool executed', function () {
    $invoked = 0;
    app('events')->listen(ToolInvoked::class, function () use (&$invoked) {
        $invoked++;
    });
    Http::fake(['*' => Http::response(nativeParitySse('NativeTool'))]);
    $stream = app(SwarmRunner::class)->agent(new NativeLimitedAgent)->stream('task');
    $result = collect(iterator_to_array($stream))->whereInstanceOf(SwarmToolResult::class)->sole();
    expect($result->successful)->toBeFalse()->and($result->toolResult->failed)->toBeTrue()
        ->and($result->toolResult->denied)->toBeFalse()->and($result->error)->toContain('maximum number of steps')
        ->and(NativeTool::$calls)->toBe(0)->and($invoked)->toBe(0);
    $replayed = collect(iterator_to_array(app(StreamEventStore::class)->events($stream->runId)))->whereInstanceOf(SwarmToolResult::class)->sole();
    expect($replayed->toArray())->toBe($result->toArray());
    Http::assertSentCount(1);
});

it('does not restart after native output or complete replay on a native failure', function (string $failure) {
    config()->set('tests.native_parity.tool', $failure === 'tool' ? 'throwable' : 'ordinary');
    $failedTools = 0;
    $failovers = 0;
    app('events')->listen(ToolFailed::class, function () use (&$failedTools) {
        $failedTools++;
    });
    app('events')->listen(AgentFailedOver::class, function () use (&$failovers) {
        $failovers++;
    });
    if ($failure === 'after-effect') {
        app('events')->listen(ToolInvoked::class, fn () => throw RateLimitedException::forProvider('primary'));
    }
    if ($failure === 'native-callback') {
        app('events')->listen(AgentStreamed::class, fn () => throw new RuntimeException('native streamed observer failed'));
    }
    $requests = 0;
    Http::fake(function () use (&$requests, $failure) {
        $requests++;

        return Http::response(nativeParitySse($failure !== 'native-callback' ? 'NativeTool' : null));
    });
    $stream = app(SwarmRunner::class)->agent(new NativeFailoverAgent)->stream('task');
    expect(fn () => iterator_to_array($stream))->toThrow($failure === 'after-effect' ? RateLimitedException::class : RuntimeException::class);
    expect($requests)->toBe(1)->and($failovers)->toBe(0)
        ->and($failedTools)->toBe($failure === 'tool' ? 1 : 0)
        ->and(NativeTool::$calls)->toBe($failure === 'native-callback' ? 0 : 1)
        ->and($stream->streamedResponse)->toBeNull()
        ->and(app(RunHistoryStore::class)->find($stream->runId)['status'])->toBe('failed');
    Event::assertNotDispatched(SwarmCompleted::class);
    Event::assertNotDispatched(SwarmStepCompleted::class);
    $stored = collect(iterator_to_array(app(StreamEventStore::class)->events($stream->runId)));
    expect($stored->whereInstanceOf(SwarmStreamEnd::class))->toHaveCount(0);
})->with(['tool', 'after-effect', 'native-callback']);
