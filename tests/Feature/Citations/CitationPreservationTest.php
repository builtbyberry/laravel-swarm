<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Exceptions\LostDurableLeaseException;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Memory\StreamStepCheckpoint;
use BuiltByBerry\LaravelSwarm\Persistence\CitationEvidenceCodec;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\NativeCitationEvidence;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEnd;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationDurableStreamSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationGeneratedSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationLiteralSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationLoopSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParallelPlanSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParentSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\OtherCitingAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingAuditCapturePolicy;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.streaming.replay.enabled', true);
    config()->set('concurrency.default', 'sync');
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    CitingAgent::$calls = [];
});

it('keeps final sequential evidence separate from every prior step', function () {
    $response = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->prompt('task');
    expect($response->citations)->toHaveCount(1)
        ->and($response->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/1')
        ->and($response->steps[0]->citations[0]->url)->toBe('https://secret.example/CitingAgent/1')
        ->and($response->steps[1]->citations[0]->invocationId)->toBe('invocation-OtherCitingAgent-1')
        ->and($response->citations[0]->startIndex)->toBe(1)
        ->and($response->citations[0]->endIndex)->toBe(7);
    $history = app(RunHistoryStore::class)->find($response->context->runId);
    expect($history['citations'])->toBe($response->citationEvidence->toArray()['citations'])
        ->and($history['steps'][0]['citations'][0]['step_index'])->toBe(0);
});

it('projects all parallel output contributors in declaration order', function () {
    $response = app(SwarmRunner::class)->parallel([new CitingAgent, new OtherCitingAgent])->prompt('task');
    expect(array_column($response->citationEvidence->toArray()['citations'], 'step_index'))->toBe([0, 1])
        ->and($response->citationEvidence->status)->toBe('available')
        ->and($response->citations[1]->startIndex)->toBe(1);
});

it('preserves earlier selected hierarchical evidence in prompt and completed streams', function (bool $streamed) {
    $result = $streamed ? (new CitationStaticSwarm)->stream('task') : (new CitationStaticSwarm)->prompt('task');
    if ($streamed) {
        iterator_to_array($result);
        $response = $result->streamedResponse;
    } else {
        $response = $result;
    }
    expect($response->citations[0]->url)->toBe('https://secret.example/CitingAgent/1')
        ->and($response->citations[0]->nodeId)->toBe('first')
        ->and($response->steps[1]->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/1');
})->with([false, true]);

it('round trips native citation identity through completion replay and capture', function (CaptureDecision $capture) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $capture));
    $stream = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream('task');
    $events = collect(iterator_to_array($stream));
    $citation = $events->whereInstanceOf(SwarmCitation::class)->sole();
    $status = match ($capture) {
        CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted'
    };
    expect($citation->id)->toBe('citation-invocation-OtherCitingAgent-1')
        ->and($citation->timestamp)->toBe(1710000001)
        ->and($citation->invocationId)->toBe('invocation-OtherCitingAgent-1')
        ->and($events->whereInstanceOf(SwarmStreamEnd::class)->sole()->citationEvidence->status)->toBe($status)
        ->and($stream->streamedResponse->citationEvidence->status)->toBe('available')
        ->and($stream->streamedResponse->steps[0]->citationEvidence->status)->toBe('available');
    $replayed = collect(iterator_to_array(app(StreamEventStore::class)->events($stream->runId)));
    expect($replayed->map->toArray()->all())->toBe($events->map->toArray()->all());
    if ($capture === CaptureDecision::Full) {
        expect($stream->streamedResponse->citations[0]->startIndex)->toBe(1)
            ->and($stream->streamedResponse->citations[0]->eventId)->toBe($citation->id);
    } else {
        expect(json_encode($events->map->toArray()->all()))->not->toContain('secret.example', 'Title Ω');
    }
    expect(array_key_exists('citations', $citation->toArray()))->toBe($capture !== CaptureDecision::Skip);
})->with(CaptureDecision::cases());

