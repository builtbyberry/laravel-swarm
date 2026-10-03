<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents\RuntimeConfiguredParallelStreamAgent;

pest()->group('process-concurrency');

test('ad-hoc parallel live streams fail before silently reconstructing runtime-configured agents', function (): void {
    config()->set('concurrency.default', 'process');
    config()->set('swarm.streaming.parallel.enabled', true);
    config()->set('swarm.native_inputs.enabled', true);
    config()->set('swarm.native_agent_settings.enabled', true);

    $path = sys_get_temp_dir().'/laravel-swarm-native-settings-stream-'.getmypid().'-'.bin2hex(random_bytes(4));

    try {
        expect(fn () => iterator_to_array(
            app(SwarmRunner::class)
                ->parallel([new RuntimeConfiguredParallelStreamAgent('runtime-only')])
                ->stream($path),
            false,
        ))->toThrow(SwarmException::class, 'live instance state cannot be preserved');

        expect(file_exists($path.'.provider-invoked'))->toBeFalse();
    } finally {
        @unlink($path.'.provider-invoked');
    }
});
