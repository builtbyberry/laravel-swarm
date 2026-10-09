<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SnapshotsMemory;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Enums\ReplayMode;
use BuiltByBerry\LaravelSwarm\Exceptions\GuardrailViolation;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Memory\DefaultSwarmMemory;
use BuiltByBerry\LaravelSwarm\Runners\StaticHierarchicalStreamRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmStepRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolReference;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\NativeStepResultProjector;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\MemoryRecallAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\ProcessReplayMemoryWriterA;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RichSerializationBoundaryAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnresolvableParallelAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Guardrails\BlocksStepWhenIndex;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\NativeSettingsSerializationParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ProcessReplayStructuredWriteStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ProcessReplayWriteOrderStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\ProcessReplayWriteStaticHierarchicalSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RichSerializationBoundaryParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryHierarchicalParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\SerializationBoundaryStaticHierarchicalParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Tools\NativeSettingsTool;
use BuiltByBerry\LaravelSwarm\Tests\Support\HierarchicalTestPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Messages\UserMessage;
use Laravel\SerializableClosure\SerializableClosure;

pest()->group('process-concurrency');

/**
 * @return Closure(): void
 */
function configureReplayProcessDatabase(): Closure
{
    $database = sys_get_temp_dir().'/laravel-swarm-replay-process-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($database);
    $key = (string) config('app.key');
    $testbenchWorkingPath = dirname(__DIR__, 2);
    $names = [
        'APP_KEY' => $key,
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'SWARM_PERSISTENCE_DRIVER' => 'database',
        'SWARM_HISTORY_DRIVER' => 'database',
        'SWARM_ENCRYPT_AT_REST' => 'false',
        'SWARM_MEMORY_REPLAY_MODE' => ReplayMode::FrozenView->value,
        'TESTBENCH_WORKING_PATH' => $testbenchWorkingPath,
    ];
    $original = [];

    foreach ($names as $name => $value) {
        $original[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    SerializableClosure::setSecretKey(base64_decode(substr($key, strlen('base64:')), true));

    config()->set('database.connections.testing.database', $database);
    DB::purge('testing');
    DB::setDefaultConnection('testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing', '--force' => true]);
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', false);
    config()->set('swarm.memory.replay_mode', ReplayMode::FrozenView->value);
    config()->set('swarm.streaming.parallel.enabled', true);
    MemoryRecallAgent::reset();

    return static function () use ($database, $original): void {
        SerializableClosure::setSecretKey(null);
        DB::disconnect('testing');
        @unlink($database);

        foreach ($original as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    };
}

function seedReplayProcessRun(string $runId): void
{
    $now = now('UTC');
    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId,
        'swarm_class' => ProcessReplayWriteStaticHierarchicalSwarm::class,
        'topology' => 'static_hierarchical',
        'status' => 'running',
        'context' => json_encode([]),
        'metadata' => json_encode([]),
        'steps' => json_encode([]),
        'output' => null,
        'usage' => json_encode([]),
        'error' => null,
        'artifacts' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    app(SnapshotsMemory::class)->snapshot($runId, 0, []);
    app(SnapshotsMemory::class)->snapshot($runId, 1, []);
}

test('parallel swarm crosses the real process concurrency driver without agent instance state', function () {
    $direct = (new RichSerializationBoundaryAgent)->prompt('shared-task');
    $expected = app(NativeStepResultProjector::class)->fromResponse($direct)->toArray();
    $response = RichSerializationBoundaryParallelSwarm::make()->run('shared-task');

    expect($response->steps)->toHaveCount(2)
        ->and(array_map(static fn ($step) => $step->nativeResult?->invocationId, $response->steps))
        ->toBe(['serialization-boundary-agent', 'serialization-boundary-agent'])
        ->and(array_map(static fn ($step) => [$step->nativeResult?->provider, $step->nativeResult?->model], $response->steps))
        ->toBe([['fake', 'test'], ['fake', 'test']])
        ->and($response->steps[0]->nativeResult?->structured)->toBe(['channel' => 'process', 'typed' => true])
        ->and($response->steps[0]->nativeResult?->reasoning)->toBe('process-reasoning')
        ->and($response->steps[0]->nativeResult?->conversationId)->toBe('process-conversation')
        ->and($response->steps[0]->nativeResult?->generationSteps[0]['structured'])->toBe(['generation' => 'typed'])
        ->and($response->steps[0]->nativeResult?->tools[0]['status'])->toBe('succeeded')
        ->and(array_map(static fn ($step) => $step->nativeResult?->toArray(), $response->steps))
        ->toBe([$expected, $expected]);
});

test('parallel process workers inherit the parent native-result bounds', function () {
    config()->set('swarm.native_results.max_bytes', 256);
    config()->set('swarm.native_results.max_generation_steps', 1);
    config()->set('swarm.native_results.max_tool_statuses', 1);

    $response = RichSerializationBoundaryParallelSwarm::make()->run('bounded-process-task');

    foreach ($response->steps as $step) {
        expect(strlen(json_encode($step->nativeResult, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(256)
            ->and($step->nativeResult?->status)->toBe('partial')
            ->and($step->nativeResult?->reasons)->toContain('limit');
    }
});

test('native attachments cross fresh process workers in every concurrent topology', function () {
    $database = sys_get_temp_dir().'/laravel-swarm-native-process-'.getmypid().'.sqlite';
    touch($database);
    $key = (string) config('app.key');
    $testbenchWorkingPath = dirname(__DIR__, 2);
    $originalTestbenchWorkingPath = getenv('TESTBENCH_WORKING_PATH');

    putenv('APP_KEY='.$key);
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$database);
    putenv('SWARM_NATIVE_INPUTS_ENABLED=true');
    putenv('SWARM_NATIVE_AGENT_SETTINGS_ENABLED=true');
    putenv('SWARM_NATIVE_INPUTS_DISK=local');
    putenv('SWARM_PERSISTENCE_DRIVER=database');
    putenv('SWARM_HISTORY_DRIVER=database');
    putenv('SWARM_ENCRYPT_AT_REST=true');
    putenv('TESTBENCH_WORKING_PATH='.$testbenchWorkingPath);
    $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $key;
    $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
    $_ENV['SWARM_NATIVE_INPUTS_ENABLED'] = $_SERVER['SWARM_NATIVE_INPUTS_ENABLED'] = 'true';
    $_ENV['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'] = $_SERVER['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'] = 'true';
    $_ENV['SWARM_NATIVE_INPUTS_DISK'] = $_SERVER['SWARM_NATIVE_INPUTS_DISK'] = 'local';
    $_ENV['SWARM_PERSISTENCE_DRIVER'] = $_SERVER['SWARM_PERSISTENCE_DRIVER'] = 'database';
    $_ENV['SWARM_HISTORY_DRIVER'] = $_SERVER['SWARM_HISTORY_DRIVER'] = 'database';
    $_ENV['SWARM_ENCRYPT_AT_REST'] = $_SERVER['SWARM_ENCRYPT_AT_REST'] = 'true';
    $_ENV['TESTBENCH_WORKING_PATH'] = $_SERVER['TESTBENCH_WORKING_PATH'] = $testbenchWorkingPath;
    SerializableClosure::setSecretKey(base64_decode(substr($key, strlen('base64:')), true));
    config()->set('database.connections.testing.database', $database);
    DB::purge('testing');
    DB::setDefaultConnection('testing');
    Artisan::call('migrate:fresh', ['--database' => 'testing', '--force' => true]);

    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_agent_settings.enabled', true);
    config()->set('swarm.native_inputs.disk', 'local');
    config()->set('swarm.persistence.driver', 'database');
    config()->set('swarm.history.driver', 'database');
    config()->set('swarm.persistence.encrypt_at_rest', true);
    config()->set('swarm.streaming.parallel.enabled', true);

    $message = new UserMessage('process-task', [new Base64Document(base64_encode('process-document'), 'text/plain')]);
    $context = RunContext::fromTask($message)->withAgentInput($message, [
        NativeInputRecipient::parallel(0, textSource: 'original', attachments: [0]),
        NativeInputRecipient::parallel(1, textSource: 'original', attachments: [0]),
    ]);

    try {
        $parallel = SerializationBoundaryParallelSwarm::make()->run($context);

        $staticContext = RunContext::fromTask($message)->withAgentInput($message, [
            NativeInputRecipient::staticNode('branch_one', textSource: 'original', attachments: [0]),
            NativeInputRecipient::staticNode('branch_two', textSource: 'original', attachments: [0]),
        ]);
        $static = SerializationBoundaryStaticHierarchicalParallelSwarm::make()->run($staticContext);

        FakeHierarchicalCoordinator::fake([
            HierarchicalTestPlan::make('parallel_node', [
                'parallel_node' => [
                    'type' => 'parallel',
                    'branches' => ['writer_node', 'editor_node'],
                    'next' => 'finish_node',
                ],
                'writer_node' => [
                    'type' => 'worker',
                    'agent' => SerializationBoundaryParallelBranchOne::class,
                    'prompt' => 'writer-branch',
                ],
                'editor_node' => [
                    'type' => 'worker',
                    'agent' => SerializationBoundaryParallelBranchTwo::class,
                    'prompt' => 'editor-branch',
                ],
                'finish_node' => [
                    'type' => 'finish',
                    'output_from' => 'editor_node',
                ],
            ]),
        ]);
        $generatedContext = RunContext::fromTask($message)->withAgentInput($message, [
            NativeInputRecipient::generatedNode('writer_node', textSource: 'original', attachments: [0]),
            NativeInputRecipient::generatedNode('editor_node', textSource: 'original', attachments: [0]),
        ]);
        $generated = SerializationBoundaryHierarchicalParallelSwarm::make()->run($generatedContext);

        $settingsContext = RunContext::fromTask('settings-task')->withAgentConfiguration([
            NativeInputRecipient::parallel(0)
                ->withInvocation('openai', 'gpt-process', 19)
                ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'tenant-a'])])
                ->withMessages([new UserMessage('history-a')]),
            NativeInputRecipient::parallel(1)
                ->withInvocation('anthropic', 'claude-process', 23)
                ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'tenant-b'])])
                ->withMessages([new UserMessage('history-b')]),
        ]);
        $settings = NativeSettingsSerializationParallelSwarm::make()->run($settingsContext);

        $liveContext = RunContext::fromTask($message)
            ->withAgentInput($message, [
                NativeInputRecipient::parallel(0, textSource: 'original', attachments: [0]),
                NativeInputRecipient::parallel(1, textSource: 'original', attachments: [0]),
            ])
            ->withAgentConfiguration([
                NativeInputRecipient::parallel(0)
                    ->withInvocation('openai', 'gpt-stream', 29)
                    ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'tenant-stream-a'])])
                    ->withMessages([new UserMessage('stream-history-a')]),
                NativeInputRecipient::parallel(1)
                    ->withInvocation('anthropic', 'claude-stream', 31)
                    ->withTools([new NativeAgentToolReference(NativeSettingsTool::class, ['tenant' => 'tenant-stream-b'])])
                    ->withMessages([new UserMessage('stream-history-b')]),
            ]);
        $liveEvents = iterator_to_array(NativeSettingsSerializationParallelSwarm::make()->stream($liveContext), false);
        $liveDeltas = array_values(array_filter($liveEvents, fn ($event) => $event instanceof SwarmTextDelta));
        $liveDeltasByBranch = collect($liveDeltas)->keyBy(fn (SwarmTextDelta $event): string => $event->branchId ?? '');
        $continued = RunContext::fromPayload($liveContext->toQueuePayload());

        expect($parallel->steps)->toHaveCount(2)
            ->and((string) $parallel)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $static)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $generated)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $settings)->toContain('native-settings:settings-task:history-a:tenant-a:openai:gpt-process:19')
            ->and((string) $settings)->toContain('native-settings:settings-task:history-b:tenant-b:anthropic:claude-process:23')
            ->and($liveDeltas)->toHaveCount(2)
            ->and($liveDeltasByBranch->keys()->sort()->values()->all())->toBe(['parallel:0', 'parallel:1'])
            ->and($liveDeltasByBranch['parallel:0']->delta)->toBe('native-settings:process-task:process-document:stream-history-a:tenant-stream-a:openai:gpt-stream:29')
            ->and($liveDeltasByBranch['parallel:1']->delta)->toBe('native-settings:process-task:process-document:stream-history-b:tenant-stream-b:anthropic:claude-stream:31')
            ->and($liveDeltasByBranch->every(fn (SwarmTextDelta $event): bool => is_string($event->attemptId)
                && $event->attemptId !== ''
                && $event->branchSequence === 1))->toBeTrue()
            ->and($liveDeltasByBranch['parallel:0']->attemptId)->not->toBe($liveDeltasByBranch['parallel:1']->attemptId)
            ->and($continued->nativeInvocation('parallel:0', 'continued')->messages)->toBeEmpty()
            ->and($continued->nativeInvocation('parallel:1', 'continued')->messages)->toBeEmpty()
            ->and($continued->nativeInvocation('parallel:0', 'continued')->tools)->toHaveCount(1)
            ->and($continued->nativeInvocation('parallel:1', 'continued')->tools)->toHaveCount(1);
    } finally {
        SerializableClosure::setSecretKey(null);
        Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$context->runId);
        if (isset($staticContext)) {
            Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$staticContext->runId);
        }
        if (isset($generatedContext)) {
            Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$generatedContext->runId);
        }
        if (isset($liveContext)) {
            Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$liveContext->runId);
        }
        @unlink($database);
        putenv('APP_KEY');
        putenv('DB_CONNECTION');
        putenv('DB_DATABASE');
        putenv('SWARM_NATIVE_INPUTS_ENABLED');
        putenv('SWARM_NATIVE_AGENT_SETTINGS_ENABLED');
        putenv('SWARM_NATIVE_INPUTS_DISK');
        putenv('SWARM_PERSISTENCE_DRIVER');
        putenv('SWARM_HISTORY_DRIVER');
        putenv('SWARM_ENCRYPT_AT_REST');
        putenv($originalTestbenchWorkingPath === false
            ? 'TESTBENCH_WORKING_PATH'
            : 'TESTBENCH_WORKING_PATH='.$originalTestbenchWorkingPath);
        unset(
            $_ENV['APP_KEY'],
            $_SERVER['APP_KEY'],
            $_ENV['DB_CONNECTION'],
            $_SERVER['DB_CONNECTION'],
            $_ENV['DB_DATABASE'],
            $_SERVER['DB_DATABASE'],
            $_ENV['SWARM_NATIVE_INPUTS_ENABLED'],
            $_SERVER['SWARM_NATIVE_INPUTS_ENABLED'],
            $_ENV['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'],
            $_SERVER['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'],
            $_ENV['SWARM_NATIVE_INPUTS_DISK'],
            $_SERVER['SWARM_NATIVE_INPUTS_DISK'],
            $_ENV['SWARM_PERSISTENCE_DRIVER'],
            $_SERVER['SWARM_PERSISTENCE_DRIVER'],
            $_ENV['SWARM_HISTORY_DRIVER'],
            $_SERVER['SWARM_HISTORY_DRIVER'],
            $_ENV['SWARM_ENCRYPT_AT_REST'],
            $_SERVER['SWARM_ENCRYPT_AT_REST'],
            $_ENV['TESTBENCH_WORKING_PATH'],
            $_SERVER['TESTBENCH_WORKING_PATH'],
        );

        if ($originalTestbenchWorkingPath !== false) {
            $_ENV['TESTBENCH_WORKING_PATH'] = $_SERVER['TESTBENCH_WORKING_PATH'] = $originalTestbenchWorkingPath;
        }
    }
});

