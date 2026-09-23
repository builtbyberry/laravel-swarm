<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\StreamEventMapper;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\ToolResultEncoding;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeHttpAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Streaming\Events\ToolResult as NativeToolResult;

covers(StreamEventMapper::class, StaticHierarchicalStreamRunner::class, SwarmToolResult::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('ai.providers.openai.key', 'test-key');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ActiveRunContext::flush();
    PreliminaryResultAgent::$calls = 0;
    Http::preventStrayRequests();
});

afterEach(fn () => ActiveRunContext::flush());

function preliminaryStream(string $path, string $scenario): StreamableSwarmResponse
{
    $context = RunContext::from($scenario, 'preliminary-'.$scenario);

    return $path === 'static'
        ? (new PreliminaryResultStaticSwarm($scenario))->stream($context)
        : app(SwarmRunner::class)->agent(new PreliminaryResultAgent)->stream($context);
}

it('keeps interleaved partials out of snapshots until one final per call in both mapping paths', function (string $path, string $scenario) {
    $stream = preliminaryStream($path, $scenario);
    $seen = [];
    $finalized = [];
    foreach ($stream as $event) {
        if (! $event instanceof SwarmToolResult) {
            continue;
        }
        $seen[] = $event;
        $id = $event->toolResult->id;
        if (! $event->preliminary) {
            $finalized[$id] = 'complete-secret-'.$id;
        }
        $snapshot = app(SnapshotsMemory::class)->find($stream->runId, 0);
        expect(array_column($snapshot->toolCalls, 'result', 'id'))->toBe($finalized)
            ->and($snapshot->toolCalls)->toHaveCount(count($finalized))
            ->and($event->invocationId)->toBe('parent-'.$scenario)
            ->and($event->nodeId)->toBe($path === 'static' ? 'worker' : null)
            ->and($event->toolResult->resultId)->toBe('result-'.$id)
            ->and($event->denied)->toBe($scenario === 'event-denied')
            ->and($event->toolResult->denied)->toBe($scenario === 'denied')
            ->and($event->toolResult->failed)->toBe($scenario === 'failed')
            ->and($event->successful)->toBe(! in_array($scenario, ['denied', 'failed'], true))
            ->and($event->error)->toBe(in_array($scenario, ['denied', 'failed'], true) ? 'error-secret' : null);
    }
    expect(array_map(fn ($event) => $event->preliminary, $seen))->toBe([...array_fill(0, 8, true), false, false, false])
        ->and(array_map(fn ($event) => $event->timestamp, $seen))->toBe(range(1710000002, 1710000012))
        ->and(array_map(fn ($event) => $event->id, $seen))->toBe(['partial-a-1', 'partial-b-1', 'partial-a-2', 'partial-b-2', 'partial-a-3', 'partial-b-3', 'partial-a-4', 'partial-b-4', 'final-0', 'final-1', 'final-2'])
        ->and($stream->streamedResponse->output)->toBe('final output');
})->with(['sequential', 'static'])->with(['ordinary', 'denied', 'event-denied', 'failed']);

it('keeps function-tool capture and encoding policies for partials and finals', function (string $path, CaptureDecision $capture, string $scenario) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $capture));
    $events = collect(iterator_to_array(preliminaryStream($path, $scenario)))->whereInstanceOf(SwarmToolResult::class)->values();
    expect($events)->toHaveCount(11);
    foreach ($events as $event) {
        $wire = $event->toArray();
        expect(json_decode(json_encode($wire, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($wire);
        expect($wire['preliminary'])->toBe($event->preliminary)->and($wire['denied'])->toBeFalse()
            ->and($wire['tool_result']['id'])->toBe($event->toolResult->id)
            ->and($wire['tool_result']['result_id'])->toBe($event->toolResult->resultId);
        if ($capture === CaptureDecision::Skip) {
            expect($event->toolResult->arguments)->toBe([])->and($event->toolResult->result)->toBeNull()->and($event->error)->toBeNull();
        } elseif ($capture === CaptureDecision::Redact) {
            $arguments = ['secret' => ['input' => '[redacted]']];
            if ($scenario === 'unencodable') {
                $arguments['bad'] = '[redacted]';
            }
            expect($event->toolResult->arguments)->toBe($arguments)->and($event->toolResult->result)->toBe('[redacted]')
                ->and($event->error)->toBe($scenario === 'failed' ? '[redacted]' : null);
            expect(json_encode($wire, JSON_THROW_ON_ERROR))->not->toContain('argument-secret', 'partial-secret', 'complete-secret', 'error-secret');
        } elseif ($scenario === 'unencodable') {
            expect($wire['tool_result']['arguments'])->toBe([ToolResultEncoding::UNENCODABLE_ARGUMENTS_MARKER => true, 'tool' => 'lookup']);
            expect($wire['tool_result']['result'])->toBe($event->preliminary
                ? [ToolResultEncoding::UNENCODABLE_MARKER => true, 'tool' => 'lookup']
                : 'complete-secret-'.$event->toolResult->id);
        } else {
            expect($event->toolResult->arguments)->toBe(['secret' => ['input' => 'argument-secret']])
                ->and($event->toolResult->result)->toContain($event->preliminary ? 'partial-secret' : 'complete-secret')
                ->and($event->error)->toBe($scenario === 'failed' ? 'error-secret' : null);
            if ($event->preliminary) {
                expect(strlen($event->toolResult->result))->toBeGreaterThan(16000);
            }
        }
    }
})->with(['sequential', 'static'])->with(CaptureDecision::cases())->with(['ordinary', 'failed', 'unencodable']);

it('records only unpaired calls after partials then an ordinary abort or iterator abandonment', function (string $path, bool $abandon) {
    $stream = preliminaryStream($path, $abandon ? 'ordinary' : 'abort');
    if ($abandon) {
        $iterator = $stream->getIterator();
        while (! $iterator->current() instanceof SwarmToolResult) {
            $iterator->next();
        }
        expect($iterator->current()->preliminary)->toBeTrue();
        unset($iterator);
        gc_collect_cycles();
    } else {
        expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'ordinary stream abort after partials');
    }
    $snapshot = app(SnapshotsMemory::class)->find($stream->runId, 0);
    expect($snapshot->toolCalls)->toHaveCount(2)
        ->and(array_column($snapshot->toolCalls, 'result'))->toBe([null, null])
        ->and($stream->streamedResponse)->toBeNull()
        ->and(app(RunHistoryStore::class)->find($stream->runId)['status'])->toBe('failed');
})->with(['sequential', 'static'])->with([false, true]);