it('seals evidence in history steps and replay without changing event identity', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $stream = app(SwarmRunner::class)->agent(new CitingAgent)->stream('task');
    $events = collect(iterator_to_array($stream));
    foreach (['swarm_run_histories', 'swarm_run_steps'] as $table) {
        $value = DB::table($table)->where('run_id', $stream->runId)->value('citation_evidence');
        expect($value)->toStartWith('sw0:')->not->toContain('secret.example', 'Title');
    }
    $raw = DB::table('swarm_stream_events')->where('run_id', $stream->runId)->pluck('payload')->implode('');
    expect($raw)->not->toContain('secret.example', 'Title')->toContain('citation-invocation-CitingAgent-1');
    expect(collect(app(StreamEventStore::class)->events($stream->runId))->whereInstanceOf(SwarmCitation::class)->sole()->toArray())
        ->toBe($events->whereInstanceOf(SwarmCitation::class)->sole()->toArray());
});

it('selects durable final evidence before terminal node cleanup', function () {
    Queue::fake();
    $response = (new CitationStaticSwarm)->dispatchDurable('task');
    $manager = app(DurableSwarmManager::class);
    for ($i = 0; $i < 8; $i++) {
        $run = app(DurableRunStore::class)->find($response->runId);
        if ($run['status'] === 'completed') {
            break;
        }
        (new AdvanceDurableSwarm($response->runId, $run['next_step_index']))->handle($manager);
    }
    $history = app(RunHistoryStore::class)->find($response->runId);
    expect($history['status'])->toBe('completed')
        ->and($history['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/1')
        ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $response->runId)->count())->toBe(0);
});

it('fails missing citation migration before any provider invocation', function () {
    DB::statement('ALTER TABLE swarm_run_histories DROP COLUMN citation_evidence');
    expect(fn () => app(SwarmRunner::class)->agent(new CitingAgent)->prompt('task'))->toThrow(SwarmException::class, 'citation_evidence');
    expect(CitingAgent::$calls)->toBe([]);
});