test('hierarchical swarm executes parallel group and join under the real process concurrency driver', function () {
    FakeHierarchicalCoordinator::fake([
        HierarchicalTestPlan::make('parallel_node', [
            'parallel_node' => [
                'type' => 'parallel',
                'branches' => ['writer_node', 'editor_node'],
                'next' => 'finish_node',
            ],
            'writer_node' => [
                'type' => 'worker',
                'agent' => SerializationBoundaryParallelBranchOne::class,
                'prompt' => 'writer-branch',
            ],
            'editor_node' => [
                'type' => 'worker',
                'agent' => SerializationBoundaryParallelBranchTwo::class,
                'prompt' => 'editor-branch',
            ],
            'finish_node' => [
                'type' => 'finish',
                'output_from' => 'editor_node',
            ],
        ]),
    ]);

    $response = SerializationBoundaryHierarchicalParallelSwarm::make()->run('hierarchical-task');

    expect($response->output)->toContain('serialization-boundary:editor-branch');
    expect($response->metadata['executed_node_ids'])->toBe(['parallel_node', 'writer_node', 'editor_node', 'finish_node']);
    expect($response->metadata['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['writer_node', 'editor_node']],
    ]);

    $parallelSteps = array_values(array_filter(
        $response->steps,
        fn ($step) => ($step->metadata['parent_parallel_node_id'] ?? null) === 'parallel_node'
    ));

    expect($parallelSteps)->toHaveCount(2);

    foreach ($parallelSteps as $step) {
        expect($step->metadata['parent_parallel_node_id'])->toBe('parallel_node')
            ->and($step->nativeResult->invocationId)->toBe('serialization-boundary-agent')
            ->and($step->nativeResult->provider)->toBe('fake')
            ->and($step->nativeResult->model)->toBe('test');
    }
});