function preliminaryNativeSse(?string $tool, array $deltas = ['parent output']): string
{
    $call = ['type' => 'function_call', 'id' => 'native-item', 'call_id' => 'native-call', 'name' => $tool, 'arguments' => '{"task":"child-task"}'];
    $message = ['type' => 'message', 'id' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => implode('', $deltas)]]];
    $response = ['id' => 'response', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [$tool ? $call : $message], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
    $events = [['type' => 'response.created', 'response' => $response], ['type' => 'response.output_item.added', 'item' => $tool ? $call : $message, 'output_index' => 0]];
    if ($tool) {
        $events[] = ['type' => 'response.function_call_arguments.done', 'item_id' => 'native-item', 'arguments' => $call['arguments']];
    } else {
        foreach ($deltas as $delta) {
            $events[] = ['type' => 'response.output_text.delta', 'item_id' => 'message', 'delta' => $delta];
        }
        $events[] = ['type' => 'response.output_text.done', 'item_id' => 'message', 'text' => implode('', $deltas)];
    }
    $events[] = ['type' => 'response.completed', 'response' => $response];

    return implode('', array_map(fn ($event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
}

it('preserves real native nested AgentTool progress as parent observations and snapshots its final once', function (string $path) {
    config()->set('tests.native_parity.tool', 'child');
    $nativeResults = [];
    app('events')->listen(AgentStreamed::class, function ($event) use (&$nativeResults) {
        if ($event->prompt->agent::class === NativeHttpAgent::class) {
            $nativeResults = $event->response->events->whereInstanceOf(NativeToolResult::class)->values()->all();
        }
    });
    $requests = 0;
    $deltas = [str_repeat('a', 2048), str_repeat('b', 2048)];
    Http::fake(function (Request $request) use (&$requests, $deltas) {
        expect($request['stream'])->toBeTrue();
        $requests++;

        return Http::response(match ($requests) {
            1 => preliminaryNativeSse('NativeChildAgent'),
            2 => preliminaryNativeSse(null, $deltas),
            3 => preliminaryNativeSse(null),
            default => throw new RuntimeException('Unexpected provider request.'),
        });
    });
    $stream = $path === 'static'
        ? (new PreliminaryResultStaticSwarm('task', NativeHttpAgent::class))->stream('task')
        : app(SwarmRunner::class)->agent(new NativeHttpAgent)->stream('task');
    $results = [];
    foreach ($stream as $event) {
        if (! $event instanceof SwarmToolResult) {
            continue;
        }
        $results[] = $event;
        $calls = app(SnapshotsMemory::class)->find($stream->runId, 0)->toolCalls;
        expect($calls)->toHaveCount($event->preliminary ? 0 : 1)
            ->and($event->toolResult->id)->toBe('native-item')
            ->and($event->toolResult->resultId)->toBe('native-call')
            ->and($event->successful)->toBeTrue()->and($event->denied)->toBeFalse();
    }
    $results = collect($results);
    $partials = $results->where('preliminary', true);
    $final = $results->where('preliminary', false)->sole();
    expect($partials->count())->toBeGreaterThan(1)
        ->and($partials->pluck('toolResult.result')->all())->toContain($deltas[0], implode('', $deltas))
        ->and($results->pluck('invocationId')->unique())->toHaveCount(1)
        ->and($final->invocationId)->not->toBeNull()
        ->and($final->toolResult->result)->toBe(implode('', $deltas))
        ->and(app(SnapshotsMemory::class)->find($stream->runId, 0)->toolCalls[0]['result'])->toBe(implode('', $deltas))
        ->and($stream->streamedResponse->output)->toBe('parent output');
    expect($nativeResults)->toHaveCount($results->count());
    foreach ($nativeResults as $index => $native) {
        $mapped = $results[$index];
        expect($mapped->id)->toBe($native->id)->and($mapped->timestamp)->toBe($native->timestamp)
            ->and($mapped->invocationId)->toBe($native->invocationId)
            ->and($mapped->preliminary)->toBe($native->preliminary)->and($mapped->denied)->toBe($native->denied)
            ->and($mapped->toolResult->toArray())->toBe($native->toolResult->toArray());
    }
    Http::assertSentCount(3);
})->with(['sequential', 'static']);
