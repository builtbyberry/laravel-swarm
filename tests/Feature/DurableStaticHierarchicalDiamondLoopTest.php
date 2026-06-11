<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableBranch;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeEditor;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeReviewer;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeStaticHierarchicalDiamondLoopSwarm;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    app()->forgetInstance(ContextStore::class);
    app()->forgetInstance(ArtifactRepository::class);
    app()->forgetInstance(RunHistoryStore::class);
    app()->forgetInstance(DurableRunStore::class);
    app()->forgetInstance(SwarmRunner::class);
    app()->forgetInstance(DurableSwarmManager::class);

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(array_fill(0, 20, 'research-out'));
    FakeWriter::fake(array_fill(0, 20, 'write-out'));
    FakeEditor::fake(array_fill(0, 20, 'synth-out'));
    FakeReviewer::fake(array_fill(0, 20, 'review-out'));
});

/**
 * Drive a durable run to completion, resolving any branch fan-outs as they appear.
 */
function driveDiamondRun(string $runId, DurableSwarmManager $manager): void
{
    $guard = 0;

    while (true) {
        if ($guard++ > 200) {
            throw new RuntimeException('Diamond durable run did not converge.');
        }

        $run = $manager->find($runId);

        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        if ($run['status'] === 'waiting') {
            $branches = app(DurableRunStore::class)->branchesFor($runId, $run['current_node_id']);

            foreach ($branches as $branch) {
                if (in_array($branch['status'] ?? null, ['pending', null], true)) {
                    (new AdvanceDurableBranch($runId, $branch['branch_id']))->handle($manager);
                }
            }

            continue;
        }

        (new AdvanceDurableSwarm($runId, (int) $run['next_step_index']))->handle($manager);
    }
}

test('durable diamond loop rewinds to the single post-join occurrence and re-executes the body', function () {
    $response = FakeStaticHierarchicalDiamondLoopSwarm::make()->dispatchDurable('durable-diamond');
    $runId = $response->runId;
    $manager = app(DurableSwarmManager::class);

    driveDiamondRun($runId, $manager);

    $history = app(SwarmHistory::class)->find($runId);
    $executed = $history['context']['metadata']['executed_node_ids'];
    $count = fn (string $id): int => count(array_filter($executed, static fn (string $n): bool => $n === $id));

    expect($manager->find($runId)['status'])->toBe('completed')
        // The parallel fan-out runs once; the post-join synth + review body loops 3×.
        // Under a first-match (wrong) rewind the body would re-run from the wrong
        // offset and diverge — here it re-executes the synth→review body each pass.
        ->and($count('fan_out'))->toBe(1)
        ->and($count('branch_research'))->toBe(1)
        ->and($count('branch_write'))->toBe(1)
        ->and($count('synth'))->toBe(3)
        ->and($count('review'))->toBe(3)
        ->and($history['output'])->toBe('review-out');
});