function finishCitationDurable(string $runId): array
{
    $manager = app(DurableSwarmManager::class);
    for ($i = 0; $i < 16; $i++) {
        $run = app(DurableRunStore::class)->find($runId);
        if (in_array($run['status'], ['completed', 'failed'], true)) {
            return app(RunHistoryStore::class)->find($runId);
        }
        foreach (app(DurableRunStore::class)->branchesFor($runId, $run['current_node_id'] ?? null) as $branch) {
            if ($branch['status'] === 'pending') {
                (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
            }
        }
        $run = app(DurableRunStore::class)->find($runId);
        if ($run['status'] !== 'completed') {
            (new AdvanceDurableSwarm($runId, $run['next_step_index']))->handle($manager);
        }
    }
    throw new RuntimeException('Citation fixture did not complete.');
}

it('keeps the current loop occurrence when its terminal step bypasses checkpointing', function (string $mode) {
    Queue::fake();
    $swarm = new CitationLoopSwarm;
    if ($mode === 'durable') {
        $run = $swarm->dispatchDurable('task');
        $history = finishCitationDurable($run->runId);
        expect($history['status'])->toBe('completed')->and($history['citations'][0]['url'])->toBe('https://secret.example/OtherCitingAgent/3')
            ->and($history['steps'][1]['citations'][0]['url'])->toBe('https://secret.example/OtherCitingAgent/1')
            ->and(DB::table('swarm_durable_node_outputs')->where('run_id', $run->runId)->count())->toBe(0);
    } else {
        $response = $mode === 'stream' ? $swarm->stream('task') : $swarm->prompt('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
            $response = $response->streamedResponse;
        }
        expect($response->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/3')
            ->and($response->steps[1]->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/1');
    }
})->with(['prompt', 'stream', 'durable']);

it('does not attach earlier sources to a literal hierarchy finish', function (string $mode) {
    Queue::fake();
    $swarm = new CitationLiteralSwarm;
    if ($mode === 'durable') {
        $history = finishCitationDurable($swarm->dispatchDurable('task')->runId);
        expect($history['citations'])->toBe([])->and($history['citation_status'])->toBe('available');
    } else {
        $response = $mode === 'stream' ? $swarm->stream('task') : $swarm->prompt('task');
        if ($mode === 'stream') {
            iterator_to_array($response);
            $response = $response->streamedResponse;
        }
        expect($response->citations)->toBe([])->and($response->citationEvidence->status)->toBe('available')
            ->and($response->steps[0]->citations)->toHaveCount(1);
    }
})->with(['prompt', 'stream', 'durable']);

it('preserves guarded durable branch evidence in final parallel order', function () {
    Queue::fake();
    $run = (new CitationParallelSwarm)->dispatchDurable('task');
    $history = finishCitationDurable($run->runId);
    expect($history['status'])->toBe('completed')->and(array_column($history['citations'], 'step_index'))->toBe([0, 1])
        ->and(array_column($history['citations'], 'url'))->toBe(['https://secret.example/CitingAgent/1', 'https://secret.example/OtherCitingAgent/1']);
});

it('keeps citation payloads sealed through cold graduation raw replay and snapshots', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $stream = app(SwarmRunner::class)->agent(new CitingAgent)->stream('task');
    $events = collect(iterator_to_array($stream));
    $store = app(StreamEventStore::class);
    $store->record($stream->runId, new SwarmCausalSealBarrier('barrier', $stream->runId, 1710000003), 3600);
    $barrier = (int) DB::table('swarm_stream_events')->where('run_id', $stream->runId)->where('event_type', 'swarm_causal_seal_barrier')->value('id');
    $view = new CausalLogView($store->events($stream->runId));
    $cipher = app(SwarmPersistenceCipher::class);
    $snapshot = $cipher->seal(json_encode($view->snapshot(), JSON_THROW_ON_ERROR));
    $cold = app(DatabaseColdArchiveDriver::class);
    expect($cold->graduate($stream->runId, 0, $barrier, $snapshot))->toBeTrue();
    $raw = DB::table('swarm_cold_archives')->where('run_id', $stream->runId)->pluck('payload')->implode('');
    expect($raw)->not->toContain('secret.example', 'Title');
    $cold->reclaim($stream->runId, $barrier);
    $replayed = collect(iterator_to_array(app(SwarmHistory::class)->replay($stream->runId)));
    expect($replayed->whereInstanceOf(SwarmCitation::class)->sole()->toArray())->toBe($events->whereInstanceOf(SwarmCitation::class)->sole()->toArray());
});

it('reports unavailable citation evidence without leaking ciphertext after a key change', function () {
    config()->set('swarm.persistence.encrypt_at_rest', true);
    $response = app(SwarmRunner::class)->agent(new CitingAgent)->prompt('task');
    $cipher = new SwarmPersistenceCipher(config(), new Encrypter(random_bytes(32), 'AES-256-CBC'), app('log'));
    $store = new DatabaseRunHistoryStore(DB::connection(), config(), app(SwarmCapture::class), $cipher);
    $history = $store->findForDisplay($response->context->runId);
    expect($history['citation_status'])->toBe('unavailable')->and($history['citation_reasons'])->toBe(['decrypt_failed'])
        ->and($history['citations'])->toBe([])->and($history['steps'][0]['citation_status'])->toBe('unavailable')
        ->and(json_encode($history))->not->toContain('sw0:', 'secret.example');
});

it('honors run-scoped output capture for citation steps and terminal evidence', function (CaptureDecision $decision) {
    app()->instance(CapturePolicy::class, new class($decision) implements CapturePolicy
    {
        public function __construct(private CaptureDecision $decision) {}

        public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return $context !== null ? $this->decision : CaptureDecision::Redact;
        }

        public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }

        public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
        {
            return CaptureDecision::Full;
        }
    });
    $response = app(SwarmRunner::class)->agent(new CitingAgent)->prompt('task');
    $history = app(RunHistoryStore::class)->find($response->context->runId);
    $status = match ($decision) {
        CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted'
    };
    expect($history['citation_status'])->toBe($status)->and($history['steps'][0]['citation_status'])->toBe($status);
    if ($decision === CaptureDecision::Full) {
        expect($history['steps'][0]['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/1');
    } else {
        expect(json_encode($history))->not->toContain('secret.example');
    }
})->with(CaptureDecision::cases());

it('preserves checkpoint evidence without re-invoking a completed non-final agent', function () {
    config()->set('swarm.memory.replay.mode', 'frozen_view');
    $context = RunContext::from('task', 'citation-checkpoint');
    $first = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context);
    iterator_to_array($first);
    $checkpoint = app(StreamStepCheckpointStore::class)->find($context->runId, 0);
    expect($checkpoint)->not->toBeNull()->and($checkpoint->citationEvidence->items[0]->url)->toBe('https://secret.example/CitingAgent/1');
    $second = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context);
    iterator_to_array($second);
    expect(CitingAgent::$calls['CitingAgent'])->toBe(1)->and(CitingAgent::$calls['OtherCitingAgent'])->toBe(2)
        ->and($second->streamedResponse->steps[0]->citations[0]->url)->toBe('https://secret.example/CitingAgent/1');
});

