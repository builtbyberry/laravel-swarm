<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\FakeSequentialSwarm;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;

/**
 * Process-concurrency coverage for swarm.durable.eager_dispatch: an eager push
 * and a relay push for the same durable step, consumed by two workers at once.
 *
 * The race is made real, not hoped for. In the parent, each run's eager job is
 * captured, its born-reserved outbox entry is aged past the reservation timeout,
 * and drain() reclaims it and pushes a relay job — so both deliveries exist
 * before either runs. Two child processes then meet at a file barrier and handle
 * their jobs together: one holds every eager job, the other every relay job.
 *
 * The invariant: each step's agent runs exactly once (counted in a table both
 * processes write to), each run's cursor advances exactly once, and no outbox
 * entry is left for the step that was raced.
 */
// Tagged `skip-locked-real-db` so the CI lane (`test:process-concurrency:ci`)
// excludes it under `--fail-on-skipped`: it requires a shared MySQL/Postgres
// connection and skips on the default in-memory SQLite, which is per-process and
// cannot be reached by the child processes the concurrency driver spawns.
pest()->group('process-concurrency', 'skip-locked-real-db');

function durableEagerConcurrencyDriverSupported(): bool
{
    return ! in_array(DB::connection()->getDriverName(), ['sqlite', 'sqlsrv'], true);
}

/**
 * A worker that waits for both workers to be ready, then handles its jobs in
 * order. Built by a free function so it serializes cleanly to the child process,
 * which runs against bare testbench: everything it needs is set up inline.
 *
 * @param  list<string>  $serializedJobs
 */
function durableEagerRaceWorker(string $label, array $serializedJobs, string $barrierPath): Closure
{
    return static function () use ($label, $serializedJobs, $barrierPath): array {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        config()->set('swarm.capture.inputs', true);
        config()->set('swarm.capture.outputs', true);
        config()->set('swarm.capture.artifacts', true);
        config()->set('swarm.capture.active_context', true);
        foreach ([BusServiceProvider::class, AiServiceProvider::class, SwarmServiceProvider::class] as $provider) {
            if (! app()->providerIsLoaded($provider)) {
                app()->register($provider);
            }
        }

        FakeWriter::fake(static function () use ($label): string {
            DB::table('eager_race_executions')->insert(['worker' => $label]);

            return 'writer-out';
        });

        file_put_contents($barrierPath.'/ready-'.$label, 'ready');
        $deadline = microtime(true) + 10.0;

        while (count(glob($barrierPath.'/ready-*') ?: []) < 2) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for the eager race barrier [{$barrierPath}].");
            }

            usleep(1_000);
        }

        $manager = app(DurableSwarmManager::class);

        foreach ($serializedJobs as $serialized) {
            unserialize($serialized)->handle($manager);
        }

        return ['worker' => $label, 'handled' => count($serializedJobs)];
    };
}

beforeEach(function (): void {
    if (! durableEagerConcurrencyDriverSupported()) {
        $this->markTestSkipped(
            'Eager dispatch concurrency test requires a shared database engine '
            .'reachable from child processes (mysql/pgsql). Current driver: '
            .DB::connection()->getDriverName().'.'
        );
    }

    config()->set('swarm.persistence.driver', 'database');

    Schema::dropIfExists('eager_race_executions');
    Schema::create('eager_race_executions', function (Blueprint $table): void {
        $table->id();
        $table->string('worker');
    });
});

afterEach(function (): void {
    if (durableEagerConcurrencyDriverSupported()) {
        Schema::dropIfExists('eager_race_executions');
    }
});

test('an eager push and a relay push consumed at once advance each step exactly once', function (): void {
    config()->set('swarm.durable.eager_dispatch', true);
    Queue::fake();
    FakeResearcher::fake(['research-out']);

    $manager = app(DurableSwarmManager::class);
    $outbox = app(DurableOutbox::class);
    $runIds = [];
    $eagerJobs = [];

    for ($i = 0; $i < 8; $i++) {
        $runId = FakeSequentialSwarm::make()->dispatchDurable('eager-race-'.$i)->runId;
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        $runIds[] = $runId;
    }

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            if ($push['job'] instanceof AdvanceDurableSwarm && $push['job']->outboxEntryId !== null) {
                $eagerJobs[] = $push['job'];
            }
        }
    }

    expect($eagerJobs)->toHaveCount(8);

    // Age every born-reserved entry past the reservation timeout and let the relay
    // reclaim it, so a relay job exists for every step before anything runs.
    DB::table('swarm_durable_outbox')
        ->whereIn('id', array_map(fn (AdvanceDurableSwarm $job): int => (int) $job->outboxEntryId, $eagerJobs))
        ->update(['reserved_at' => now('UTC')->subMinutes(5)]);

    $drain = $outbox->drain([], 100);
    $relayJobs = [];

    foreach (Queue::pushedJobs() as $pushes) {
        foreach ($pushes as $push) {
            if ($push['job'] instanceof AdvanceDurableSwarm && $push['job']->stepIndex === 1 && $push['job']->outboxEntryId === null) {
                $relayJobs[] = $push['job'];
            }
        }
    }

    expect($drain->reclaimed)->toBe(8)
        ->and($relayJobs)->toHaveCount(8);

    $barrierPath = sys_get_temp_dir().'/laravel-swarm-eager-race-'.bin2hex(random_bytes(6));
    mkdir($barrierPath);

    try {
        $results = $this->app->make(ConcurrencyManager::class)->driver('process')->run([
            durableEagerRaceWorker('eager', array_map('serialize', $eagerJobs), $barrierPath),
            durableEagerRaceWorker('relay', array_map('serialize', $relayJobs), $barrierPath),
        ]);
    } finally {
        array_map('unlink', glob($barrierPath.'/*') ?: []);
        rmdir($barrierPath);
    }

    expect(array_column($results, 'handled'))->toBe([8, 8])
        ->and(DB::table('eager_race_executions')->count())->toBe(8);

    foreach ($runIds as $runId) {
        $stepOneEntries = DB::table('swarm_durable_outbox')
            ->where('run_id', $runId)
            ->get()
            ->filter(fn (object $row): bool => (json_decode((string) $row->payload, true)['step_index'] ?? null) === 1)
            ->count();

        expect($manager->find($runId)['next_step_index'])->toBe(2)
            ->and($stepOneEntries)->toBe(0);
    }
});
