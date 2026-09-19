<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\StreamableSwarmResponse;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeResultAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\NativeResultStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestTerminated;

require_once __DIR__.'/Fixtures/WorkerResetStub.php';

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    ActiveRunContext::flush();
});

afterEach(fn () => ActiveRunContext::flush());

function nativeParityStream(string $path, string $label): StreamableSwarmResponse
{
    $context = RunContext::from($label, 'run-'.$label);

    return $path === 'static'
        ? (new NativeResultStaticSwarm($label))->stream($context)
        : app(SwarmRunner::class)->agent(new NativeResultAgent)->stream($context);
}

it('preserves native result status and identity through capture and database replay', function (string $path, string $status, CaptureDecision $capture) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $capture));
    $stream = nativeParityStream($path, $status);
    $events = collect(iterator_to_array($stream));
    $result = $events->whereInstanceOf(SwarmToolResult::class)->sole();
    $denied = str_contains($status, 'denied');
    $failed = str_contains($status, 'failed');
    expect($result->toolResult->denied)->toBe($denied)
        ->and($result->toolResult->failed)->toBe($failed)
        ->and($result->successful)->toBe(! $denied && ! $failed)
        ->and($result->toolResult->successful())->toBe($result->successful)
        ->and($result->toolResult->id)->toBe('shared-call')
        ->and($result->toolResult->resultId)->toBe('shared-result');
    $payload = $result->toArray();
    expect($payload['tool_result']['denied'] ?? false)->toBe($denied)
        ->and($payload['tool_result']['failed'] ?? false)->toBe($failed);
    $native = $events->filter(fn ($event) => str_starts_with($event->id, $status.'-'))->values();
    expect($native->pluck('id')->all())->toBe(array_map(fn ($suffix) => $status.'-'.$suffix, ['delta', 'reasoning', 'reasoning-end', 'call', 'result', 'text-end']))
        ->and($native->pluck('timestamp')->all())->toBe(range(1710000001, 1710000006))
        ->and($native->pluck('invocationId')->unique()->all())->toBe(['invocation-'.$status]);
    foreach ($native as $event) {
        expect($event->runId)->toBe($stream->runId)
            ->and($event->nodeId)->toBe($path === 'static' ? 'worker' : null)
            ->and($event->toArray())->not->toHaveKeys(['generation_id', 'parent_invocation_id', 'tool_invocation_id']);
    }
    $end = $events->whereInstanceOf(SwarmStreamEnd::class)->sole();
    expect($end->usage['prompt_tokens'])->toBe(2)->and($end->usage['completion_tokens'])->toBe(3);
    $replay = collect(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId)));
    expect($replay->whereInstanceOf(SwarmToolResult::class)->sole()->toArray())->toBe($payload);
    if ($capture !== CaptureDecision::Full) {
        $stored = DB::table('swarm_stream_events')->where('run_id', $stream->runId)->pluck('payload')->implode('');
        expect($stored)->not->toContain('argument-secret', 'result-secret', 'reason-secret', 'summary-secret');
        expect($result->toolResult->arguments)->toBe($capture === CaptureDecision::Skip ? [] : ['secret' => '[redacted]'])
            ->and($result->toolResult->result)->toBe($capture === CaptureDecision::Skip ? null : '[redacted]')
            ->and($result->error)->toBe($result->successful || $capture === CaptureDecision::Skip ? null : '[redacted]');
    }
})->with(['sequential', 'static'])->with(['ordinary', 'denied', 'failed', 'denied-failed'])->with(CaptureDecision::cases());

it('does not fabricate absent native invocation identity', function (string $path) {
    $events = collect(iterator_to_array(nativeParityStream($path, 'missing-id-denied')));
    expect($events->whereInstanceOf(SwarmToolResult::class)->sole()->invocationId)->toBeNull();
})->with(['sequential', 'static']);

it('fails before Swarm completion when a native callback throws after StreamEnd', function (string $path) {
    Event::fake([SwarmCompleted::class, SwarmStepCompleted::class]);
    $stream = nativeParityStream($path, 'denied-native-callback');
    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'native callback failed after output');
    expect($stream->streamedResponse)->toBeNull();
    Event::assertNotDispatched(SwarmCompleted::class);
    Event::assertNotDispatched(SwarmStepCompleted::class);
    expect(app(RunHistoryStore::class)->find($stream->runId)['status'])->toBe('failed');
    $stored = collect(iterator_to_array(app(StreamEventStore::class)->events($stream->runId)));
    expect($stored->whereInstanceOf(SwarmStreamEnd::class))->toHaveCount(0)
        ->and($stored->whereInstanceOf(SwarmToolResult::class)->sole()->toolResult->denied)->toBeTrue();
})->with(['sequential', 'static']);

