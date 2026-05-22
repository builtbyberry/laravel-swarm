<?php

declare(strict_types=1);

function withEnvironment(array $values, callable $callback): mixed
{
    $original = [];

    foreach ($values as $key => $value) {
        $original[$key] = getenv($key);

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

test('encrypt at rest defaults on when a per store database driver override is configured', function () {
    $config = withEnvironment([
        'SWARM_PERSISTENCE_DRIVER' => 'cache',
        'SWARM_CONTEXT_DRIVER' => 'database',
        'SWARM_ARTIFACTS_DRIVER' => null,
        'SWARM_HISTORY_DRIVER' => null,
        'SWARM_STREAM_REPLAY_DRIVER' => null,
        'SWARM_ENCRYPT_AT_REST' => null,
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($config['persistence']['encrypt_at_rest'])->toBeTrue();
});

test('memory_snapshots table key is published with the canonical default', function () {
    $config = withEnvironment([
        'SWARM_MEMORY_SNAPSHOTS_TABLE' => null,
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($config['tables'])->toHaveKey('memory_snapshots')
        ->and($config['tables']['memory_snapshots'])->toBe('swarm_memory_snapshots');
});

test('memory_snapshots table name honors SWARM_MEMORY_SNAPSHOTS_TABLE override', function () {
    $config = withEnvironment([
        'SWARM_MEMORY_SNAPSHOTS_TABLE' => 'tenant42_swarm_memory_snapshots',
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($config['tables']['memory_snapshots'])->toBe('tenant42_swarm_memory_snapshots');
});

test('memories table name honors SWARM_MEMORIES_TABLE override', function () {
    $config = withEnvironment([
        'SWARM_MEMORIES_TABLE' => 'tenant42_swarm_memories',
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($config['tables']['memories'])->toBe('tenant42_swarm_memories');
});
