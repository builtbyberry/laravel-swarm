<?php

declare(strict_types=1);

namespace {{ rootNamespace }}\Console\Commands;

use {{ rootNamespace }}\Ai\Swarms\DurableApprovalWorkflow\PolicyApprovalSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runner for the durable-approval-workflow starter example.
 *
 * Usage:
 *   # Step A: kick off a durable run; prints the run id
 *   php artisan swarm:example:approval-workflow start "Enable two-factor auth for all admins"
 *
 *   # Step B: simulate an approver decision (works once a queue worker has
 *   #         drained the first job and the run is waiting)
 *   php artisan swarm:example:approval-workflow signal <run-id> approve
 *   php artisan swarm:example:approval-workflow signal <run-id> reject
 *
 *   # Step C: inspect the current state
 *   php artisan swarm:example:approval-workflow status <run-id>
 *
 * In a real app you would call DurableSwarmManager::signal() from a
 * controller or job, not from an artisan command.
 */
#[AsCommand(name: 'swarm:example:approval-workflow')]
class SwarmExampleApprovalWorkflowCommand extends Command
{
    protected $signature = 'swarm:example:approval-workflow
        {action : start|signal|status}
        {arg1? : Topic (start) or run-id (signal|status)}
        {arg2? : Decision approve|reject (signal only)}';

    protected $description = 'Run the durable-approval-workflow starter example: start, signal, or status.';

    public function handle(DurableSwarmManager $manager): int
    {
        return match ((string) $this->argument('action')) {
            'start' => $this->start(),
            'signal' => $this->signal($manager),
            'status' => $this->status($manager),
            default => $this->invalidAction(),
        };
    }

    private function start(): int
    {
        $topic = (string) ($this->argument('arg1') ?? 'Enable two-factor auth for all admins');

        $response = PolicyApprovalSwarm::make()->dispatchDurable($topic);

        $this->components->info('Durable run dispatched.');
        $this->components->twoColumnDetail('Run ID', $response->runId);
        $this->components->bulletList([
            'Run a queue worker on your durable connection to drain the first job.',
            'When the run hits the `policy_decision` wait, call:',
            'php artisan swarm:example:approval-workflow signal '.$response->runId.' approve',
        ]);

        return self::SUCCESS;
    }

    private function signal(DurableSwarmManager $manager): int
    {
        $runId = (string) $this->argument('arg1');
        $decision = (string) ($this->argument('arg2') ?? 'approve');

        if ($runId === '') {
            $this->components->error('signal requires a run-id argument.');

            return self::FAILURE;
        }

        $result = $manager->signal(
            $runId,
            'policy_decision',
            ['approved' => $decision === 'approve', 'decision' => $decision],
        );

        $this->components->info($result->accepted
            ? "Signal accepted: run {$runId} resumed."
            : 'Signal recorded (no waiting run released).');

        return self::SUCCESS;
    }

    private function status(DurableSwarmManager $manager): int
    {
        $runId = (string) $this->argument('arg1');

        if ($runId === '') {
            $this->components->error('status requires a run-id argument.');

            return self::FAILURE;
        }

        $run = $manager->find($runId);

        if ($run === null) {
            $this->components->error("No durable run found for {$runId}.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Run ID', $runId);
        $this->components->twoColumnDetail('Status', (string) ($run['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Next step index', (string) ($run['next_step_index'] ?? 0));
        $this->components->twoColumnDetail('Wait reason', (string) ($run['wait_reason'] ?? '(none)'));

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->components->error('Unknown action. Use one of: start, signal, status.');

        return self::FAILURE;
    }
}
