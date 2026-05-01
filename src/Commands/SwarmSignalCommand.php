<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:signal')]
class SwarmSignalCommand extends Command
{
    protected $signature = 'swarm:signal {runId : The durable run ID} {name : The signal name} {--payload= : JSON payload} {--idempotency-key= : Idempotency key for retried signals}';

    protected $description = 'Send a signal to a waiting durable swarm run';

    public function handle(DurableSwarmManager $manager): int
    {
        $payload = $this->option('payload');

        try {
            $decoded = $payload !== null ? json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            $this->components->error('The --payload option must be valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        $result = $manager->signal(
            (string) $this->argument('runId'),
            (string) $this->argument('name'),
            $decoded,
            $this->option('idempotency-key') !== null ? (string) $this->option('idempotency-key') : null,
        );

        $this->components->info($result->accepted ? 'Signal accepted and run released.' : 'Signal recorded.');

        return self::SUCCESS;
    }
}
