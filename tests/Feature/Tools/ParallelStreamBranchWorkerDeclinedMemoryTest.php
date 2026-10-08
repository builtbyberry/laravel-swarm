<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Runners\ParallelStreamBranchWorker;
use BuiltByBerry\LaravelSwarm\Runners\ParallelStreamBranchWorkerSocketHarness;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\DeclinedMemoryStreamAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\DeclinedMemoryLiveParallelSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Support\SkippingMemoryCapturePolicy;

require_once __DIR__.'/../../Fixtures/ParallelStreamBranchWorkerSocketHarness.php';

afterEach(function (): void {
    DeclinedMemoryStreamAgent::$arguments = ['key' => '', 'value' => 'x'];
});

test('parallel live stream branch worker reports a declined memory write as unsuccessful', function (bool $skip, array $arguments, string $message): void {
    if ($skip) {
        app()->instance(MemoryCapturePolicy::class, new SkippingMemoryCapturePolicy(['secret']));
        app()->forgetInstance(MemoryStore::class);
        app()->forgetInstance(SwarmMemory::class);
    }

    DeclinedMemoryStreamAgent::$arguments = $arguments;

    $runId = 'declined-memory-worker-run';
    $context = RunContext::fake(['run_id' => $runId, 'input' => 'remember']);
    ParallelStreamBranchWorkerSocketHarness::open();

    $result = app(ParallelStreamBranchWorker::class)->run(
        endpoint: 'socket-harness',
        token: 'test-token',
        deadline: (float) hrtime(true) + 5_000_000_000,
        maxFrameBytes: 65_536,
        branchId: 'parallel:0',
        runId: $runId,
        swarmClass: DeclinedMemoryLiveParallelSwarm::class,
        agentClass: DeclinedMemoryStreamAgent::class,
        index: 0,
        adHoc: false,
        input: 'remember',
        contextPayload: $context->toQueuePayload(),
        nativeSettingsAttemptIds: [],
        snapshotEntries: [],
        ttlSeconds: 3600,
        maxAgentExecutions: 1,
        workerSettings: [
            'capture' => ['inputs' => 'Full', 'outputs' => 'Full'],
            'citations' => ['max_count' => 256, 'max_bytes' => 262_144],
            'provider_tools' => ['max_event_bytes' => 65_536, 'max_step_bytes' => 262_144, 'max_depth' => 32],
            'native_results' => [
                'max_generation_steps' => 128,
                'max_tools' => 128,
                'max_depth' => 32,
                'max_bytes' => 262_144,
            ],
        ],
    );
    $frames = collect(ParallelStreamBranchWorkerSocketHarness::frames());
    $toolResult = $frames->first(fn (array $frame): bool => ($frame['type'] ?? null) === 'event'
        && ($frame['payload']['type'] ?? null) === 'swarm_tool_result');
    $terminal = $frames->firstWhere('type', 'terminal');

    expect($result)->toBe(['branch_id' => 'parallel:0', 'terminal_sent' => true])
        ->and($toolResult['payload']['successful'])->toBeFalse()
        ->and($toolResult['payload']['error'])->toBe($message)
        ->and($terminal['payload']['native_result']['tools'][0]['status'])->toBe('failed');
})->with([
    'empty key' => [false, ['key' => '', 'value' => 'x'], 'A memory key is required.'],
    'capture-policy skip' => [true, ['key' => 'secret', 'value' => 'x'], 'The entry [secret] was not stored.'],
]);
