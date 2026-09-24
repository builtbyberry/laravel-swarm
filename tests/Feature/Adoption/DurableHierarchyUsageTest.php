<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Adoption\Fixtures\DurableUsageStaticSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalParallelInLoopSwarm;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;

covers(HierarchicalRunner::class);

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.default', 'null');
    config()->set('queue.connections.durable-usage', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-usage');
    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $service) {
        app()->forgetInstance($service);
    }
    FakeWriter::fake([new AgentResponse('writer', 'writer-out', new TextUsage(20, 5, null, 0, 2), new Meta('fake', 'test'))]);
    FakeEditor::fake([new AgentResponse('editor', 'editor-out', new TextUsage(0, 0, 0, 0, 0), new Meta('fake', 'test'))]);
    FakeResearcher::fake([new AgentResponse('after', 'after-out', new TextUsage(7, 2, 2, 0, 0), new Meta('fake', 'test'))]);
});

function durableJoinUsagePlan(bool $nextWorker): array
{
    return ['start_at' => 'parallel', 'nodes' => [
        'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'editor'], 'next' => $nextWorker ? 'after' : 'finish'],
        'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write'],
        'editor' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'edit'],
        ...($nextWorker ? ['after' => ['type' => 'worker', 'agent' => FakeResearcher::class, 'prompt' => 'after', 'next' => 'finish']] : []),
        'finish' => ['type' => 'finish', 'output_from' => $nextWorker ? 'after' : 'editor'],
    ]];
}

function startDurableUsageJoin(string $topology, bool $nextWorker): array
{
    $plan = durableJoinUsagePlan($nextWorker);
    config()->set('tests.durable_usage.plan', $plan);
    FakeHierarchicalCoordinator::fake([new StructuredAgentResponse('coordinator', $plan, json_encode($plan, JSON_THROW_ON_ERROR), new TextUsage(10, 3, 4, 0, 1), new Meta('fake', 'test'))]);
    $swarm = $topology === 'static' ? new DurableUsageStaticSwarm : new FakeHierarchicalFullSwarm;
    $runId = $swarm->dispatchDurable('durable join usage')->runId;
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    (new AdvanceDurableSwarm($runId, (int) $store->find($runId)['next_step_index']))->handle($manager);
    $branches = $store->branchesFor($runId, 'parallel');
    expect($branches)->toHaveCount(2);
    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }
    $run = $store->find($runId);
    expect($run['status'])->toBe('pending')->and($run['current_node_id'])->toBe('parallel');

    return [$runId, (int) $run['next_step_index']];
}

function finishDurableUsageRun(string $runId): void
{
    $store = app(DurableRunStore::class);
    $manager = app(DurableSwarmManager::class);
    for ($i = 0; $i < 50; $i++) {
        $run = $store->find($runId);
        if ($run['status'] === 'completed') {
            return;
        }
        if ($run['status'] === 'waiting') {
            foreach ($store->branchesFor($runId, $run['current_node_id']) as $branch) {
                if ($branch['status'] === 'pending') {
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }
        } else {
            (new AdvanceDurableSwarm($runId, (int) $run['next_step_index']))->handle($manager);
        }
    }
    throw new RuntimeException('Durable usage fixture did not complete.');
}

function expectedDurableJoinUsage(string $topology, bool $nextWorker, string $kind = 'native'): array
{
    return $kind === 'native' ? [
        'input_tokens' => 20 + ($topology === 'generated' ? 10 : 0) + ($nextWorker ? 7 : 0),
        'output_tokens' => 5 + ($topology === 'generated' ? 3 : 0) + ($nextWorker ? 2 : 0),
        'cache_read_input_tokens' => null, 'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 2 + ($topology === 'generated' ? 1 : 0),
    ] : array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null);
}

it('accounts for raw completed hierarchy branches exactly once across durable joins', function (string $topology, bool $nextWorker, string $kind) {
    [$runId, $joinIndex] = startDurableUsageJoin($topology, $nextWorker);
    $store = app(DurableRunStore::class);
    $manager = app(DurableSwarmManager::class);
    $rawSteps = array_column(app(RunHistoryStore::class)->find($runId)['steps'], 'metadata');
    if ($kind !== 'native') {
        DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel:writer')->update([
            'usage' => json_encode($kind === 'legacy' ? ['prompt_tokens' => 11, 'completion_tokens' => 2] : [], JSON_THROW_ON_ERROR),
        ]);
    }
    $rawBranches = array_column($store->branchesFor($runId, 'parallel'), 'usage');
    (new AdvanceDurableSwarm($runId, $joinIndex))->handle($manager);
    $afterJoin = app(ContextStore::class)->find($runId);
    (new AdvanceDurableSwarm($runId, $joinIndex))->handle($manager);
    expect(app(ContextStore::class)->find($runId))->toBe($afterJoin);
    foreach ($store->branchesFor($runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }
    finishDurableUsageRun($runId);
    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['usage'])->toBe(expectedDurableJoinUsage($topology, $nextWorker, $kind))
        ->and($history['output'])->toBe($nextWorker ? 'after-out' : 'editor-out')
        ->and(array_column($store->branchesFor($runId, 'parallel'), 'usage'))->toBe($rawBranches)
        ->and(array_slice(array_column($history['steps'], 'metadata'), 0, count($rawSteps)))->toBe($rawSteps);
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
    FakeResearcher::assertPromptedTimes($nextWorker ? 1 : 0);
    FakeHierarchicalCoordinator::assertPromptedTimes($topology === 'generated' ? 1 : 0);
})->with(['generated', 'static'])->with([false, true])->with(['native', 'legacy', 'empty']);

