<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\InvokeSwarm;
use BuiltByBerry\LaravelSwarm\Jobs\ResumeQueuedHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeHierarchicalFullSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalStreamSequentialParallelSwarm;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.default', 'null');
    config()->set('queue.connections.adoption-usage', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'adoption-usage');
    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $service) {
        app()->forgetInstance($service);
    }
    FakeResearcher::fake([new AgentResponse('research-id', 'research-out', new TextUsage(10, 3, 4, 0, 1), new Meta('fake', 'test'))]);
    FakeWriter::fake([new AgentResponse('writer-id', 'writer-out', new TextUsage(20, 5, null, 0, 2), new Meta('fake', 'test'))]);
    FakeEditor::fake([new AgentResponse('editor-id', 'editor-out', new TextUsage(0, 0, 0, 0, 0), new Meta('fake', 'test'))]);
});

it('preserves unknown usage through executed workflows and stored raw steps', function (string $mode) {
    if ($mode === 'queue') {
        $context = RunContext::from('usage-task');
        (new InvokeSwarm(FakeSequentialSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
        $history = app(RunHistoryStore::class)->find($context->runId);
    } else {
        $swarm = match ($mode) {
            'parallel' => FakeParallelSwarm::make(),
            'static-prompt', 'static-stream' => FakeStaticHierarchicalStreamSequentialParallelSwarm::make(),
            default => FakeSequentialSwarm::make(),
        };
        if (in_array($mode, ['stream', 'static-stream'], true)) {
            $stream = $swarm->stream('usage-task');
            iterator_to_array($stream);
            $response = $stream->streamedResponse;
        } else {
            $response = $swarm->prompt('usage-task');
        }
        $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);
    }
    expect($history['status'])->toBe('completed')
        ->and($history['usage'])->toBe([
            'input_tokens' => 30, 'output_tokens' => 8, 'cache_read_input_tokens' => null, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 3,
        ])
        ->and($history['steps'][0]['metadata']['usage']['cache_read_input_tokens'])->toBe(4)
        ->and($history['steps'][1]['metadata']['usage']['cache_read_input_tokens'])->toBeNull();
})->with(['prompt', 'stream', 'parallel', 'queue', 'static-prompt', 'static-stream']);

it('joins completed durable branches with legacy or missing reports without repeating agents', function (string $kind) {
    $runId = FakeParallelSwarm::make()->dispatchDurable('usage-task')->runId;
    $manager = app(DurableSwarmManager::class);
    (new AdvanceDurableSwarm($runId, 0))->handle($manager);
    $store = app(DurableRunStore::class);
    $branches = $store->branchesFor($runId, 'parallel');
    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
    }
    $legacyOrEmpty = $kind === 'legacy' ? ['prompt_tokens' => 11, 'completion_tokens' => 2, 'cache_read_input_tokens' => 7] : [];
    DB::table('swarm_durable_branches')->where('run_id', $runId)->where('branch_id', $branches[0]['branch_id'])->update(['usage' => json_encode($legacyOrEmpty, JSON_THROW_ON_ERROR)]);
    $before = $store->branchesFor($runId, 'parallel');
    (new AdvanceDurableSwarm($runId, 3))->handle($manager);
    $history = app(RunHistoryStore::class)->find($runId);
    expect($history['status'])->toBe('completed')
        ->and($history['output'])->toBe("research-out\n\nwriter-out\n\neditor-out")
        ->and($history['usage'])->toBe(array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null))
        ->and(array_column($store->branchesFor($runId, 'parallel'), 'usage'))->toBe(array_column($before, 'usage'));
    FakeResearcher::assertPromptedTimes(1);
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
})->with(['legacy', 'empty']);

function usageHierarchyPlan(bool $workers): array
{
    return [
        'start_at' => $workers ? 'parallel' : 'finish',
        'nodes' => $workers ? [
            'parallel' => ['type' => 'parallel', 'branches' => ['writer', 'editor'], 'next' => 'finish'],
            'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write'],
            'editor' => ['type' => 'worker', 'agent' => FakeEditor::class, 'prompt' => 'edit'],
            'finish' => ['type' => 'finish', 'output_from' => 'editor'],
        ] : ['finish' => ['type' => 'finish', 'output' => 'coordinator-only']],
    ];
}

