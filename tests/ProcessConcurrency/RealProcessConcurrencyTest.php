<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStepEnd;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Support\NativeAgentToolReference;
use BuiltByBerry\LaravelSwarm\Support\NativeInputRecipient;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchOne;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\SerializationBoundaryParallelBranchTwo;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\UnresolvableParallelAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\NativeSettingsSerializationParallelSwarm;
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

test('parallel swarm crosses the real process concurrency driver without agent instance state', function () {
    $response = SerializationBoundaryParallelSwarm::make()->run('shared-task');

    expect($response->steps)->toHaveCount(2)
        ->and((string) $response)->toContain('serialization-boundary:shared-task');
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
    putenv('SWARM_ENCRYPT_AT_REST=true');
    putenv('TESTBENCH_WORKING_PATH='.$testbenchWorkingPath);
    $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $key;
    $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
    $_ENV['SWARM_NATIVE_INPUTS_ENABLED'] = $_SERVER['SWARM_NATIVE_INPUTS_ENABLED'] = 'true';
    $_ENV['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'] = $_SERVER['SWARM_NATIVE_AGENT_SETTINGS_ENABLED'] = 'true';
    $_ENV['SWARM_NATIVE_INPUTS_DISK'] = $_SERVER['SWARM_NATIVE_INPUTS_DISK'] = 'local';
    $_ENV['SWARM_PERSISTENCE_DRIVER'] = $_SERVER['SWARM_PERSISTENCE_DRIVER'] = 'database';
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
    config()->set('swarm.persistence.encrypt_at_rest', true);

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

        expect($parallel->steps)->toHaveCount(2)
            ->and((string) $parallel)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $static)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $generated)->toContain('serialization-boundary:process-task:process-document')
            ->and((string) $settings)->toContain('native-settings:settings-task:history-a:tenant-a:openai:gpt-process:19')
            ->and((string) $settings)->toContain('native-settings:settings-task:history-b:tenant-b:anthropic:claude-process:23');
    } finally {
        SerializableClosure::setSecretKey(null);
        Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$context->runId);
        if (isset($staticContext)) {
            Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$staticContext->runId);
        }
        if (isset($generatedContext)) {
            Storage::disk('local')->deleteDirectory('swarm/native-inputs/'.$generatedContext->runId);
        }
        @unlink($database);
        putenv('APP_KEY');
        putenv('DB_CONNECTION');
        putenv('DB_DATABASE');
        putenv('SWARM_NATIVE_INPUTS_ENABLED');
        putenv('SWARM_NATIVE_AGENT_SETTINGS_ENABLED');
        putenv('SWARM_NATIVE_INPUTS_DISK');
        putenv('SWARM_PERSISTENCE_DRIVER');
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
        expect($step->metadata['parent_parallel_node_id'])->toBe('parallel_node');
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
