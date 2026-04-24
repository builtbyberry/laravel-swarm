<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Responses\SwarmResponse;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'cache');

    FakeResearcher::fake(['research-out', 'parallel-a']);
    FakeWriter::fake(['writer-out', 'parallel-b']);
    FakeEditor::fake(['editor-out', 'parallel-c']);
});

test('swarm history can find and list persisted runs', function () {
    $sequential = FakeSequentialSwarm::make()->run('first-task');
    $parallel = FakeParallelSwarm::make()->run('second-task');

    $history = app(SwarmHistory::class);

    expect($history->find($sequential->metadata['run_id']))
        ->toMatchArray([
            'run_id' => $sequential->metadata['run_id'],
            'swarm_class' => FakeSequentialSwarm::class,
            'status' => 'completed',
        ])
        ->toHaveKey('started_at')
        ->toHaveKey('finished_at')
        ->toHaveKey('updated_at');

    expect($history->latest())
        ->toHaveCount(2)
        ->and($history->latest()[0]['run_id'])->toBe($parallel->metadata['run_id']);
});

test('swarm history query filters by swarm class and status', function () {
    $sequential = FakeSequentialSwarm::make()->run('filter-task');
    FakeParallelSwarm::make()->run('other-task');

    $history = app(SwarmHistory::class);

    expect($history->forSwarm(FakeSequentialSwarm::class)->get())
        ->toHaveCount(1)
        ->and($history->forSwarm(FakeSequentialSwarm::class)->get()[0]['run_id'])->toBe($sequential->metadata['run_id']);

    expect($history->withStatus('completed')->get())->toHaveCount(2);
    expect($history->forSwarm(FakeSequentialSwarm::class)->withStatus('completed')->get())->toHaveCount(1);
});

test('cache-backed history queries ignore stale indexed runs', function () {
    $response = FakeSequentialSwarm::make()->run('stale-task');
    $runId = $response->metadata['run_id'];

    cache()->forget(config('swarm.history.prefix').$runId);

    expect(app(SwarmHistory::class)->latest())->toBe([]);
});

test('swarm status command shows recent runs and a specific run', function () {
    $response = FakeSequentialSwarm::make()->run('status-task');

    Artisan::call('swarm:status');
    $recentOutput = Artisan::output();

    expect($recentOutput)->toContain($response->metadata['run_id']);
    expect($recentOutput)->toContain('FakeSequentialSwarm');

    Artisan::call('swarm:status', ['--run-id' => $response->metadata['run_id']]);
    $singleOutput = Artisan::output();

    expect($singleOutput)->toContain($response->metadata['run_id']);
    expect($singleOutput)->toContain('completed');
});

test('swarm history command filters matching runs', function () {
    FakeSequentialSwarm::make()->run('history-task');
    FakeParallelSwarm::make()->run('history-task');

    Artisan::call('swarm:history', ['--swarm' => FakeSequentialSwarm::class, '--status' => 'completed', '--limit' => 5]);
    $output = Artisan::output();

    expect($output)->toContain('FakeSequentialSwarm');
    expect($output)->not->toContain('FakeParallelSwarm');
});

test('assert persisted works for runtime history assertions', function () {
    $response = FakeSequentialSwarm::make()->run('persisted-task');

    FakeSequentialSwarm::assertPersisted();
    FakeSequentialSwarm::assertPersisted($response->metadata['run_id'], 'completed');
    FakeSequentialSwarm::assertPersisted(fn (array $run): bool => $run['run_id'] === $response->metadata['run_id']);
});

test('assert persisted matches structured task context subsets', function () {
    FakeSequentialSwarm::make()->run([
        'ticket_id' => 'TKT-1234',
        'customer_tier' => 'enterprise',
        'issue' => 'Need help with a billing mismatch.',
    ]);

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(['ticket_id' => 'TKT-1234']);
        FakeSequentialSwarm::assertPersisted(['customer_tier' => 'enterprise']);
    })->not->toThrow(AssertionFailedError::class);
});

test('assert persisted matches reserved-key task arrays in persisted context data', function () {
    FakeSequentialSwarm::make()->run([
        'input' => 'Draft outline about Laravel queues',
        'draft_id' => 42,
        'audience' => 'intermediate developers',
    ]);

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(fn (array $run): bool => ($run['context']['data']['draft_id'] ?? null) === 42
            && isset($run['context']['data']['audience']));
    })->not->toThrow(AssertionFailedError::class);
});

