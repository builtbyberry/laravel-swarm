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
