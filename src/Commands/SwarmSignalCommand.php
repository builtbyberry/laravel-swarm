<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Commands;

use BuiltByBerry\LaravelSwarm\Commands\Concerns\ResolvesStringConsoleInput;
use BuiltByBerry\LaravelSwarm\Contracts\AcceptsDurableSignals;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
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

    public function handle(DurableSwarmManager $manager, DurableRunStore $store): int
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

        // The run as it is now, after the signal: another process may already have
        // resumed, completed or cancelled it, so this describes the run, not the signal.
        $detail = $manager->inspect($runId);
        $runStatus = $detail->run['status'] ?? null;

        $this->report($result, $name, is_string($runStatus) ? $runStatus : null, $detail->waits, $store);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $waits
     */
    protected function report(DurableSignalResult $result, string $name, ?string $runStatus, array $waits, DurableRunStore $store): void
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

        if (in_array($runStatus, ['completed', 'failed', 'cancelled'], true)) {
            $this->components->info("Signal recorded. The run is [{$runStatus}]; no wait will accept it.");

            return;
        }

        $named = array_values(array_filter($waits, fn (array $wait): bool => ($wait['name'] ?? null) === $name));
        $open = array_filter($named, fn (array $wait): bool => ($wait['status'] ?? null) === 'waiting');

        if ($open === [] && $named !== []) {
            $closed = (string) ($named[count($named) - 1]['status'] ?? 'closed');
            $this->components->info("Signal recorded. The wait named [{$name}] already closed ({$closed}); this signal is not applied.");

            return;
        }

        if ($open === []) {
            $this->components->info("Signal recorded. No open wait named [{$name}] accepted it.");

            return;
        }

        if ($runStatus === 'paused') {
            $cause = $store instanceof AcceptsDurableSignals
                ? 'the signal was sent inside a database transaction'
                : "the run's durable store cannot accept a signal while the run is paused";
            $this->components->warn("Signal recorded but not accepted. The run is paused and {$cause}. To apply it, re-send it after the run is resumed, with a new idempotency key.");

            return;
        }

        $this->components->info("Signal recorded. The open wait named [{$name}] did not accept it while the run is [{$runStatus}].");
    }
}
