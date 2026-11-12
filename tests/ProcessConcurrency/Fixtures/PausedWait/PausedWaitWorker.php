<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\PausedWait;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\AdvanceDurableSwarm;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeHierarchicalCoordinator;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeResearcher;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\FakeWriter;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\ResumeOpenWaitWorker;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Worker entry points are autoloadable in bare Testbench, without the Pest file. */
final class PausedWaitWorker
{
    public static function boot(string $isolation = 'READ COMMITTED'): HookedDurableRunStore
    {
        ResumeOpenWaitWorker::boot();
        if (! in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
            throw new RuntimeException('Unexpected test isolation level.');
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
            $actual = DB::selectOne('select @@session.transaction_isolation as isolation_level')->isolation_level;
        } else {
            DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '.$isolation);
            $actual = DB::selectOne('show transaction_isolation')->transaction_isolation;
        }
        if (strtoupper(str_replace('-', ' ', $actual)) !== $isolation) {
            throw new RuntimeException("Worker isolation [{$actual}] differs from [{$isolation}].");
        }
        $store = app(HookedDurableRunStore::class);
        app()->instance(DurableRunStore::class, $store);
        app()->forgetInstance(DurableSwarmManager::class);

        return $store;
    }

    public static function state(string $runId): array
    {
        return [
            'status' => DB::table('swarm_durable_runs')->where('run_id', $runId)->value('status'),
            'open' => DB::table('swarm_durable_waits')->where('run_id', $runId)->where('status', 'waiting')->count(),
        ];
    }

    public static function creatorFirst(string $runId, string $dir, bool $rollback = false): array
    {
        $store = self::boot();
        $me = LockBarrier::connectionId();
        $blocked = false;
        $locks = [];
        $store->beforeSync = static function () use ($dir, $me, &$blocked, &$locks): void {
            // createWait has INSERTed and still owns its transaction/run lock.
            LockBarrier::signal($dir, 'inserted');
            $resumer = (int) LockBarrier::await($dir, 'resumer-id');
            $blocked = LockBarrier::awaitBlockedBy($resumer, $me);
            $locks = DB::connection()->getDriverName() === 'pgsql'
                ? array_map(static fn (object $row): array => (array) $row, DB::select('select pid, query, wait_event_type from pg_stat_activity where pid = ?', [$resumer]))
                : array_values(array_filter(LockBarrier::lockWaitSnapshot(), static fn (array $row): bool => (int) ($row['waiting_pid'] ?? 0) === $resumer));
            if (! $blocked) {
                throw new RuntimeException('G1 resumer did not block on creator: '.json_encode($locks));
            }
        };
        try {
            DB::transaction(function () use ($store, $runId, $rollback): void {
                $store->createWait($runId, 'late');
                if ($rollback) {
                    throw new RuntimeException('creator deliberately rolled back');
                }
            });
        } catch (Throwable $e) {
            if (! $rollback || $e->getMessage() !== 'creator deliberately rolled back') {
                throw $e;
            }
        }

        return ['outcome' => $rollback ? 'rolled-back' : 'created', 'blocked' => $blocked, 'locks' => $locks];
    }

    public static function resumeAfterInsert(string $runId, string $dir): array
    {
        self::boot();
        LockBarrier::signal($dir, 'resumer-id', (string) LockBarrier::connectionId());
        LockBarrier::await($dir, 'inserted');

        return ['outcome' => app(DurableSwarmManager::class)->resume($runId)->status->value];
    }

    public static function scanFirst(string $runId, string $dir, string $isolation, bool $cycle = false): array
    {
        $store = self::boot($isolation);
        $me = LockBarrier::connectionId();
        $blocked = false;
        $store->afterResumeWaitScan = static function () use ($dir, $me, $cycle, &$blocked): void {
            LockBarrier::signal($dir, 'scanned', (string) $me);
            if ($cycle) {
                $creator = (int) LockBarrier::await($dir, 'creator-holds-run');
                // The empty RR scan holds the gap the INSERT needs. Prove that
                // edge before resume requests the creator's run lock.
                $blocked = LockBarrier::awaitBlockedBy($creator, $me);
                if (! $blocked) {
                    throw new RuntimeException('G2(iii) creator not blocked on scan gap: '.json_encode(LockBarrier::lockWaitSnapshot()));
                }
            } else {
                LockBarrier::await($dir, 'created');
            }
        };
        try {
            $outcome = app(DurableSwarmManager::class)->resume($runId)->status->value;
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'blocked' => $blocked];
        }

