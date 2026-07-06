<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarm\Testing\InteractsWithSwarmEvents;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithSwarmEvents;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BusServiceProvider::class,
            AiServiceProvider::class,
            SwarmServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->singleton(DispatcherContract::class, fn (Application $app): Dispatcher => new Dispatcher($app));
        $app['config']->set('concurrency.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->resolveTestingDatabaseConfig());
        $app['config']->set('swarm.persistence.driver', 'cache');
        $app['config']->set('swarm.persistence.encrypt_at_rest', false);
        $app['config']->set('swarm.capture.inputs', true);
        $app['config']->set('swarm.capture.outputs', true);
        $app['config']->set('swarm.capture.artifacts', true);
        $app['config']->set('swarm.capture.active_context', true);
        $app['config']->set('queue.connections.durable-test', ['driver' => 'null']);
    }

    /**
     * Build the testbench `testing` connection config.
     *
     * Defaults to in-memory SQLite — the right choice for 99% of the suite.
     * CI lanes that need a real MySQL/Postgres backend (currently only
     * `tests/ProcessConcurrency/AuditOutboxConcurrencyTest.php`, which exercises
     * `FOR UPDATE SKIP LOCKED`) set `DB_CONNECTION=mysql` or `pgsql` along with
     * the usual `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`
     * env vars; this method honors them so the same TestCase boots cleanly
     * against either backend.
     *
     * @return array<string, mixed>
     */
    protected function resolveTestingDatabaseConfig(): array
    {
        $connection = getenv('DB_CONNECTION');

        if ($connection === 'mysql') {
            return [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'laravel_swarm_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];
        }

        if ($connection === 'pgsql') {
            return [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_DATABASE') ?: 'laravel_swarm_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ];
        }

        return [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        Artisan::call('migrate', ['--database' => 'testing']);
    }
}
