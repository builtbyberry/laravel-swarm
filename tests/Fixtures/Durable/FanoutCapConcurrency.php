<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Durable;

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableOutbox;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use RuntimeException;

/**
 * Setup shared by the fan-out cap process-concurrency workers. A class, not test-file
 * functions: the child processes load only autoloadable code.
 */
final class FanoutCapConcurrency
{
    public static function boot(int $cap): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        config()->set('swarm.capture.inputs', true);
        config()->set('swarm.capture.outputs', true);
        config()->set('swarm.capture.artifacts', true);
        config()->set('swarm.capture.active_context', true);
        config()->set('queue.connections.durable-test', ['driver' => 'null']);
        config()->set('swarm.durable.queue.connection', 'durable-test');
        config()->set('swarm.durable.parallel.max_concurrent_branches', $cap);

        foreach ([BusServiceProvider::class, AiServiceProvider::class, SwarmServiceProvider::class] as $provider) {
            if (! app()->providerIsLoaded($provider)) {
                app()->register($provider);
            }
        }
    }

    /**
     * Admitted and unfinished branches of the run's fan-out — what the cap bounds.
     */
    public static function admittedUnfinished(string $runId): int
    {
        $held = [];

        foreach (DatabaseDurableOutbox::constrainHeld(DB::table('swarm_durable_outbox'))->where('run_id', $runId)->get(['payload']) as $row) {
            $held[] = json_decode((string) $row->payload, true)['branch_id'] ?? null;
        }

        return DB::table('swarm_durable_branches')
            ->where('run_id', $runId)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->pluck('branch_id')
            ->reject(fn (string $id): bool => in_array($id, $held, true))
            ->count();
    }

    public static function barrier(string $path, string $label, int $parties): void
    {
        file_put_contents($path.'/ready-'.$label, 'ready');
        $deadline = microtime(true) + 15.0;

        while (count(glob($path.'/ready-*') ?: []) < $parties) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for barrier [{$path}].");
            }

            usleep(1_000);
        }
    }
}