        return ['outcome' => $outcome, 'blocked' => $blocked, 'at_commit' => self::state($runId)];
    }

    public static function createAfterScan(string $runId, string $dir, string $isolation, bool $cycle = false): array
    {
        $store = self::boot($isolation);
        LockBarrier::await($dir, 'scanned');
        if ($cycle) {
            $store->afterRunLock = static fn () => LockBarrier::signal($dir, 'creator-holds-run', (string) LockBarrier::connectionId());
        }
        try {
            $store->createWait($runId, 'late');
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        } finally {
            LockBarrier::signal($dir, 'created');
        }

        return ['outcome' => 'created'];
    }

    public static function runFirst(string $runId, string $dir): array
    {
        $store = self::boot();
        $me = LockBarrier::connectionId();
        DB::beginTransaction();
        try {
            $query = DB::table('swarm_durable_runs')->where('run_id', $runId);
            (DB::connection()->getDriverName() === 'pgsql' ? $query->lock('for no key update') : $query->lockForUpdate())->first();
            LockBarrier::signal($dir, 'run-held');
            $blocked = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'creator-id'), $me);
            if (! $blocked) {
                throw new RuntimeException('S2 creator did not wait for the run lock.');
            }
            $resumed = $store->resume($runId);
            $status = self::state($runId)['status'];
            DB::commit();

            return ['resumed' => $resumed, 'status' => $status, 'blocked' => $blocked];
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public static function createAfterRunLock(string $runId, string $dir): array
    {
        $store = self::boot();
        LockBarrier::await($dir, 'run-held');
        LockBarrier::signal($dir, 'creator-id', (string) LockBarrier::connectionId());
        $store->createWait($runId, 'late');

        return ['outcome' => 'created'];
    }

    public static function snapshotResume(string $runId, string $dir, string $isolation): array
    {
        self::boot($isolation);
        DB::beginTransaction();
        $generation = DB::table('swarm_durable_runs')->where('run_id', $runId)->value('wait_generation');
        LockBarrier::signal($dir, 'snapshot');
        LockBarrier::await($dir, 'created-closed');
        try {
            $result = app(DurableSwarmManager::class)->resume($runId);
            $status = self::state($runId)['status'];
            DB::commit();

            return ['outcome' => $result->status->value, 'status' => $status, 'snapshot_generation' => (string) $generation];
        } catch (Throwable $e) {
            DB::rollBack();

            return ['outcome' => 'aborted', 'class' => $e::class, 'message' => $e->getMessage()];
        }
    }

    public static function createAndClose(string $runId, string $dir, string $isolation): array
    {
        $store = self::boot($isolation);
        LockBarrier::await($dir, 'snapshot');
        $store->createWait($runId, 'transient');
        $store->releaseWaitWithOutcome($runId, 'transient', 'child_completed', ['status' => 'completed']);
        LockBarrier::signal($dir, 'created-closed');

        return ['outcome' => 'created-closed'];
    }

    public static function checkpoint(string $runId, string $dir): array
    {
        self::boot();
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('G3 checkpoint must have no application transaction.');
        }
        // Avoid the existing MySQL same-second history lease issue.
        FakeResearcher::fake(static function (): string {
            usleep(1_100_000);

            return 'research';
        });
        FakeHierarchicalCoordinator::fake(static function (): array {
            usleep(1_100_000);

            return ['start_at' => 'writer', 'nodes' => [
                'writer' => ['type' => 'worker', 'agent' => FakeWriter::class, 'prompt' => 'write', 'next' => 'finish'],
                'finish' => ['type' => 'finish', 'output_from' => 'writer'],
            ]];
        });
        $manager = app(DurableSwarmManager::class);
        $manager->afterStepCheckpointForTesting(static function (string $id, int $next) use ($dir): void {
            if (DB::connection()->transactionLevel() !== 0) {
                throw new RuntimeException('G3 hook must observe the committed checkpoint.');
            }
            LockBarrier::signal($dir, 'checkpoint', (string) $next);
            LockBarrier::await($dir, 'observed');
        });
        (new AdvanceDurableSwarm($runId, 0))->handle($manager);

        return ['outcome' => 'checkpointed'];
    }

    public static function observeCheckpoint(string $runId, string $dir): array
    {
        $store = self::boot();
        $next = (int) LockBarrier::await($dir, 'checkpoint');
        DB::beginTransaction();
        try {
            $state = self::state($runId);
            $leased = $store->acquireLease($runId, $next, 300) !== null;
        } finally {
            // A red lease probe must not steal the real executor's continuation.
            DB::rollBack();
            LockBarrier::signal($dir, 'observed');
        }

        return $state + ['leased' => $leased, 'next' => $next];
    }

    public static function checkpointAgainstCloser(string $runId, string $dir): array
    {
        $store = self::boot();
        FakeResearcher::fake(static function (): string {
            usleep(1_100_000);

            return 'research';
        });
        $manager = app(DurableSwarmManager::class);
        $blocked = false;
        $manager->beforeStepCheckpointForTesting(static function () use ($store, $runId, $dir, &$blocked): void {
            // Open the closer's wait through the store, not DurableSwarmManager::wait():
            // the manager also syncs history without the step's token, which drops the
            // step's own checkpoint (release finding WAIT-IN-STEP-DROPS-CHECKPOINT).
            $store->createWait($runId, 'legacy');
            $store->beforeSync = static function () use ($dir, &$blocked): void {
                LockBarrier::signal($dir, 'boundary-inserted');
                $blocked = LockBarrier::awaitBlockedBy((int) LockBarrier::await($dir, 'closer-id'), LockBarrier::connectionId());
                if (! $blocked) {
                    throw new RuntimeException('R6 closer did not contend: '.json_encode(LockBarrier::lockWaitSnapshot()));
                }
            };
        });
        try {
            (new AdvanceDurableSwarm($runId, 0))->handle($manager);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e), 'blocked' => $blocked];
        }

        return ['outcome' => 'checkpointed', 'blocked' => $blocked];
    }

    public static function signalCloser(string $runId, string $dir, int $signalId): array
    {
        $store = self::boot();
        LockBarrier::await($dir, 'boundary-inserted');
        LockBarrier::signal($dir, 'closer-id', (string) LockBarrier::connectionId());
        try {
            $closed = $store->releaseWaitWithSignal($runId, 'legacy', $signalId);
        } catch (Throwable $e) {
            return ['outcome' => 'aborted', 'failure' => LockBarrier::failure($e)];
        }

        return ['outcome' => 'closed', 'closed' => $closed];
    }
}
