<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Drain the durable-streaming-digest run to completion in-process (no queue
 * worker, mirroring the runner command's demo drain), returning the run id.
 */
function drainStreamingDigest(string $swarmClass, string $topic): string
{
    $response = $swarmClass::make()->dispatchDurable($topic);
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    $guard = 0;
    while (! in_array((string) ($manager->find($runId)['status'] ?? 'completed'), ['completed', 'failed', 'cancelled'], true)) {
        if ($guard++ > 50) {
            throw new RuntimeException('durable-streaming-digest run did not converge.');
        }

        $stepIndex = (int) ($manager->find($runId)['next_step_index'] ?? 0);
        (new AdvanceDurableSwarm($runId, $stepIndex))->handle($manager);
    }

    return $runId;
}

/** Reconstruct each node's streamed text from the persisted causal log. */
function reconstructStreamingDigest(string $runId): array
{
    $byNode = [];

    foreach (CausalLogView::forRun(app(CausalLogStore::class), $runId)->fold() as $event) {
        /** @var SwarmStreamEvent $event */
        $nodeId = $event->toArray()['node_id'] ?? null;

        if (is_string($nodeId)) {
            $byNode[$nodeId][] = $event;
        }
    }

    return array_map(static fn (array $events): string => SwarmTextDelta::combine($events), $byNode);
}

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([
        ContextStore::class,
        ArtifactRepository::class,
        RunHistoryStore::class,
        DurableRunStore::class,
        SwarmRunner::class,
        DurableSwarmManager::class,
    ] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    $this->namespace = StarterExampleRenderer::render('durable-streaming-digest');
    $this->swarmClass = $this->namespace.'\\Ai\\Swarms\\DurableStreamingDigest\\StreamingDigestSwarm';
});

test('durable-streaming-digest pins durable_streaming true and runs an actually-durable stream', function () {
    expect(class_exists($this->swarmClass))->toBeTrue();

    $runId = drainStreamingDigest($this->swarmClass, 'Weekly engineering digest');

    // (1) The run is genuinely durable-streaming — the attribute is resolved and
    //     pinned on the run row at run-start (a live run would create no row).
    expect((bool) DB::table('swarm_durable_runs')->where('run_id', $runId)->value('durable_streaming'))->toBeTrue();

    // (4) It reaches completed.
    expect((string) app(DurableSwarmManager::class)->find($runId)['status'])->toBe('completed');
});

test('durable-streaming-digest persists per-node token deltas (a live run writes zero)', function () {
    $runId = drainStreamingDigest($this->swarmClass, 'Weekly engineering digest');

    // (2) Each worker node persisted swarm_text_delta rows under its own node id.
    //     This is the honesty assertion: a #[DurableStreaming] swarm invoked LIVE
    //     writes zero of these rows — persistence is what makes the demo honest.
    foreach (['step:0', 'step:1'] as $nodeId) {
        expect(
            DB::table('swarm_stream_events')
                ->where('run_id', $runId)
                ->where('node_id', $nodeId)
                ->where('event_type', 'swarm_text_delta')
                ->count()
        )->toBeGreaterThan(0, "no swarm_text_delta rows for node [{$nodeId}]");
    }
});

test('durable-streaming-digest replays the streamed text per node from the causal log', function () {
    $runId = drainStreamingDigest($this->swarmClass, 'Weekly engineering digest');

    // (3) The CausalLogView fold reconstructs each node's streamed text.
    $perNode = reconstructStreamingDigest($runId);

    expect($perNode['step:0'] ?? null)->toBe('This week in engineering: ')
        ->and($perNode['step:1'] ?? null)->toBe('Three PRs merged, zero incidents.');
});

test('durable-streaming-digest runner command dispatches durably, drains, and prints the replayed text', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleStreamingCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:streaming', [
        'action' => 'run',
        'topic' => 'Weekly engineering digest',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Durable streaming run dispatched')
        ->toContain('completed')
        ->toContain('This week in engineering:')
        ->toContain('Three PRs merged, zero incidents.');
});