it('preserves generated hierarchy evidence across sync stream and queued execution', function (string $mode) {
    $plan = (new CitationParallelPlanSwarm)->plan();
    FakeHierarchicalCoordinator::fake([$plan]);
    $swarm = new CitationGeneratedSwarm;
    if ($mode === 'stream') {
        $stream = $swarm->stream('task');
        iterator_to_array($stream);
        $response = $stream->streamedResponse;
    } elseif ($mode === 'queue') {
        $response = app(SwarmRunner::class)->runQueued($swarm, 'task');
    } else {
        $response = $swarm->prompt('task');
    }
    expect($response->citations[0]->url)->toBe('https://secret.example/CitingAgent/1')
        ->and($response->steps[2]->citations[0]->url)->toBe('https://secret.example/OtherCitingAgent/1')
        ->and($response->steps[0]->citationEvidence->status)->toBe('available');
})->with(['prompt', 'stream', 'queue']);

it('retains pre-fanout and branch sources across coordinated queue recovery', function (string $selection) {
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    Queue::fake();
    $plan = (new CitationParallelPlanSwarm)->plan();
    $plan['start_at'] = 'before';
    $plan['nodes']['before'] = ['type' => 'worker', 'agent' => CitingAgent::class, 'prompt' => 'before', 'next' => 'parallel'];
    $plan['nodes']['finish']['output_from'] = $selection;
    FakeHierarchicalCoordinator::fake([$plan]);
    $context = RunContext::from('task', 'queued-citation');
    $swarm = new CitationGeneratedSwarm;
    app(SwarmRunner::class)->runQueued($swarm, $context);
    $manager = app(DurableSwarmManager::class);
    foreach (app(DurableRunStore::class)->branchesFor($context->runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($context->runId, $branch['branch_id']))->handle($manager);
    }
    $response = app(SwarmRunner::class)->resumeQueuedHierarchicalAfterJoin($context->runId);
    expect($response)->not->toBeNull()->and($response->citations[0]->url)->toBe('https://secret.example/CitingAgent/'.($selection === 'before' ? '1' : '2'));
    $history = app(RunHistoryStore::class)->find($context->runId);
    expect($history['steps'][1]['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/1')
        ->and($history['citations'][0]['node_id'])->toBe($selection);
})->with(['before', 'first']);

it('broadcasts captured citation payloads in order and replay stays lazy', function (CaptureDecision $decision) {
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision));
    Event::fake([AnonymousEvent::class]);
    $response = app(SwarmRunner::class)->agent(new CitingAgent)->broadcast('task', new Channel('test-citations'));
    $broadcasts = Event::dispatched(AnonymousEvent::class)->map(fn ($event) => $event[0]->broadcastWith());
    $citation = $broadcasts->firstWhere('type', 'swarm_citation');
    expect($citation)->not->toBeNull()->and($citation['id'])->toBe('citation-invocation-CitingAgent-1');
    expect($broadcasts->pluck('type')->all())->toBe(['swarm_stream_start', 'swarm_step_start', 'swarm_text_delta', 'swarm_citation', 'swarm_step_end', 'swarm_stream_end']);
    iterator_to_array($response);
    expect(CitingAgent::$calls['CitingAgent'])->toBe(1);
    if ($decision !== CaptureDecision::Full) {
        expect(json_encode($broadcasts))->not->toContain('secret.example');
    }
})->with(CaptureDecision::cases());

