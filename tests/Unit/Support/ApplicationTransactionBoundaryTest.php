<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Support\ApplicationTransactionBoundary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('boundary_rows', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('boundary_rows');
});

test('outside a transaction it only runs the work', function (): void {
    $result = ApplicationTransactionBoundary::run(DB::connection(), fn (): string => 'done');

    expect($result)->toBe('done')
        ->and(DB::transactionLevel())->toBe(0);
});

test('inside a transaction a failure undoes the work and leaves the transaction usable', function (): void {
    DB::beginTransaction();
    DB::table('boundary_rows')->insert(['name' => 'application']);

    expect(fn () => ApplicationTransactionBoundary::run(DB::connection(), function (): void {
        DB::table('boundary_rows')->insert(['name' => 'package']);

        throw new RuntimeException('package work failed');
    }))->toThrow(RuntimeException::class, 'package work failed');

    DB::table('boundary_rows')->insert(['name' => 'application-after']);
    DB::commit();

    expect(DB::table('boundary_rows')->orderBy('id')->pluck('name')->all())->toBe(['application', 'application-after']);
});

test('inside a transaction a success keeps the work and releases its savepoint', function (): void {
    // A PDO that records the statements the boundary issues on it directly.
    $pdo = new class('sqlite::memory:') extends PDO
    {
        /** @var array<int, string> */
        public array $statements = [];

        public function exec(string $statement): int|false
        {
            $this->statements[] = $statement;

            return parent::exec($statement);
        }
    };
    $connection = DB::connection();
    $connection->setPdo($pdo)->setReadPdo($pdo);
    $connection->getSchemaBuilder()->create('boundary_rows', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    try {
        $connection->beginTransaction();

        foreach (range(1, 3) as $index) {
            ApplicationTransactionBoundary::run($connection, fn () => $connection->table('boundary_rows')->insert(['name' => 'package-'.$index]));
        }

        $connection->commit();

        $savepoints = array_values(array_filter($pdo->statements, fn (string $statement): bool => str_starts_with($statement, 'SAVEPOINT swarm_boundary_')));
        $released = array_values(array_filter($pdo->statements, fn (string $statement): bool => str_starts_with($statement, 'RELEASE SAVEPOINT swarm_boundary_')));

        expect($connection->table('boundary_rows')->count())->toBe(3)
            ->and($savepoints)->toHaveCount(3)
            ->and($released)->toBe(array_map(fn (string $statement): string => 'RELEASE '.$statement, $savepoints));
    } finally {
        DB::purge();
    }
});

test('when the database already ended the transaction, the rollback failure is reported and the original error rethrown', function (): void {
    DB::beginTransaction();
    $pdo = DB::connection()->getPdo();
    $reported = null;
    $original = new RuntimeException('deadlock');
    $escaped = null;

    try {
        ApplicationTransactionBoundary::run(DB::connection(), function () use ($pdo, $original): void {
            // What a MySQL deadlock does: the whole transaction is rolled back underneath,
            // so the boundary's savepoint no longer exists.
            $pdo->rollBack();

            throw $original;
        }, function (Throwable $rollbackFailure) use (&$reported): void {
            $reported = $rollbackFailure;
        });
    } catch (Throwable $e) {
        $escaped = $e;
    }

    expect($escaped)->toBe($original);
    expect($reported)->toBeInstanceOf(Throwable::class);

    // Hand Laravel back the open transaction its counter still expects.
    $pdo->beginTransaction();
    DB::rollBack();
});