test('ad-hoc parallel agents must be container resolvable before process concurrency dispatch', function () {
    expect(fn () => app(SwarmRunner::class)->parallel([
        new UnresolvableParallelAgent('runtime-only'),
    ])->run('shared-task'))
        ->toThrow(SwarmException::class, 'must be container-resolvable because Laravel Concurrency serializes worker callbacks');
});

test('static hierarchical parallel prompt() crosses the real process concurrency driver without agent instance state', function () {
    $response = SerializationBoundaryStaticHierarchicalParallelSwarm::make()->prompt('static-task');

    expect($response->steps)->toHaveCount(2);
    expect((string) $response)->toContain('serialization-boundary:branch-one');

    expect($response->metadata['executed_node_ids'])->toBe(['parallel_node', 'branch_one', 'branch_two', 'finish_node']);
    expect($response->metadata['parallel_groups'])->toBe([
        ['node_id' => 'parallel_node', 'branches' => ['branch_one', 'branch_two']],
    ]);
});

test('static hierarchical parallel stream() crosses the real process concurrency driver in concurrent mode', function () {
    $events = iterator_to_array(
        SerializationBoundaryStaticHierarchicalParallelSwarm::make()->stream('static-stream-task'),
        preserve_keys: false,
    );

    $stepEndEvents = array_values(array_filter($events, fn ($e) => $e instanceof SwarmStepEnd));
    $textDeltaEvents = array_values(array_filter($events, fn ($e) => $e instanceof SwarmTextDelta));

    // Concurrent branches yield SwarmStepEnd (not text deltas) after ConcurrencyManager completes
    expect($stepEndEvents)->toHaveCount(2);
    expect($textDeltaEvents)->toHaveCount(0);

    $agentClasses = array_map(fn ($e) => $e->agentClass, $stepEndEvents);
    expect($agentClasses)->toContain(SerializationBoundaryParallelBranchOne::class);
    expect($agentClasses)->toContain(SerializationBoundaryParallelBranchTwo::class);
});

