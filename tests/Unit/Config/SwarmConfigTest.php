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

test('terminal callback enabled parses common boolean environment values', function (string $value, bool $expected) {
    $config = withEnvironment([
        'SWARM_CALLBACKS_ENABLED' => $value,
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($config['callbacks']['enabled'])->toBe($expected);
})->with([
    'off' => ['off', false],
    'no' => ['no', false],
    'false' => ['false', false],
    'zero' => ['0', false],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'true' => ['true', true],
    'one' => ['1', true],
]);

test('terminal callback delivery settings use their exact environment variables and bounded defaults', function () {
    $configured = withEnvironment([
        'SWARM_CALLBACKS_QUEUE_CONNECTION' => 'callbacks-redis',
        'SWARM_CALLBACKS_QUEUE' => 'terminal-callbacks',
        'SWARM_CALLBACKS_RETRY_BACKOFF_SECONDS' => '75',
        'SWARM_CALLBACKS_STALE_WARNING_THRESHOLD_SECONDS' => '125',
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    $defaults = withEnvironment([
        'SWARM_CALLBACKS_QUEUE_CONNECTION' => null,
        'SWARM_CALLBACKS_QUEUE' => null,
        'SWARM_CALLBACKS_RETRY_BACKOFF_SECONDS' => null,
        'SWARM_CALLBACKS_STALE_WARNING_THRESHOLD_SECONDS' => null,
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    $bounded = withEnvironment([
        'SWARM_CALLBACKS_RETRY_BACKOFF_SECONDS' => '-30',
        'SWARM_CALLBACKS_STALE_WARNING_THRESHOLD_SECONDS' => '-60',
    ], fn (): array => require __DIR__.'/../../../config/swarm.php');

    expect($configured['callbacks']['queue'])->toBe([
        'connection' => 'callbacks-redis',
        'name' => 'terminal-callbacks',
    ])->and($configured['callbacks']['retry_backoff_seconds'])->toBe(75)
        ->and($configured['callbacks']['stale_warning_threshold_seconds'])->toBe(125)
        ->and($defaults['callbacks']['queue'])->toBe(['connection' => null, 'name' => null])
        ->and($defaults['callbacks']['retry_backoff_seconds'])->toBe(60)
        ->and($defaults['callbacks']['stale_warning_threshold_seconds'])->toBe(0)
        ->and($bounded['callbacks']['retry_backoff_seconds'])->toBe(0)
        ->and($bounded['callbacks']['stale_warning_threshold_seconds'])->toBe(0);
});

test('terminal callback documentation states the bounded delivery and shutdown guarantees', function () {
    $docs = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../../../docs/error-handling.md'));
    $publicSurface = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../../../docs/public-surface.md'));
    $executionModes = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../../../docs/execution-modes.md'));
    $registrationTrait = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../../../src/Responses/Concerns/RegistersTerminalCallbacks.php'));
    $config = preg_replace('/\s+/', ' ', (string) file_get_contents(__DIR__.'/../../../config/swarm.php'));

    expect($docs)->toContain('armed once for a settled completion')
        ->and($docs)->toContain('armed once for a settled failure')
        ->and($docs)->toContain('A delivery already past that check continues')
        ->and($docs)->toContain('signed when audit signing is configured')
        ->and($docs)->toContain('foreign payload objects and unsigned closure bodies are never constructed or invoked')
        ->and($docs)->toContain('one indexed existence check')
        ->and($docs)->toContain('leaves the row `delivering` until its lease expires')
        ->and($docs)->toContain("reported as their original `Throwable` through Laravel's application exception handler")
        ->and($publicSurface)->toContain('counted when a delivery job acquires the callback')
        ->and($executionModes)->toContain('`then` is armed once on a settled completion')
        ->and($executionModes)->toContain('delivered at least once')
        ->and($registrationTrait)->toContain('A `then` callback is armed once')
        ->and($registrationTrait)->toContain('Either is delivered at least once')
        ->and($config)->toContain('one indexed existence check')
        ->and($config)->toContain('A delivery already past the kill-switch check may complete');
});