it('commits evidence with the selected durable occurrence across a checkpoint crash', function (bool $streaming) {
    Queue::fake();
    config()->set('swarm.durable.streaming_enabled', $streaming);
    $runId = (new CitationDurableStreamSwarm)->dispatchDurable('task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $manager->beforeStepCheckpointForTesting(fn () => throw new RuntimeException('citation checkpoint crash'));
    expect(fn () => (new AdvanceDurableSwarm($runId, 1))->handle($manager))->toThrow(RuntimeException::class, 'citation checkpoint crash');
    expect(DB::table('swarm_durable_node_outputs')->where('run_id', $runId)->count())->toBe(0);
    $manager->beforeStepCheckpointForTesting(null);
    $this->freezeTime();
    DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['leased_until' => now()->subSeconds(5), 'updated_at' => now()->subSeconds(301)]);
    Artisan::call('swarm:recover');
    $history = finishCitationDurable($runId);
    expect($history['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/2')
        ->and($history['citations'][0]['invocation_id'])->toBe('invocation-CitingAgent-2')
        ->and($history['steps'][0]['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/2');
    if ($streaming) {
        $log = app(CausalLogStore::class);
        $clean = collect(CausalLogView::forRun($log, $runId)->fold());
        $citations = $clean->whereInstanceOf(SwarmCitation::class);
        expect($citations->flatMap(fn ($event) => array_map(fn ($source) => $source->url, $event->citationEvidence->items))->all())
            ->not->toContain('https://secret.example/CitingAgent/1')->toContain('https://secret.example/CitingAgent/2');
        $all = collect(CausalLogView::forRun($log, $runId)->fold(supersession: ViewSupersession::Everything));
        expect($all->filter(fn ($e) => $e instanceof VoidedEvent && $e->event instanceof SwarmCitation))->not->toBeEmpty();
    }
})->with([false, true]);

it('retains an unusable citation checkpoint as unavailable without repeating usable output', function () {
    config()->set('swarm.memory.replay.mode', 'frozen_view');
    $context = RunContext::from('task', 'bad-citation-key');
    iterator_to_array(app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context));
    $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
    DB::table('swarm_stream_step_checkpoints')->where('run_id', $context->runId)->where('step_index', 0)
        ->update(['citation_evidence' => 'sw0:'.$foreign->encryptString('private source')]);
    $stream = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context);
    iterator_to_array($stream);
    expect(CitingAgent::$calls['CitingAgent'])->toBe(1)
        ->and($stream->streamedResponse->steps[0]->citationEvidence->status)->toBe('unavailable')
        ->and($stream->streamedResponse->steps[0]->citationEvidence->reasons)->toBe(['decrypt_failed']);
});

it('checks the active checkpoint schema before invoking streamed agents', function () {
    Schema::table('swarm_stream_step_checkpoints', fn ($table) => $table->dropColumn('citation_evidence'));
    expect(fn () => iterator_to_array(app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream('task')))
        ->toThrow(SwarmException::class, 'swarm_stream_step_checkpoints.citation_evidence');
    expect(CitingAgent::$calls)->toBe([]);
});

it('retains cache history capture semantics and legacy unknown evidence', function (CaptureDecision $decision) {
    config()->set('swarm.persistence.driver', 'cache');
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision));
    $response = app(SwarmRunner::class)->agent(new CitingAgent)->prompt('task');
    $history = app(RunHistoryStore::class)->find($response->context->runId);
    expect($history['citation_status'])->toBe(match ($decision) {
        CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted'
    });
    if ($decision !== CaptureDecision::Full) {
        expect(json_encode($history))->not->toContain('secret.example');
    }
    expect($response->citations)->toHaveCount(1);
})->with(CaptureDecision::cases());

it('rejects stale branch evidence without changing committed output or source data', function () {
    Queue::fake();
    $runId = (new CitationParallelSwarm)->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    $store = app(DurableRunStore::class);
    $branch = $store->branchesFor($runId)[0];
    $token = $store->acquireBranchLease($runId, $branch['branch_id'], 120);
    $evidence = app(NativeCitationEvidence::class)->response((new CitingAgent)->prompt('task'), $runId, 0, CitingAgent::class);
    expect(fn () => $store->markBranchCompletedWithCitations($runId, $branch['branch_id'], 'stale-token', 'stale', [], 0, $evidence))
        ->toThrow(LostDurableLeaseException::class);
    $row = DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branch['branch_id'])->first();
    expect($row->output)->toBeNull()->and($row->citation_evidence)->toBeNull();
    $store->markBranchCompletedWithCitations($runId, $branch['branch_id'], $token, 'committed', [], 1, $evidence);
    expect($store->branchesFor($runId)[0]['citation_evidence'])->toBe($evidence->toArray());
});

