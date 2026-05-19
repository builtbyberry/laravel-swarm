<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\SwarmAuditDispatcher;
use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'swarm:resume')]
class SwarmResumeCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:resume {runId}';

    protected $description = 'Resume a paused durable swarm run';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit): int
    {
        $runId = $this->argumentString('runId');
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        try {
            $manager->resume($runId);
        } catch (Throwable $exception) {
            $audit->emit('command.resume', [
                'run_id' => $runId,
                'status' => 'failed',
                'exception_class' => $exception::class,
                ...$audit->metadata($actorMetadata),
            ]);

            throw $exception;
        }

        $audit->emit('command.resume', [
            'run_id' => $runId,
            'status' => 'dispatched',
            ...$audit->metadata($actorMetadata),
        ]);

        $this->components->info('Durable swarm resumed.');

        return self::SUCCESS;
    }
}