it('keeps an empty hierarchy walk neutral and aggregates actual hierarchy workers', function (string $mode, bool $workers) {
    $nativeUsage = new TextUsage(10, 3, 4, 0, 1);
    FakeHierarchicalCoordinator::fake([new StructuredAgentResponse('coordinator-id', usageHierarchyPlan($workers), json_encode(usageHierarchyPlan($workers), JSON_THROW_ON_ERROR), $nativeUsage, new Meta('fake', 'test'))]);
    $swarm = FakeHierarchicalFullSwarm::make();
    if (in_array($mode, ['stream', 'static-stream'], true)) {
        $stream = $swarm->stream('hierarchy usage');
        iterator_to_array($stream);
        $response = $stream->streamedResponse;
    } else {
        $response = $swarm->prompt('hierarchy usage');
    }
    $history = app(RunHistoryStore::class)->find($response->metadata['run_id']);
    expect($history['status'])->toBe('completed')
        ->and($history['output'])->toBe($workers ? 'editor-out' : 'coordinator-only')
        ->and($history['usage'])->toBe($workers ? [
            'input_tokens' => 30, 'output_tokens' => 8, 'cache_read_input_tokens' => null, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 3,
        ] : $nativeUsage->toArray());
    FakeHierarchicalCoordinator::assertPromptedTimes(1);
    FakeResearcher::assertNeverPrompted();
    FakeWriter::assertPromptedTimes($workers ? 1 : 0);
    FakeEditor::assertPromptedTimes($workers ? 1 : 0);
})->with([['prompt', false], ['stream', false], ['prompt', true], ['stream', true]]);

it('retains conservative accounting through a persisted coordinated queue join', function (string $kind) {
    config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    app()->forgetInstance(QueuedHierarchicalCoordinator::class);
    FakeHierarchicalCoordinator::fake([new StructuredAgentResponse('coordinator-id', usageHierarchyPlan(true), json_encode(usageHierarchyPlan(true), JSON_THROW_ON_ERROR), new TextUsage(10, 3, 4, 0, 1), new Meta('fake', 'test'))]);
    $context = RunContext::from('coordinated usage');
    (new InvokeSwarm(FakeHierarchicalFullSwarm::class, $context->toQueuePayload()))->handle(app(SwarmRunner::class));
    $store = app(DurableRunStore::class);
    $branches = $store->branchesFor($context->runId, 'parallel');
    expect($branches)->toHaveCount(2);
    foreach ($branches as $branch) {
        (new AdvanceDurableBranch($context->runId, $branch['branch_id']))->handle(app(DurableSwarmManager::class));
    }
    if ($kind !== 'native') {
        DB::table('swarm_durable_branches')->where('run_id', $context->runId)->where('branch_id', $branches[0]['branch_id'])->update([
            'usage' => json_encode($kind === 'empty' ? [] : ['prompt_tokens' => 20, 'completion_tokens' => 5], JSON_THROW_ON_ERROR),
        ]);
    }
    $before = array_column($store->branchesFor($context->runId, 'parallel'), 'usage');
    (new ResumeQueuedHierarchicalSwarm($context->runId))->handle(app(QueuedHierarchicalCoordinator::class));
    $history = app(RunHistoryStore::class)->find($context->runId);
    expect($history['status'])->toBe('completed')
        ->and($history['output'])->toBe('editor-out')
        ->and($history['usage'])->toBe($kind === 'native' ? [
            'input_tokens' => 30, 'output_tokens' => 8, 'cache_read_input_tokens' => null, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 3,
        ] : array_fill_keys(['input_tokens', 'output_tokens', 'prompt_tokens', 'completion_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'], null))
        ->and(array_column($store->branchesFor($context->runId, 'parallel'), 'usage'))->toBe($before);
    FakeHierarchicalCoordinator::assertPromptedTimes(1);
    FakeWriter::assertPromptedTimes(1);
    FakeEditor::assertPromptedTimes(1);
})->with(['native', 'legacy', 'empty']);