it('supports legacy checkpoint implementations with explicit unknown source availability', function () {
    $legacy = new class implements StreamStepCheckpointStore
    {
        public array $rows = [];

        public function record(string $runId, int $stepIndex, string $output, array $usage): void
        {
            $this->rows[$runId][$stepIndex] = new StreamStepCheckpoint($runId, $stepIndex, $output, $usage);
        }

        public function find(string $runId, int $stepIndex): ?StreamStepCheckpoint
        {
            return $this->rows[$runId][$stepIndex] ?? null;
        }
    };
    app()->instance(StreamStepCheckpointStore::class, $legacy);
    config()->set('swarm.memory.replay.mode', 'frozen_view');
    $context = RunContext::from('task', 'legacy-checkpoint');
    iterator_to_array(app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context));
    $stream = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream($context);
    iterator_to_array($stream);
    expect(CitingAgent::$calls['CitingAgent'])->toBe(1)->and($stream->streamedResponse->steps[0]->citationEvidence->status)->toBe('unknown');
});

it('keeps child-run sources separate from the parent final response', function () {
    Queue::fake();
    $runId = (new CitationParentSwarm)->dispatchDurable('parent')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $childId = $manager->inspect($runId)->children[0]['child_run_id'];
    $child = finishCitationDurable($childId);
    $parent = finishCitationDurable($runId);
    expect($child['citations'])->toHaveCount(1)->and($child['citations'][0]['run_id'])->toBe($childId)
        ->and($parent['citations'])->toHaveCount(1)->and($parent['citations'][0]['run_id'])->toBe($runId)
        ->and($parent['citations'][0]['agent_class'])->toBe(OtherCitingAgent::class);
});

it('prunes retained citation data with its owning history and checkpoints', function () {
    $stream = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream('task');
    iterator_to_array($stream);
    expect(DB::table('swarm_stream_step_checkpoints')->where('run_id', $stream->runId)->count())->toBe(1);
    foreach (['swarm_run_histories', 'swarm_stream_events'] as $table) {
        DB::table($table)->where('run_id', $stream->runId)->update(['expires_at' => now()->subDay()]);
    }
    Artisan::call('swarm:prune');
    foreach (['swarm_run_histories', 'swarm_run_steps', 'swarm_stream_events', 'swarm_stream_step_checkpoints'] as $table) {
        expect(DB::table($table)->where('run_id', $stream->runId)->count())->toBe(0);
    }
});

it('checks citation migration readiness through the existing health command', function (string $table, bool $durable, string $component) {
    Schema::table($table, fn ($blueprint) => $blueprint->dropColumn('citation_evidence'));
    expect(Artisan::call('swarm:health', ['--json' => true, '--durable' => $durable]))->toBe(1);
    $checks = collect(json_decode(Artisan::output(), true)['checks']);
    $check = $checks->firstWhere('component', $component);
    expect($check['status'])->toBe('failed')->and($check['details'])->toContain($table.'.citation_evidence', 'Run migrations');
})->with([
    ['swarm_run_histories', false, 'History'],
    ['swarm_run_steps', false, 'History'],
    ['swarm_durable_branches', true, 'Durable runtime'],
    ['swarm_durable_node_outputs', true, 'Durable runtime'],
    ['swarm_stream_step_checkpoints', false, 'Stream checkpoints'],
]);