test('assert persisted uses explicit input data and metadata matching rules', function () {
    FakeSequentialSwarm::make()->run(RunContext::from([
        'input' => 'Draft outline',
        'data' => ['draft_id' => 42],
        'metadata' => ['campaign' => 'content-calendar'],
    ]));

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(['input' => 'Draft outline']);
        FakeSequentialSwarm::assertPersisted(['draft_id' => 42]);
        FakeSequentialSwarm::assertPersisted(['metadata' => ['campaign' => 'content-calendar']]);
    })->not->toThrow(AssertionFailedError::class);

    expect(fn () => FakeSequentialSwarm::assertPersisted(['campaign' => 'content-calendar']))
        ->toThrow(AssertionFailedError::class);
});

test('assert persisted finds exact run ids beyond the latest 100 runs', function () {
    $history = app(RunHistoryStore::class);

    $targetRunId = 'target-run-id';
    $targetContext = RunContext::from('target-task', $targetRunId);

    $history->start($targetRunId, FakeSequentialSwarm::class, 'sequential', $targetContext, ['run_id' => $targetRunId], 60);
    $history->complete($targetRunId, new SwarmResponse(
        output: 'target-output',
        context: $targetContext,
        metadata: ['run_id' => $targetRunId],
    ), 60);

    foreach (range(1, 105) as $index) {
        $runId = 'recent-run-'.$index;
        $context = RunContext::from('recent-task-'.$index, $runId);

        $history->start($runId, FakeSequentialSwarm::class, 'sequential', $context, ['run_id' => $runId], 60);
        $history->complete($runId, new SwarmResponse(
            output: 'recent-output-'.$index,
            context: $context,
            metadata: ['run_id' => $runId],
        ), 60);
    }

    FakeSequentialSwarm::assertPersisted($targetRunId, 'completed');
});

test('assert persisted finds structured array and callable matches beyond the latest 100 cache runs', function () {
    foreach (range(1, 101) as $index) {
        FakeSequentialSwarm::make()->run(['draft_id' => $index]);
    }

    expect(function (): void {
        FakeSequentialSwarm::assertPersisted(['draft_id' => 101]);
        FakeSequentialSwarm::assertPersisted(fn (array $run): bool => ($run['context']['data']['draft_id'] ?? null) === 101);
    })->not->toThrow(AssertionFailedError::class);
});

test('assert persisted fails when the run belongs to a different swarm class', function () {
    $history = app(RunHistoryStore::class);
    $runId = 'parallel-run-id';
    $context = RunContext::from('parallel-task', $runId);

    $history->start($runId, FakeParallelSwarm::class, 'parallel', $context, ['run_id' => $runId], 60);
    $history->complete($runId, new SwarmResponse(
        output: 'parallel-output',
        context: $context,
        metadata: ['run_id' => $runId],
    ), 60);

    expect(fn () => FakeSequentialSwarm::assertPersisted($runId))
        ->toThrow(AssertionFailedError::class);
});

test('assert persisted fails with a clear message when no matching run exists', function () {
    expect(fn () => FakeSequentialSwarm::assertPersisted('missing-run-id'))
        ->toThrow(AssertionFailedError::class);
});

test('assert event fired works for runtime lifecycle assertions', function () {
    $response = FakeSequentialSwarm::make()->run('event-task');

    FakeSequentialSwarm::assertEventFired(SwarmStarted::class);
    FakeSequentialSwarm::assertEventFired(
        SwarmStarted::class,
        fn (SwarmStarted $event): bool => $event->runId === $response->metadata['run_id'] && $event->executionMode === 'run',
    );
});

test('assert event fired recorder resets between tests', function () {
    app(SwarmEventRecorder::class)->resetRecorder();

    expect(fn () => FakeSequentialSwarm::assertEventFired(SwarmStarted::class))
        ->toThrow(AssertionFailedError::class);
});

test('assert event fired fails clearly when the recorder is unavailable', function () {
    app(SwarmEventRecorder::class)->deactivate();

    expect(fn () => FakeSequentialSwarm::assertEventFired(SwarmStarted::class))
        ->toThrow(AssertionFailedError::class, 'Swarm event recording is only available in tests');
});
