<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\JoinOpenWait;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait\ResumeOpenWaitWorker;

/**
 * Child-process bootstrap for DurableJoinOpenWaitTest's workers: the package
 * configuration ResumeOpenWaitWorker repeats, plus the queued-hierarchical
 * multi_worker settings, and optionally a hooked store the worker's manager uses.
 */
final class JoinOpenWaitWorker
{
    public static function boot(): void
    {
        ResumeOpenWaitWorker::boot();
        config()->set('queue.default', 'null');
        config()->set('swarm.queue.hierarchical_parallel.coordination', 'multi_worker');
    }

    /**
     * Boot, then make the worker's manager use a hooked store.
     */
    public static function bootHooked(): HookedJoinDurableRunStore
    {
        self::boot();
        $store = app(HookedJoinDurableRunStore::class);
        app()->instance(DurableRunStore::class, $store);
        app()->forgetInstance(DurableSwarmManager::class);

        return $store;
    }
}
