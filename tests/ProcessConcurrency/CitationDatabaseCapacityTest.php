<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamStepCheckpointStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Persistence\CitationEvidenceCodec;
use BuiltByBerry\LaravelSwarm\Responses\CitationEvidence;
use BuiltByBerry\LaravelSwarm\Responses\SwarmCitation;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Responses\SwarmStep;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Citations\Fixtures\CitationParallelSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Exercise storage capacity on the existing real-database CI lane.
pest()->group('process-concurrency', 'skip-locked-real-db');

it('round trips permitted large and aggregate citation envelopes on real databases', function (bool $encrypted) {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
        $this->markTestSkipped('Citation column capacity requires the real MySQL/Postgres lane.');
    }
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', $encrypted);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
    Queue::fake();
    $runId = (new CitationParallelSwarm)->dispatchDurable('task')->runId;
    (new AdvanceDurableSwarm($runId, 0))->handle(app(DurableSwarmManager::class));
    $items = [];
    foreach ([0, 1] as $step) {
        for ($i = 0; $i < 120; $i++) {
            $items[] = new SwarmCitation('https://example.com/source/'.$i, str_repeat('title', 120), $runId, $step, 'Agent', 0, 10, invocationId: 'inv-'.$step);
        }
    }
    $evidence = new CitationEvidence($items, CitationEvidence::AVAILABLE);
    $codec = app(CitationEvidenceCodec::class);
    expect(strlen($codec->encode($evidence)))->toBeGreaterThan(65535)
        ->and($codec->decode($codec->encode($evidence))->toArray())->toBe($evidence->toArray());
    $history = app(RunHistoryStore::class);
    $history->recordStep($runId, new SwarmStep('Agent', 'in', 'out', metadata: ['index' => 0], citationEvidence: $evidence), 3600);
    $history->complete($runId, new SwarmResponse('out', context: RunContext::from('task', $runId), citationEvidence: $evidence), 3600);
    app(StreamStepCheckpointStore::class)->recordWithCitations($runId, 0, 'out', [], $evidence);
    $durable = app(DurableRunStore::class);
    $durable->storeHierarchicalNodeOutputWithCitations($runId, 'node', 'out', 3600, $evidence);
    $branch = $durable->branchesFor($runId)[0];
    $token = $durable->acquireBranchLease($runId, $branch['branch_id'], 120);
    $durable->markBranchCompletedWithCitations($runId, $branch['branch_id'], $token, 'out', [], 1, $evidence);
    foreach (['swarm_run_histories', 'swarm_run_steps', 'swarm_stream_step_checkpoints', 'swarm_durable_node_outputs', 'swarm_durable_branches'] as $table) {
        $query = DB::table($table)->where('run_id', $runId);
        if ($table === 'swarm_durable_branches') {
            $query->where('branch_id', $branch['branch_id']);
        }
        $raw = $query->value('citation_evidence');
        expect(strlen($raw))->toBeGreaterThan(65535)
            ->and($codec->decode($raw)->toArray())->toBe($evidence->toArray());
    }
})->with([false, true]);
