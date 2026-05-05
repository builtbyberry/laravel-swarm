<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:pause')]
class SwarmPauseCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:pause {runId}';

    protected $description = 'Pause a durable swarm run at the next safe boundary';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit): int
    {
        $runId = $this->argumentString('runId');
        $manager->pause($runId);

        $audit->emit('command.pause', [
            'run_id' => $runId,
            'actor' => 'artisan',
            'status' => 'requested',
        ]);

        $this->components->info('Durable swarm pause request recorded.');

        return self::SUCCESS;
    }
}
