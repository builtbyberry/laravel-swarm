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
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FlakyDurableAgent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeParallelFailingSwarm;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\RetryableDurableSwarm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Coverage for v0.4.2 — durable retry logging (#1).
 *
 * Before v0.4.2 the durable runtime caught exceptions on the agent prompt
 * path and silently scheduled a retry (or, on the branch path, silently
 * marked the branch failed). Application-side observability had no signal
 * that anything was wrong unless the run hit a hard error from infrastructure
 * (e.g. a queue driver failure). These tests assert that the new log
 * emissions land at warning level when scheduling a retry and at error
 * level when terminally failing a branch.
 */
beforeEach(function (): void {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('queue.connections.durable-test', ['driver' => 'null']);
    config()->set('swarm.durable.queue.connection', 'durable-test');
    config()->set('swarm.durable.queue.name', 'swarm-durable');

    foreach ([ContextStore::class, ArtifactRepository::class, RunHistoryStore::class, DurableRunStore::class, SwarmRunner::class, DurableSwarmManager::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    FakeResearcher::fake(['research-out']);
    FakeWriter::fake(['writer-out']);
    FlakyDurableAgent::reset();
});

test('scheduleRunRetryIfAllowed logs a warning when scheduling a retry (#1)', function (): void {
    // First attempt fails, subsequent attempt would succeed — but we only need
    // to drive the first step to trigger the retry-scheduling code path.
    FlakyDurableAgent::reset(failuresBeforeSuccess: 1);

    Log::shouldReceive('warning')
        ->atLeast()
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Durable swarm step failed — scheduling retry.'
                && isset($context['run_id'], $context['retry_attempt'], $context['exception'], $context['message'])
                && $context['retry_attempt'] === 1
                && is_string($context['exception']);
        });

    // Allow other log levels through so we don't fail on incidental Laravel logs.
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('notice')->andReturnNull();

    $response = RetryableDurableSwarm::make()->dispatchDurable('flaky-task');
    (new AdvanceDurableSwarm($response->runId, 0))->handle(app(DurableSwarmManager::class));
});

test('branch terminal failure logs an error before markBranchFailed (#1)', function (): void {
    // FakeParallelFailingSwarm has no DurableRetry attribute, so the first
    // branch failure has no retry policy and goes straight to markBranchFailed
    // — the path that issue #1 was specifically about (silent failure with
    // no log entry, no failed_jobs row).

    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('notice')->andReturnNull();

    Log::shouldReceive('error')
        ->atLeast()
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Durable swarm branch failed — retries exhausted or non-retryable.'
                && isset($context['run_id'], $context['branch_id'], $context['agent_class'], $context['retry_attempt'], $context['exception'], $context['message'])
                && is_string($context['exception']);
        });

    $response = FakeParallelFailingSwarm::make()->dispatchDurable('failing-parallel-task');
    $manager = app(DurableSwarmManager::class);

    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    foreach (app(DurableRunStore::class)->branchesFor($response->runId, 'parallel') as $branch) {
        (new AdvanceDurableBranch($response->runId, $branch['branch_id']))->handle($manager);
    }
});
