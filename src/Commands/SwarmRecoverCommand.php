<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:recover')]
class SwarmRecoverCommand extends Command
{
    protected $signature = 'swarm:recover {--run-id=} {--swarm=} {--limit=50}';

    protected $description = 'Redispatch recoverable durable swarm runs';

    public function handle(DurableSwarmManager $manager): int
    {
        $runIds = $manager->recover(
            runId: $this->option('run-id') ? (string) $this->option('run-id') : null,
            swarmClass: $this->option('swarm') ? (string) $this->option('swarm') : null,
            limit: (int) $this->option('limit'),
        );

        if ($runIds === []) {
            $this->components->info('No recoverable durable swarm runs were found.');

            return self::SUCCESS;
        }

        $this->components->info('Redispatched '.count($runIds).' durable swarm run(s).');

        return self::SUCCESS;
    }
}
