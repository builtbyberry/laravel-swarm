<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:resume')]
class SwarmResumeCommand extends Command
{
    protected $signature = 'swarm:resume {runId}';

    protected $description = 'Resume a paused durable swarm run';

    public function handle(DurableSwarmManager $manager): int
    {
        $manager->resume((string) $this->argument('runId'));

        $this->components->info('Durable swarm resumed.');

        return self::SUCCESS;
    }
}
