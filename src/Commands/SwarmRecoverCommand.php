<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:recover')]
class SwarmRecoverCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:recover {--run-id=} {--swarm=} {--limit=50}';

    protected $description = 'Redispatch recoverable durable swarm runs';

    public function handle(DurableSwarmManager $manager): int
    {
        $runIdOption = $this->option('run-id');
        $swarmOption = $this->option('swarm');

        $runIds = $manager->recover(
            runId: is_string($runIdOption) && $runIdOption !== '' ? $runIdOption : null,
            swarmClass: is_string($swarmOption) && $swarmOption !== '' ? $swarmOption : null,
            limit: $this->optionInt('limit', 50),
        );

        if ($runIds === []) {
            $this->components->info('No recoverable durable swarm runs were found.');

            return self::SUCCESS;
        }

        $this->components->info('Redispatched '.count($runIds).' durable swarm run(s).');

        return self::SUCCESS;
    }
}
