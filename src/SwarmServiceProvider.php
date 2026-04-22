<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm;

use BuiltByBerry\LaravelSwarm\Commands\MakeSwarmCommand;
use BuiltByBerry\LaravelSwarm\Contracts\ArtifactRepository;
use BuiltByBerry\LaravelSwarm\Contracts\ContextStore;
use BuiltByBerry\LaravelSwarm\Contracts\RunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\CacheContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\CacheRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseArtifactRepository;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseContextStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\ParallelRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class SwarmServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/swarm.php',
            'swarm',
        );

        $this->app->singleton(SequentialRunner::class);

        $this->app->singleton(ParallelRunner::class);

        $this->app->singleton(HierarchicalRunner::class);

        $this->app->singleton(SwarmRunner::class);

        $this->app->singleton(ContextStore::class, fn (Application $app): ContextStore => $this->resolvePersistenceStore(
            $app,
            'context',
            CacheContextStore::class,
            DatabaseContextStore::class,
        ));
        $this->app->singleton(ArtifactRepository::class, fn (Application $app): ArtifactRepository => $this->resolvePersistenceStore(
            $app,
            'artifacts',
            CacheArtifactRepository::class,
            DatabaseArtifactRepository::class,
        ));
        $this->app->singleton(RunHistoryStore::class, fn (Application $app): RunHistoryStore => $this->resolvePersistenceStore(
            $app,
            'history',
            CacheRunHistoryStore::class,
            DatabaseRunHistoryStore::class,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/swarm.php' => config_path('swarm.php'),
            ], 'swarm-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'swarm-migrations');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs'),
            ], 'swarm-stubs');

            $this->commands([
                MakeSwarmCommand::class,
            ]);
        }
    }

    /**
     * @template TStore of object
     *
     * @param  class-string<TStore>  $cacheStore
     * @param  class-string<TStore>  $databaseStore
     * @return TStore
     */
    protected function resolvePersistenceStore(Application $app, string $configKey, string $cacheStore, string $databaseStore): object
    {
        $config = $app->make(ConfigRepository::class);
        $driver = $config->get("swarm.{$configKey}.driver")
            ?? $config->get('swarm.persistence.driver')
            ?? 'cache';

        return $app->make($driver === 'database' ? $databaseStore : $cacheStore);
    }
}
