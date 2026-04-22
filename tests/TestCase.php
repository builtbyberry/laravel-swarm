<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('swarm.persistence.driver', 'cache');
    }

    protected function defineDatabaseMigrations(): void
    {
        Artisan::call('migrate', ['--database' => 'testing']);
    }
}
