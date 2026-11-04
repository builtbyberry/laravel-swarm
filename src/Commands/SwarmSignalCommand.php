<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Responses\DurableSignalResult;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'swarm:signal')]
class SwarmSignalCommand extends Command
{
    use ResolvesStringConsoleInput;

    protected $signature = 'swarm:signal {runId : The durable run ID} {name : The signal name} {--payload= : JSON payload} {--idempotency-key= : Idempotency key for retried signals}';

    protected $description = 'Send a signal to a waiting or paused durable swarm run';

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

        $runId = $this->argumentString('runId');
        $name = $this->argumentString('name');

        $result = $manager->signal(
            $runId,
            $name,
            $decoded,
            is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        // The run's status read now, after the signal: another process may already have
        // resumed, completed or cancelled it, so it describes the run, not the signal.
        $runStatus = $manager->find($runId)['status'] ?? null;

        $this->report($result, $name, is_string($runStatus) ? $runStatus : null);

        return self::SUCCESS;
    }

    protected function report(DurableSignalResult $result, string $name, ?string $runStatus): void
    {
        if ($result->duplicate) {
            $this->components->warn('Duplicate signal: this idempotency key is already recorded for the run. Nothing changed.');

            return;
        }

        if ($result->accepted) {
            $this->components->info(match ($runStatus) {
                'paused' => 'Signal accepted. The run is paused and continues when it is resumed.',
                'waiting' => 'Signal accepted. The run is still waiting (on another wait or on its branches).',
                default => 'Signal accepted and run released.',
            });

            return;
        }

        if ($runStatus === 'paused') {
            $this->components->warn("Signal recorded but not accepted. Either the run has no open wait named [{$name}], or the signal was sent inside a database transaction, or the run's durable store cannot accept signals while paused. Check the waits with swarm:inspect; to apply it, re-send it after resume with a new idempotency key.");

            return;
        }

        $this->components->info("Signal recorded. No open wait named [{$name}] accepted it.");
    }
}
