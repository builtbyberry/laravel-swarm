<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\DurableOutbox;
use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableBranchCoordinator;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableEagerDispatcher;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableJoinRelease;
use BuiltByBerry\LaravelSwarm\Runners\Durable\DurableRunContext;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/**
 * DurableJoinRelease::releaseAfterCommit() — when the join release attempt runs
 * relative to the swarm connection's transactions, and that a failing attempt never
 * escapes into the caller whose transaction already committed.
 */

/**
 * A DurableJoinRelease whose release attempt is recorded (or throws) instead of
 * touching any run.
 */
function recordingJoinRelease(?Illuminate\Contracts\Container\Container $container = null, bool $throw = false): DurableJoinRelease
{
    $store = Mockery::mock(DurableRunStore::class);
    $store->shouldReceive('find')->andReturnUsing(fn (string $runId): array => ['run_id' => $runId, 'status' => 'waiting']);

    return new class($store, Mockery::mock(DurableRunContext::class), Mockery::mock(DurableBranchCoordinator::class), Mockery::mock(DurableOutbox::class), Mockery::mock(DurableEagerDispatcher::class), DB::connection(), $container ?? app(), $throw) extends DurableJoinRelease
    {
        /** @var list<string> */
        public array $attempts = [];

        public function __construct($store, $runs, $branches, $outbox, $eager, $connection, $container, private bool $throw)
        {
            parent::__construct($store, $runs, $branches, $outbox, $eager, $connection, $container);
        }

        public function releaseIfReady(array $run): bool
        {
            if ($this->throw) {
                throw new RuntimeException('release failed');
            }

            $this->attempts[] = (string) $run['run_id'];

            return true;
        }
    };
}

test('with no transaction open the attempt runs at once', function () {
    $release = recordingJoinRelease();

    $release->releaseAfterCommit('run-a');

    expect($release->attempts)->toBe(['run-a']);
});

test('inside nested transactions the attempt runs once, after the outermost commit', function () {
    $release = recordingJoinRelease();

    DB::transaction(function () use ($release): void {
        DB::transaction(function () use ($release): void {
            $release->releaseAfterCommit('run-a');
        });

        expect($release->attempts)->toBe([]);
    });

    expect($release->attempts)->toBe(['run-a']);
});

test('a savepoint rollback or an outer rollback discards the attempt', function () {
    $release = recordingJoinRelease();

    DB::transaction(function () use ($release): void {
        try {
            DB::transaction(function () use ($release): void {
                $release->releaseAfterCommit('rolled-back-savepoint');

                throw new RuntimeException('savepoint');
            });
        } catch (RuntimeException) {
        }
    });

    try {
        DB::transaction(function () use ($release): void {
            $release->releaseAfterCommit('rolled-back-outer');

            throw new RuntimeException('outer');
        });
    } catch (RuntimeException) {
    }

    expect($release->attempts)->toBe([]);
});

test('a commit on another connection does not run the attempt', function () {
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $release = recordingJoinRelease();

    DB::transaction(function () use ($release): void {
        DB::connection('other')->transaction(function () use ($release): void {
            $release->releaseAfterCommit('run-a');
        });

        expect($release->attempts)->toBe([]);
    });

    expect($release->attempts)->toBe(['run-a']);
});

test('without a transaction manager tracking the swarm connection nothing is attached', function () {
    $release = recordingJoinRelease(new Container);

    DB::transaction(function () use ($release): void {
        $release->releaseAfterCommit('run-a');
    });

    expect($release->attempts)->toBe([]);
});

test('a failing attempt is reported, does not escape the commit, and later callbacks still run', function () {
    Exceptions::fake();
    $release = recordingJoinRelease(throw: true);
    $laterCallbackRan = false;

    DB::transaction(function () use ($release, &$laterCallbackRan): void {
        $release->releaseAfterCommit('run-a');
        DB::afterCommit(function () use (&$laterCallbackRan): void {
            $laterCallbackRan = true;
        });
    });

    expect($laterCallbackRan)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Durable run [run-a] could not be released to its join')
        && $e->getPrevious()?->getMessage() === 'release failed');
});