it('keeps generator-local pairing and identities during actual alternating iteration', function (string $path, bool $fibers) {
    $streams = [nativeParityStream($path, 'A-denied'), nativeParityStream($path, 'B-failed')];
    $seen = [[], []];
    if ($fibers) {
        $workers = array_map(fn ($stream) => new Fiber(function () use ($stream) {
            foreach ($stream as $event) {
                Fiber::suspend($event);
            }
        }), $streams);
        foreach ($workers as $i => $worker) {
            $seen[$i][] = $worker->start();
        }
        do {
            foreach ($workers as $i => $worker) {
                if ($worker->isSuspended()) {
                    $event = $worker->resume();
                    if ($event !== null) {
                        $seen[$i][] = $event;
                    }
                }
            }
        } while (! $workers[0]->isTerminated() || ! $workers[1]->isTerminated());
    } else {
        $iterators = array_map(fn ($stream) => $stream->getIterator(), $streams);
        do {
            foreach ($iterators as $i => $iterator) {
                if ($iterator->valid()) {
                    $seen[$i][] = $iterator->current();
                    $iterator->next();
                }
            }
        } while ($iterators[0]->valid() || $iterators[1]->valid());
    }
    foreach (['A-denied', 'B-failed'] as $i => $label) {
        $result = collect($seen[$i])->whereInstanceOf(SwarmToolResult::class)->sole();
        expect($result->runId)->toBe('run-'.$label)->and($result->invocationId)->toBe('invocation-'.$label)
            ->and($result->toolResult->denied)->toBe($i === 0)->and($result->toolResult->failed)->toBe($i === 1)
            ->and($result->toolResult->result)->toBe('result-secret-'.$label);
        $snapshot = app(SnapshotsMemory::class)->find('run-'.$label, 0);
        expect($snapshot->toolCalls)->toHaveCount(1)
            ->and($snapshot->toolCalls[0]['result'])->toBe('result-secret-'.$label)
            ->and($streams[$i]->streamedResponse->output)->toBe('output-'.$label);
    }
    expect(ActiveRunContext::current())->toBeNull();
})->with(['sequential', 'static'])->with([false, true]);

it('abandons an older iterator out of order without corrupting the surviving stream', function (string $path) {
    $abandoned = nativeParityStream($path, 'abandoned-denied');
    $surviving = nativeParityStream($path, 'surviving-failed');
    $first = $abandoned->getIterator();
    while (! $first->current() instanceof SwarmToolCall) {
        $first->next();
    }
    $second = $surviving->getIterator();
    while (! $second->current() instanceof SwarmToolCall) {
        $second->next();
    }
    unset($first);
    gc_collect_cycles();
    $seen = [];
    while ($second->valid()) {
        $seen[] = $second->current();
        $second->next();
    }
    expect($abandoned->streamedResponse)->toBeNull()
        ->and(app(RunHistoryStore::class)->find($abandoned->runId)['status'])->toBe('failed')
        ->and(collect(iterator_to_array(app(StreamEventStore::class)->events($abandoned->runId)))->whereInstanceOf(SwarmStreamEnd::class))->toHaveCount(0)
        ->and(collect($seen)->whereInstanceOf(SwarmToolResult::class)->sole()->toolResult->failed)->toBeTrue()
        ->and($surviving->streamedResponse->output)->toBe('output-surviving-failed');
    expect(app(SnapshotsMemory::class)->find($abandoned->runId, 0)->toolCalls[0]['result'])->toBeNull();
    ActiveRunContext::enter('stale', 'StaleSwarm', RunContext::from('stale', 'stale'));
    app('events')->dispatch(new RequestTerminated);
    expect(ActiveRunContext::current())->toBeNull();
    $fresh = nativeParityStream($path, 'fresh-ordinary');
    iterator_to_array($fresh);
    expect($fresh->streamedResponse->output)->toBe('output-fresh-ordinary')
        ->and(ActiveRunContext::current())->toBeNull();
})->with(['sequential', 'static']);

it('retains corrected evidence across sealing and cold-tier replay', function (string $path) {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $stream = nativeParityStream($path, 'cold-denied-failed');
    $events = collect(iterator_to_array($stream));
    $expected = $events->whereInstanceOf(SwarmToolResult::class)->sole()->toArray();
    $store = app(StreamEventStore::class);
    // Exercise graduation with an explicit storage boundary; synchronous streams
    // do not schedule the durable compactor.
    $store->record($stream->runId, new SwarmCausalSealBarrier(
        id: 'cold-boundary', runId: $stream->runId, timestamp: 1710000008,
    ), 3600);
    $barrier = (int) DB::table('swarm_stream_events')->where('run_id', $stream->runId)
        ->where('event_type', 'swarm_causal_seal_barrier')->value('id');
    expect($barrier)->toBeGreaterThan(0);
    $view = new CausalLogView($store->events($stream->runId));
    $cipher = app(SwarmPersistenceCipher::class);
    $snapshot = $cipher->seal(json_encode($view->snapshot(), JSON_THROW_ON_ERROR));
    $cold = app(DatabaseColdArchiveDriver::class);
    expect($cold->graduate($stream->runId, 0, $barrier, $snapshot))->toBeTrue();
    expect(DB::table('swarm_stream_events')->where('run_id', $stream->runId)->where('event_type', 'swarm_tool_result')->value('sealed_at'))->not->toBeNull();
    expect(DB::table('swarm_cold_archives')->where('run_id', $stream->runId)->where('archive_type', 'snapshot')->value('payload'))->toStartWith('sw0:');
    $cold->reclaim($stream->runId, $barrier);
    expect(DB::table('swarm_stream_events')->where('run_id', $stream->runId)->where('event_type', 'swarm_tool_result')->exists())->toBeFalse();
    $replayed = collect(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId)));
    expect($replayed->whereInstanceOf(SwarmToolResult::class)->sole()->toArray())->toBe($expected);
})->with(['sequential', 'static']);