test('a replayed process branch saves its Remember write before a downstream static node reads it', function () {
    $cleanup = configureReplayProcessDatabase();
    $runId = 'process-replay-write-success';

    try {
        seedReplayProcessRun($runId);

        iterator_to_array(ProcessReplayWriteStaticHierarchicalSwarm::make()->stream(
            RunContext::from('process-replay', $runId),
        ), false);

        expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe('retry-value')
            ->and(MemoryRecallAgent::$seen)->toContain('retry-write: retry-value');
    } finally {
        $cleanup();
    }
});

test('a replayed process branch rejected by the parent guardrail leaves live memory unchanged', function () {
    $cleanup = configureReplayProcessDatabase();
    $runId = 'process-replay-write-guardrail';

    try {
        seedReplayProcessRun($runId);
        config()->set('swarm.guardrails.step', [new BlocksStepWhenIndex(0)]);

        expect(fn () => iterator_to_array(ProcessReplayWriteStaticHierarchicalSwarm::make()->stream(
            RunContext::from('process-replay', $runId),
        ), false))->toThrow(GuardrailViolation::class);

        expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBeNull();
    } finally {
        $cleanup();
    }
});

test('a replayed process branch whose parent-side step recording fails leaves live memory unchanged', function () {
    $cleanup = configureReplayProcessDatabase();
    $runId = 'process-replay-write-recording';

    try {
        seedReplayProcessRun($runId);
        $seenAtRecording = 'not-called';
        $recorder = Mockery::mock(app(SwarmStepRecorder::class))->makePartial();
        $recorder->shouldReceive('completed')
            ->withArgs(fn ($state, int $index, string $agentClass): bool => $agentClass === ProcessReplayMemoryWriterA::class)
            ->once()
            ->andReturnUsing(function () use (&$seenAtRecording, $runId): never {
                $seenAtRecording = app(DefaultSwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write');

                throw new RuntimeException('process-step-recording-failed');
            });
        app()->instance(SwarmStepRecorder::class, $recorder);
        app()->forgetInstance(StaticHierarchicalStreamRunner::class);
        app()->forgetInstance(SwarmRunner::class);

        expect(fn () => iterator_to_array(ProcessReplayWriteStaticHierarchicalSwarm::make()->stream(
            RunContext::from('process-replay', $runId),
        ), false))->toThrow(RuntimeException::class, 'process-step-recording-failed');

        expect($seenAtRecording)->toBeNull()
            ->and(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBeNull();
    } finally {
        app()->forgetInstance(SwarmStepRecorder::class);
        app()->forgetInstance(StaticHierarchicalStreamRunner::class);
        app()->forgetInstance(SwarmRunner::class);
        $cleanup();
    }
});

test('a replayed process branch preserves a nested array through the child-to-parent channel', function () {
    $cleanup = configureReplayProcessDatabase();
    $runId = 'process-replay-write-structured';

    try {
        seedReplayProcessRun($runId);

        iterator_to_array(ProcessReplayStructuredWriteStaticHierarchicalSwarm::make()->stream(
            RunContext::from('process-replay', $runId),
        ), false);

        expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe([
            'nested' => ['answer' => 42],
            'items' => ['one', 'two'],
        ]);
    } finally {
        $cleanup();
    }
});

test('replayed process branches save same-key writes in declaration order', function () {
    $cleanup = configureReplayProcessDatabase();
    $runId = 'process-replay-write-order';

    try {
        seedReplayProcessRun($runId);

        iterator_to_array(ProcessReplayWriteOrderStaticHierarchicalSwarm::make()->stream(
            RunContext::from('process-replay-order', $runId),
        ), false);

        expect(app(SwarmMemory::class)->get(MemoryScope::Run, $runId, 'retry-write'))->toBe('second')
            ->and(MemoryRecallAgent::$seen)->toContain('retry-write: second');
    } finally {
        $cleanup();
    }
});
