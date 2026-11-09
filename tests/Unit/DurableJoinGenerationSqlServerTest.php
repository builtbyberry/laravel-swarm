<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Persistence\DatabaseDurableRunStore;
use BuiltByBerry\LaravelSwarm\Persistence\SwarmPersistenceCipher;
use Illuminate\Database\SqlServerConnection;

test('SQL Server generation observation uses statement-scoped committed reads before wait locks', function (): void {
    $connection = new SqlServerConnection(new PDO('sqlite::memory:'), 'testing', '', ['driver' => 'sqlsrv']);
    $store = new DatabaseDurableRunStore($connection, app('config'), app(SwarmPersistenceCipher::class));
    $queries = $connection->pretend(fn () => $store->releaseWaitingRunForJoin('sql-server-generation', 1));

    expect($queries[0]['query'])->toContain('[wait_generation]', 'with (readcommitted)')
        ->and($queries[1]['query'])->toContain('[swarm_durable_waits]', 'holdlock');
});
