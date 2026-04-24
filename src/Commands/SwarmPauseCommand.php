<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:pause')]
class SwarmPauseCommand extends Command
{
    protected $signature = 'swarm:pause {runId}';

    protected $description = 'Pause a durable swarm run at the next safe boundary';

    public function handle(DurableSwarmManager $manager): int
    {
        $manager->pause((string) $this->argument('runId'));

        $this->components->info('Durable swarm pause request recorded.');

        return self::SUCCESS;
    }
}