it('protects raw checkpoint and durable evidence under every capture and sealing policy', function (CaptureDecision $decision, bool $encrypted) {
    config()->set('swarm.persistence.encrypt_at_rest', $encrypted);
    config()->set('swarm.memory.replay.mode', 'frozen_view');
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision, activeContext: CaptureDecision::Full));
    $stream = app(SwarmRunner::class)->sequential([new CitingAgent, new OtherCitingAgent])->stream('task');
    iterator_to_array($stream);
    $raw = [DB::table('swarm_stream_step_checkpoints')->where('run_id', $stream->runId)->value('citation_evidence')];
    Queue::fake();
    $manager = app(DurableSwarmManager::class);
    $nodeRun = (new CitationStaticSwarm)->dispatchDurable('task')->runId;
    for ($attempt = 0; $attempt < 4 && ! DB::table('swarm_durable_node_outputs')->where('run_id', $nodeRun)->exists(); $attempt++) {
        $run = app(DurableRunStore::class)->find($nodeRun);
        (new AdvanceDurableSwarm($nodeRun, $run['next_step_index']))->handle($manager);
    }
    $raw[] = DB::table('swarm_durable_node_outputs')->where('run_id', $nodeRun)->value('citation_evidence');
    $branchRun = (new CitationParallelSwarm)->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($branchRun, 0))->handle($manager);
    $branch = app(DurableRunStore::class)->branchesFor($branchRun)[0];
    (new AdvanceDurableBranch($branchRun, $branch['branch_id']))->handle($manager);
    $raw[] = DB::table('swarm_durable_branches')->where('run_id', $branchRun)->where('branch_id', $branch['branch_id'])->value('citation_evidence');
    $status = match ($decision) {
        CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted',
    };
    foreach ($raw as $value) {
        expect($value)->toBeString()->not->toBeEmpty();
        if ($encrypted) {
            expect($value)->toStartWith('sw0:');
        }
        if ($encrypted || $decision !== CaptureDecision::Full) {
            expect($value)->not->toContain('secret.example', 'Title Ω');
        } else {
            expect($value)->toContain('secret.example');
        }
        $evidence = app(CitationEvidenceCodec::class)->decode($value);
        expect($evidence->status)->toBe($status);
        if ($decision === CaptureDecision::Full) {
            expect($evidence->items)->toHaveCount(1)->and($evidence->items[0]->startIndex)->toBe(1)
                ->and($evidence->items[0]->url)->toContain('secret.example/CitingAgent/');
        } else {
            expect($evidence->items)->toBe([]);
        }
    }
})->with(CaptureDecision::cases())->with([false, true]);

it('protects newly written legacy inline history evidence under every capture and sealing policy', function (CaptureDecision $decision, bool $encrypted) {
    Schema::drop('swarm_run_steps');
    config()->set('swarm.persistence.encrypt_at_rest', $encrypted);
    app()->instance(CapturePolicy::class, new SkippingAuditCapturePolicy(outputs: $decision));
    $context = RunContext::from('task');
    $store = app(RunHistoryStore::class);
    $store->start($context->runId, 'Fixture', 'sequential', $context, [], 3600);
    $evidence = app(NativeCitationEvidence::class)->response((new CitingAgent)->prompt('task'), $context->runId, 0, CitingAgent::class);
    $store->recordStepWithContext($context->runId, new SwarmStep('Agent', 'in', 'out', citationEvidence: $evidence), 3600, null, null, $context);
    $value = DB::table('swarm_run_histories')->where('run_id', $context->runId)->value('steps');
    expect($value)->toBeString()->not->toBeEmpty();
    $stored = json_decode($value, true)[0];
    expect($stored)->toHaveKey('citation_evidence')->not->toHaveKey('citations');
    if ($encrypted) {
        expect($stored['citation_evidence'])->toStartWith('sw0:');
    }
    if ($encrypted || $decision !== CaptureDecision::Full) {
        expect($value)->not->toContain('secret.example', 'Title Ω');
    } else {
        expect($value)->toContain('secret.example');
    }
    $step = app(RunHistoryStore::class)->find($context->runId)['steps'][0];
    expect($step['citation_status'])->toBe(match ($decision) {
        CaptureDecision::Full => 'available', CaptureDecision::Redact => 'redacted', CaptureDecision::Skip => 'omitted',
    });
    if ($decision === CaptureDecision::Full) {
        expect($step['citations'][0]['url'])->toBe('https://secret.example/CitingAgent/1');
    } else {
        expect(json_encode($step))->not->toContain('secret.example', 'Title Ω');
    }
})->with(CaptureDecision::cases())->with([false, true]);
