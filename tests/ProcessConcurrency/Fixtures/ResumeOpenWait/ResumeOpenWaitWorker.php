<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\ProcessConcurrency\Fixtures\ResumeOpenWait;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Laravel\Ai\AiServiceProvider;

/**
 * Child-process bootstrap for DurableResumeOpenWaitTest's workers. A worker runs
 * `php artisan invoke-serialized-closure` against bare testbench, so the package
 * configuration the parent test case sets is repeated here; it is a class (not a
 * function in the test file) because the test file is not loaded in the child.
 */
final class ResumeOpenWaitWorker
{
    public static function boot(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('swarm.persistence.driver', 'database');
        config()->set('swarm.persistence.encrypt_at_rest', false);
        config()->set('swarm.capture.inputs', true);
        config()->set('swarm.capture.outputs', true);
        config()->set('swarm.capture.artifacts', true);
        config()->set('swarm.capture.active_context', true);
        config()->set('queue.connections.durable-test', ['driver' => 'null']);
        config()->set('swarm.durable.queue.connection', 'durable-test');

        foreach ([BusServiceProvider::class, AiServiceProvider::class, SwarmServiceProvider::class] as $provider) {
            if (! app()->providerIsLoaded($provider)) {
                app()->register($provider);
            }
        }
    }
}
