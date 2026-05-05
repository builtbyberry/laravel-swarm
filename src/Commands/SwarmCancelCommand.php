<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:cancel')]
class SwarmCancelCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:cancel {runId}';

    protected $description = 'Cancel a durable swarm run';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit): int
    {
        $runId = $this->argumentString('runId');
        $manager->cancel($runId);

        $audit->emit('command.cancel', [
            'run_id' => $runId,
            'actor' => 'artisan',
            'status' => 'requested',
        ]);

        $this->components->info('Durable swarm cancelled or cancellation requested.');

        return self::SUCCESS;
    }
}