it('recovers a join checkpoint interruption without double accounting or repeated agents', function (string $topology, string $edge) {
    [$runId, $joinIndex] = startDurableUsageJoin($topology, true);
    $manager = app(DurableSwarmManager::class);
    $store = app(DurableRunStore::class);
    $before = app(ContextStore::class)->find($runId);
    $beforeRun = $store->find($runId);
    $hook = $edge === 'before' ? 'beforeStepCheckpointForTesting' : 'afterStepCheckpointForTesting';
    $manager->$hook(fn () => throw new RuntimeException('interrupted join checkpoint'));
    try {
        expect(fn () => (new AdvanceDurableSwarm($runId, $joinIndex))->handle($manager))->toThrow(RuntimeException::class, 'interrupted join checkpoint');
    } finally {
        $manager->$hook(null);
    }
    $stored = app(ContextStore::class)->find($runId);
    if ($edge === 'before') {
        expect($stored)->toBe($before)
            ->and($store->find($runId)['route_cursor'])->toBe($beforeRun['route_cursor'])
            ->and($store->find($runId)['next_step_index'])->toBe($joinIndex);
        $this->freezeTime();
        DB::table('swarm_durable_runs')->where('run_id', $runId)->update(['leased_until' => now()->subSeconds(5)]);
    } else {
        expect($stored['metadata']['usage'])->toBe(expectedDurableJoinUsage($topology, false))
            ->and($store->find($runId)['current_node_id'])->toBe('after')
            ->and($store->find($runId)['next_step_index'])->toBe($joinIndex + 1);
    }
    (new AdvanceDurableSwarm($runId, $joinIndex))->handle($manager);
    finishDurableUsageRun($runId);
    expect(app(RunHistoryStore::class)->find($runId)['usage'])->toBe(expectedDurableJoinUsage($topology, true));
    FakeHierarchicalCoordinator::assertPromptedTimes($topology === 'generated' ? 1 : 0);
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
    FakeResearcher::assertPromptedTimes(1);
})->with(['generated', 'static'])->with(['before', 'after']);

it('counts each real branch generation inside a retained bounded loop', function () {
    foreach ([FakeEditor::class => 1, FakeResearcher::class => 2, FakeWriter::class => 3, FakeReviewer::class => 4] as $agent => $inputTokens) {
        $agent::fake(fn () => new AgentResponse($agent, 'loop-output', new TextUsage($inputTokens, 1, 1, 0, 0), new Meta('fake', 'test')));
    }
    $runId = FakeStaticHierarchicalParallelInLoopSwarm::make()->dispatchDurable('loop usage')->runId;
    finishDurableUsageRun($runId);
    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['usage'])->toBe(['input_tokens' => 30, 'output_tokens' => 12, 'cache_read_input_tokens' => 12, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0])
        ->and($history['context']['metadata']['parallel_groups'])->toHaveCount(3);
    foreach ([FakeEditor::class, FakeResearcher::class, FakeWriter::class, FakeReviewer::class] as $agent) {
        $agent::assertPromptedTimes(3);
    }
});

it('ignores failed branch reports when joining a partial-success hierarchy', function (string $topology) {
    [$runId, $joinIndex] = startDurableUsageJoin($topology, false);
    // Seed terminal state to isolate completed-only accounting from failure execution.
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', 'parallel:writer')->update([
        'status' => 'failed', 'usage' => json_encode((new TextUsage(999, 999, 999, 999, 999))->toArray(), JSON_THROW_ON_ERROR),
    ]);
    $context = RunContext::fromPayload(app(ContextStore::class)->find($runId));
    $context->mergeMetadata(['durable_parallel_failure_policy' => 'partial_success']);
    app(ContextStore::class)->put($context, 3600);
    (new AdvanceDurableSwarm($runId, $joinIndex))->handle(app(DurableSwarmManager::class));
    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['status'])->toBe('completed')->and($history['output'])->toBe('editor-out')
        ->and($history['usage'])->toBe(($topology === 'generated' ? new TextUsage(10, 3, 4, 0, 1) : new TextUsage(0, 0, 0, 0, 0))->toArray());
})->with(['generated', 'static']);
