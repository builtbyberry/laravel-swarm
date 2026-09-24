<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Jobs\BroadcastSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\CacheStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseStreamEventStore;
use BuiltByBerry\LaravelSwarm\Persistence\TieredStreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\SwarmAttributeResolver;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Streaming\Fixtures\PreliminaryResultStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

covers(CacheStreamEventStore::class, DatabaseStreamEventStore::class, TieredStreamEventStore::class, SwarmHistory::class, BroadcastSwarm::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('swarm.streaming.replay.store', 'array');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Http::preventStrayRequests();
    PreliminaryResultAgent::$calls = 0;
});

function preliminaryReplayStore(string $driver): StreamEventStore
{
    $class = match ($driver) {
        'cache' => CacheStreamEventStore::class,
        'database' => DatabaseStreamEventStore::class,
        default => TieredStreamEventStore::class,
    };
    app()->forgetInstance($class);
    $store = app($class);
    app()->instance(StreamEventStore::class, $store);
    app()->forgetInstance(SwarmHistory::class);

    return $store;
}

function preliminaryReplayPayloads(iterable $events): array
{
    return collect($events)->map(fn (SwarmStreamEvent $event): array => $event->toArray())->values()->all();
}

function preliminaryColdSeam(string $runId): void
{
    $rows = DB::table('swarm_stream_events')->where('run_id', $runId)->orderBy('id')->get();
    $base = (int) $rows->where('event_type', 'swarm_tool_result')->values()[4]->id;
    $below = $rows->where('id', '<', $base);
    DB::transaction(function () use ($runId, $base, $below): void {
        foreach ($below as $row) {
            DB::table('swarm_cold_archives')->insert([
                'run_id' => $runId, 'archive_type' => 'event', 'sequence' => $row->id,
                'payload' => $row->payload, 'base_pointer' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('swarm_cold_archives')->insert([
            'run_id' => $runId, 'archive_type' => 'snapshot', 'sequence' => null,
            'payload' => '{}', 'base_pointer' => $base, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('swarm_stream_events')->where('run_id', $runId)->where('id', '<', $base)->delete();
    });
    expect(DB::table('swarm_cold_archives')->where('run_id', $runId)->where('archive_type', 'event')->count())->toBe($below->count())
        ->and(DB::table('swarm_stream_events')->where('run_id', $runId)->min('id'))->toBe($base);
}

function assertPreliminaryReplayResults(Collection $results, CaptureDecision $capture): void
{
    $expectedIds = [];
    foreach (range(1, 4) as $index) {
        $expectedIds[] = 'partial-a-'.$index;
        $expectedIds[] = 'partial-b-'.$index;
    }
    array_push($expectedIds, 'final-0', 'final-1', 'final-2');
    expect($results)->toHaveCount(11)
        ->and($results->pluck('id')->all())->toBe($expectedIds)
        ->and($results->pluck('timestamp')->all())->toBe(range(1710000002, 1710000012))
        ->and($results->pluck('preliminary')->all())->toBe([...array_fill(0, 8, true), false, false, false])
        ->and($results->pluck('denied')->unique()->all())->toBe([true])
        ->and($results->pluck('invocationId')->unique()->all())->toBe(['parent-event-denied']);
    foreach ($results as $result) {
        expect($result->toolResult->denied)->toBeFalse()
            ->and($result->toolResult->failed)->toBeFalse()
            ->and($result->successful)->toBeTrue()
            ->and($result->error)->toBeNull()
            ->and($result->toolResult->resultId)->toBe('result-'.$result->toolResult->id);
        if ($capture === CaptureDecision::Full) {
            expect($result->toolResult->arguments)->toBe(['secret' => ['input' => 'argument-secret']])
                ->and($result->toolResult->result)->toContain($result->preliminary ? 'partial-secret-' : 'complete-secret-');
        } else {
            expect($result->toolResult->arguments)->toBe($capture === CaptureDecision::Skip ? [] : ['secret' => ['input' => '[redacted]']])
                ->and($result->toolResult->result)->toBe($capture === CaptureDecision::Skip ? null : '[redacted]')
                ->and(json_encode($result->toArray(), JSON_THROW_ON_ERROR))->not->toContain('argument-secret', 'partial-secret', 'complete-secret');
        }
    }
}

it('replays preliminary events identically from fresh cache database and hot cold readers without invoking agents', function (string $driver, string $path, CaptureDecision $capture) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $capture));
    preliminaryReplayStore($driver);
    $context = RunContext::from('event-denied');
    $stream = $path === 'static'
        ? (new PreliminaryResultStaticSwarm('event-denied'))->stream($context)
        : app(SwarmRunner::class)->agent(new PreliminaryResultAgent)->stream($context);
    $live = collect(iterator_to_array($stream));
    $expected = preliminaryReplayPayloads($live);
    expect(PreliminaryResultAgent::$calls)->toBe(1);
    assertPreliminaryReplayResults($live->whereInstanceOf(SwarmToolResult::class)->values(), $capture);
    expect($live->whereInstanceOf(SwarmStreamEnd::class)->sole()->usage)->toBe([
        'input_tokens' => 2, 'output_tokens' => 3,
        'cache_read_input_tokens' => null, 'cache_write_input_tokens' => null, 'reasoning_tokens' => null,
    ]);
    $runId = $stream->runId;
    unset($stream, $live);
    if ($driver === 'tiered-cold') {
        preliminaryColdSeam($runId);
    }
    preliminaryReplayStore($driver);
    PreliminaryResultAgent::$calls = 0;
    $replay = app(SwarmHistory::class)->replay($runId);
    $replayedEvents = collect(iterator_to_array($replay));
    assertPreliminaryReplayResults($replayedEvents->whereInstanceOf(SwarmToolResult::class)->values(), $capture);
    $actual = preliminaryReplayPayloads($replayedEvents);
    expect($actual)->toBe($expected)
        ->and(PreliminaryResultAgent::$calls)->toBe(0)
        ->and($replay->streamedResponse->usage['reasoning_tokens'])->toBeNull();
    Http::assertNothingSent();
})->with(['cache', 'database', 'tiered-hot', 'tiered-cold'])->with(['sequential', 'static'])->with(CaptureDecision::cases());

it('preserves legacy missing flag defaults and unavailable aggregate usage across stored replay', function (string $driver) {
    preliminaryReplayStore($driver);
    $stream = app(SwarmRunner::class)->agent(new PreliminaryResultAgent)->stream('event-denied');
    iterator_to_array($stream);
    $runId = $stream->runId;
    $unavailable = array_fill_keys(['prompt_tokens', 'completion_tokens', 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null);
    $legacy = function (array $payload) use ($unavailable): array {
        if (($payload['id'] ?? null) === 'partial-a-1') {
            unset($payload['preliminary'], $payload['denied']);
            $payload['tool_result']['denied'] = true;
        }
        if (($payload['type'] ?? null) === 'swarm_stream_end') {
            $payload['usage'] = $unavailable;
        }

        return $payload;
    };
    // Construct old stored rows directly; these are reader fixtures, not a claim of historical producer evidence.
    if ($driver === 'cache') {
        $key = config('swarm.streaming.replay.prefix').$runId;
        Cache::store('array')->put($key, array_map($legacy, Cache::store('array')->get($key)), 3600);
    } else {
        foreach (DB::table('swarm_stream_events')->where('run_id', $runId)->get() as $row) {
            DB::table('swarm_stream_events')->where('id', $row->id)->update([
                'payload' => json_encode($legacy(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
            ]);
        }
    }
    if ($driver === 'tiered-cold') {
        preliminaryColdSeam($runId);
    }
    unset($stream);
    preliminaryReplayStore($driver);
    PreliminaryResultAgent::$calls = 0;
    $replay = app(SwarmHistory::class)->replay($runId);
    $events = collect(iterator_to_array($replay));
    $old = $events->whereInstanceOf(SwarmToolResult::class)->firstWhere('id', 'partial-a-1');
    $new = $events->whereInstanceOf(SwarmToolResult::class)->firstWhere('id', 'partial-b-1');
    expect($old->preliminary)->toBeFalse()->and($old->denied)->toBeTrue()->and($old->toolResult->denied)->toBeTrue()
        ->and($new->preliminary)->toBeTrue()->and($new->denied)->toBeTrue()->and($new->toolResult->denied)->toBeFalse()
        ->and($replay->streamedResponse->usage)->toBe($unavailable)
        ->and($replay->streamedResponse->steps[0]->metadata['usage']['input_tokens'])->toBe(2)
        ->and(PreliminaryResultAgent::$calls)->toBe(0);
    Http::assertNothingSent();
})->with(['cache', 'database', 'tiered-hot', 'tiered-cold']);

it('preserves preliminary event payloads through every broadcast helper and subsequent replay', function (string $verb, CaptureDecision $capture) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $capture, activeContext: CaptureDecision::Full));
    preliminaryReplayStore('tiered-hot');
    app()->bind(PreliminaryResultStaticSwarm::class, fn () => new PreliminaryResultStaticSwarm('event-denied'));
    Event::fake([AnonymousEvent::class]);
    $context = RunContext::from('broadcast task');
    if ($verb === 'broadcastOnQueue') {
        Bus::fake([BroadcastSwarm::class]);
        $pending = PreliminaryResultStaticSwarm::make()->broadcastOnQueue($context, new Channel('preliminary'));
        $job = unserialize(serialize($pending->getJob()));
        unset($pending);
        Bus::assertDispatchedTimes(BroadcastSwarm::class, 1);
        $job->handle(app(SwarmRunner::class), app(SwarmAttributeResolver::class));
    } else {
        PreliminaryResultStaticSwarm::make()->{$verb}($context, new Channel('preliminary'));
    }
    expect(PreliminaryResultAgent::$calls)->toBe(1);
    $broadcasts = Event::dispatched(AnonymousEvent::class)->map(fn (array $event): AnonymousEvent => $event[0]);
    $payloads = $broadcasts->map(fn (AnonymousEvent $event): array => $event->broadcastWith())->values()->all();
    expect($broadcasts->every(fn (AnonymousEvent $event): bool => $event->shouldBroadcastNow() === ($verb !== 'broadcast')))->toBeTrue();
    foreach ($broadcasts as $broadcast) {
        expect($broadcast->broadcastOn()[0]->name)->toBe('preliminary');
    }
    assertPreliminaryReplayResults(collect($payloads)->map(fn (array $payload): SwarmStreamEvent => SwarmStreamEvent::fromArray($payload))->whereInstanceOf(SwarmToolResult::class)->values(), $capture);
    PreliminaryResultAgent::$calls = 0;
    preliminaryReplayStore('tiered-hot');
    expect(preliminaryReplayPayloads(app(SwarmHistory::class)->replay($context->runId)))->toBe($payloads)
        ->and(PreliminaryResultAgent::$calls)->toBe(0);
    Http::assertNothingSent();
})->with(['broadcast', 'broadcastNow', 'broadcastOnQueue'])->with(CaptureDecision::cases());
