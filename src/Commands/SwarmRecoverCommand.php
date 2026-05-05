<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:recover')]
class SwarmRecoverCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:recover {--run-id=} {--swarm=} {--limit=50}';

    protected $description = 'Redispatch recoverable durable swarm runs';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit): int
    {
        $runIdOption = $this->option('run-id');
        $swarmOption = $this->option('swarm');
        $targetRunId = is_string($runIdOption) && $runIdOption !== '' ? $runIdOption : null;
        $targetSwarmClass = is_string($swarmOption) && $swarmOption !== '' ? $swarmOption : null;

        try {
            $runIds = $manager->recover(
                runId: $targetRunId,
                swarmClass: $targetSwarmClass,
                limit: $this->optionInt('limit', 50),
            );
        } catch (Throwable $exception) {
            $audit->emit('command.recover', [
                'target_run_id' => $targetRunId,
                'target_swarm_class' => $targetSwarmClass,
                'actor' => 'artisan',
                'status' => 'failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $audit->emit('command.recover', [
            'target_run_id' => $targetRunId,
            'target_swarm_class' => $targetSwarmClass,
            'actor' => 'artisan',
            'recovered_count' => count($runIds),
            'recovered_run_ids' => $runIds,
            'status' => count($runIds) > 0 ? 'recovered' : 'none_found',
        ]);

        if ($runIds === []) {
            $this->components->info('No recoverable durable swarm runs were found.');

            return self::SUCCESS;
        }

        $this->components->info('Redispatched '.count($runIds).' durable swarm run(s).');

        return self::SUCCESS;
    }
}
