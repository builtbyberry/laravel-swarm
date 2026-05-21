<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Support\SwarmHistory;
use BuiltByBerry\LaravelSwarm\Tests\Feature\Examples\StarterExampleRenderer;
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

    $this->namespace = StarterExampleRenderer::render('durable-approval-workflow');
});

test('durable-approval-workflow starter parks at the policy_decision wait after step 1', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\DurableApprovalWorkflow\\PolicyApprovalSwarm';

    expect(class_exists($swarmClass))->toBeTrue();

    $response = $swarmClass::make()->dispatchDurable('Enable two-factor auth for all admins');
    $manager = app(DurableSwarmManager::class);

    // Drain the first job manually (no queue worker in the smoke test).
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);

    $run = $manager->find($response->runId);
    $detail = $manager->inspect($response->runId);

    expect($run['status'])->toBe('waiting')
        ->and($detail->waits)->toHaveCount(1)
        ->and($detail->waits[0]['name'])->toBe('policy_decision')
        ->and($detail->waits[0]['reason'])
        ->toContain('approver');
});

test('durable-approval-workflow starter completes after a policy_decision signal', function () {
    $swarmClass = $this->namespace.'\\Ai\\Swarms\\DurableApprovalWorkflow\\PolicyApprovalSwarm';

    $response = $swarmClass::make()->dispatchDurable('Enable two-factor auth for all admins');
    $manager = app(DurableSwarmManager::class);

    // Step 1 → park at the wait.
    (new AdvanceDurableSwarm($response->runId, 0))->handle($manager);
    expect($manager->find($response->runId)['status'])->toBe('waiting');

    // Approver signals.
    $result = $response->signal('policy_decision', ['approved' => true, 'decision' => 'approve'], 'approval-1');

    expect($result->accepted)->toBeTrue()
        ->and($manager->find($response->runId)['status'])->toBe('pending');

    // Step 2 → finalize and complete.
    (new AdvanceDurableSwarm($response->runId, 1))->handle($manager);

    $run = $manager->find($response->runId);
    $history = app(SwarmHistory::class)->find($response->runId);

    expect($run['status'])->toBe('completed')
        ->and($history['output'])
        ->toContain('Final policy')
        ->toContain('approved and published');
});

test('durable-approval-workflow runner command surfaces the run id from start action', function () {
    $commandClass = $this->namespace.'\\Console\\Commands\\SwarmExampleApprovalWorkflowCommand';

    expect(class_exists($commandClass))->toBeTrue();

    Artisan::registerCommand(new $commandClass);

    $exit = Artisan::call('swarm:example:approval-workflow', [
        'action' => 'start',
        'arg1' => 'Pilot new analytics policy',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)
        ->toContain('Durable run dispatched')
        ->toContain('Run ID');
});
