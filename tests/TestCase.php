<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests;

use BuiltByBerry\LaravelSwarm\Events\SwarmCancelled;
use BuiltByBerry\LaravelSwarm\Events\SwarmCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmFailed;
use BuiltByBerry\LaravelSwarm\Events\SwarmPaused;
use BuiltByBerry\LaravelSwarm\Events\SwarmResumed;
use BuiltByBerry\LaravelSwarm\Events\SwarmStarted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepCompleted;
use BuiltByBerry\LaravelSwarm\Events\SwarmStepStarted;
use BuiltByBerry\LaravelSwarm\Support\SwarmEventRecorder;
use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\AiServiceProvider;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $recorder = $this->app->make(SwarmEventRecorder::class);
        $recorder->resetRecorder();
        $recorder->activate();

        $events = $this->app->make(EventDispatcher::class);

        foreach ([
            SwarmStarted::class,
            SwarmStepStarted::class,
            SwarmStepCompleted::class,
            SwarmCompleted::class,
            SwarmFailed::class,
            SwarmPaused::class,
            SwarmResumed::class,
            SwarmCancelled::class,
        ] as $eventClass) {
            $events->listen($eventClass, fn (object $event) => $recorder->record($event));
        }
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [
            BusServiceProvider::class,
            AiServiceProvider::class,
            SwarmServiceProvider::class,
        ];

        if (class_exists(Pulse::class)) {
            $providers[] = LivewireServiceProvider::class;
            $providers[] = PulseServiceProvider::class;
        }

        return $providers;
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
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('pulse.enabled', true);
        $app['config']->set('pulse.storage.database.connection', 'testing');
        $app['config']->set('pulse.ingest.driver', 'storage');
        $app['config']->set('swarm.persistence.driver', 'cache');
    }

    protected function defineDatabaseMigrations(): void
    {
        Artisan::call('migrate', ['--database' => 'testing']);

        if (class_exists(Pulse::class)) {
            Artisan::call('migrate', [
                '--database' => 'testing',
                '--path' => __DIR__.'/../vendor/laravel/pulse/database/migrations',
                '--realpath' => true,
            ]);
        }
    }
}
