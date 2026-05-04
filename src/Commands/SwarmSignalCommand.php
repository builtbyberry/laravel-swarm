<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:signal')]
class SwarmSignalCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:signal {runId : The durable run ID} {name : The signal name} {--payload= : JSON payload} {--idempotency-key= : Idempotency key for retried signals}';

    protected $description = 'Send a signal to a waiting durable swarm run';

    public function handle(DurableSwarmManager $manager): int
    {
        $payload = $this->option('payload');

        try {
            $decoded = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            $this->components->error('The --payload option must be valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        $idempotencyKey = $this->option('idempotency-key');

        $result = $manager->signal(
            $this->argumentString('runId'),
            $this->argumentString('name'),
            $decoded,
            is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        $this->components->info($result->accepted ? 'Signal accepted and run released.' : 'Signal recorded.');

        return self::SUCCESS;
    }
}
