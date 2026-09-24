<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableNodeStreamRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolCall;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmToolResult;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

pest()->group('process-concurrency', 'skip-locked-real-db');

function preliminaryAttemptWorker(string $runId, string $node, int $epoch, bool $finalOnly): Closure
{
    return static function () use ($runId, $node, $epoch, $finalOnly): int {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        if (! app()->providerIsLoaded(SwarmServiceProvider::class)) {
            app()->register(SwarmServiceProvider::class);
        }
        $sink = app(DurableNodeStreamRecorder::class)->sinkFor($runId, $node, $epoch);
        if (! $finalOnly) {
            $sink(new SwarmToolCall('same-call-event', $runId, 0, 'Agent', new ToolCall('same-call', 'tool', []), 123));
            for ($i = 0; $i < 8; $i++) {
                $sink(new SwarmToolResult('same-partial-'.$i, $runId, 0, 'Agent', new ToolResult('same-call', 'tool', [], [$node, $epoch, $i]), true, null, 123, preliminary: true));
            }
        }
        $sink(new SwarmToolResult('same-final', $runId, 0, 'Agent', new ToolResult('same-call', 'tool', [], [$node, $epoch, 'final']), true, null, 123));

        return $finalOnly ? 1 : 10;
    };
}

beforeEach(function () {
    if (in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true)) {
        $this->markTestSkipped('Function-tool attempt interleaving requires MySQL or PostgreSQL shared across real child processes.');
    }
    config()->set('swarm.persistence.driver', 'database');
    DB::table('swarm_stream_events')->delete();
    DB::table('swarm_run_histories')->delete();
});

test('reused native function-tool IDs remain scoped under real process interleaving and late retry events', function () {
    $runId = 'preliminary-attempt-process';
    DB::table('swarm_run_histories')->insert([
        'run_id' => $runId, 'swarm_class' => 'ExampleSwarm', 'topology' => 'parallel', 'status' => 'running',
        'context' => '{}', 'metadata' => '{}', 'steps' => '[]', 'output' => null, 'usage' => '{}', 'error' => null,
        'artifacts' => '[]', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    $concurrency = app(ConcurrencyManager::class);
    expect($concurrency->driver('process')->run([
        preliminaryAttemptWorker($runId, 'parallel:0', 1, false),
        preliminaryAttemptWorker($runId, 'parallel:1', 1, false),
    ]))->toBe([10, 10]);

    $recorder = app(DurableNodeStreamRecorder::class);
    $recorder->voidPriorAttempt($runId, 'parallel:0', 2, true);
    expect($concurrency->driver('process')->run([
        preliminaryAttemptWorker($runId, 'parallel:0', 1, true),
        preliminaryAttemptWorker($runId, 'parallel:0', 2, false),
    ]))->toBe([1, 10]);
    $recorder->sealNodeBoundary($runId, true);

    $visible = collect(CausalLogView::forRun(app(StreamEventStore::class), $runId)->fold());
    $results = $visible->whereInstanceOf(SwarmToolResult::class)->values();
    expect($results)->toHaveCount(18)
        ->and($visible->whereInstanceOf(SwarmToolCall::class))->toHaveCount(2);
    foreach ($results->groupBy('nodeId') as $node => $events) {
        $expectedEpoch = $node === 'parallel:0' ? 2 : 1;
        expect($events)->toHaveCount(9)
            ->and($events->pluck('attemptEpoch')->unique()->values()->all())->toBe([$expectedEpoch])
            ->and($events->pluck('id')->values()->all())->toBe([...array_map(fn (int $i): string => 'same-partial-'.$i, range(0, 7)), 'same-final'])
            ->and($events->pluck('preliminary')->values()->all())->toBe([...array_fill(0, 8, true), false]);
        foreach ($events as $event) {
            expect($event->toolResult->id)->toBe('same-call')
                ->and(array_slice($event->toolResult->result, 0, 2))->toBe([$node, $expectedEpoch]);
        }
    }
});
