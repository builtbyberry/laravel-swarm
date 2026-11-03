<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\FanoutCloser;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\LockBarrier;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\ResumeOpenWaitWorker;
use Illuminate\Support\Facades\DB;

/**
 * Child-process bootstrap for DurableFanoutCloserLockOrderTest's workers: the package
 * configuration ResumeOpenWaitWorker repeats, and a hooked store the worker's manager
 * uses.
 */
final class FanoutCloserWorker
{
    public static function bootHooked(): HookedFanoutCloserDurableRunStore
    {
        ResumeOpenWaitWorker::boot();
        config()->set('queue.default', 'null');
        $store = app(HookedFanoutCloserDurableRunStore::class);
        app()->instance(DurableRunStore::class, $store);
        app()->forgetInstance(DurableSwarmManager::class);

        return $store;
    }

    /**
     * Marks the closer's first ask for the run row — the run lock helper, or
     * syncRunWaitSummary() when that comes first — with this worker's connection id.
     */
    public static function markRunLock(HookedFanoutCloserDurableRunStore $store, string $dir): void
    {
        $me = (string) LockBarrier::connectionId();
        $mark = static function () use ($dir, $me): void {
            if (! is_file($dir.'/closer-at-run-lock')) {
                LockBarrier::signal($dir, 'closer-at-run-lock', $me);
            }
        };
        $store->beforeRunLock = $mark;
        $store->beforeSync = $mark;
    }

    /**
     * The table the given connection is waiting to lock, from the engine's lock-wait view
     * (MySQL: the lock's table; Postgres: the waiting statement's text).
     */
    public static function waitingOn(int $waiter): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (string) DB::selectOne('select query from pg_stat_activity where pid = ?', [$waiter])?->query;
        }

        return (string) DB::selectOne(
            'select l.object_name from performance_schema.data_lock_waits w '
            .'join performance_schema.data_locks l on l.engine_lock_id = w.requesting_engine_lock_id '
            .'join performance_schema.threads rt on rt.thread_id = w.requesting_thread_id '
            .'where rt.processlist_id = ?',
            [$waiter],
        )?->object_name;
    }
}
