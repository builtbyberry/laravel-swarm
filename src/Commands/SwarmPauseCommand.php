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

#[AsCommand(name: 'swarm:pause')]
class SwarmPauseCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:pause {runId}';

    protected $description = 'Pause a durable swarm run at the next safe boundary';

    public function handle(DurableSwarmManager $manager, SwarmAuditDispatcher $audit): int
    {
        $runId = $this->argumentString('runId');
        $actorMetadata = ['actor' => Actor::system('artisan')->toArray()];

        try {
            $manager->pause($runId);
        } catch (Throwable $exception) {
            $audit->emit('command.pause', [
                'run_id' => $runId,
                'status' => 'failed',
                'exception_class' => $exception::class,
                ...$audit->metadata($actorMetadata),
            ]);

            throw $exception;
        }

        $audit->emit('command.pause', [
            'run_id' => $runId,
            'status' => 'requested',
            ...$audit->metadata($actorMetadata),
        ]);

        $this->components->info('Durable swarm pause request recorded.');

        return self::SUCCESS;
    }
}
