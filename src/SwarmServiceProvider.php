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
use BuiltByBerry\LaravelSwarm\Runners\HierarchicalRunner;
use BuiltByBerry\LaravelSwarm\Runners\ParallelRunner;
use BuiltByBerry\LaravelSwarm\Runners\SequentialRunner;
use BuiltByBerry\LaravelSwarm\Runners\SwarmRunner;
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

        $this->app->singleton(ContextStore::class, CacheContextStore::class);
        $this->app->singleton(ArtifactRepository::class, CacheArtifactRepository::class);
        $this->app->singleton(RunHistoryStore::class, CacheRunHistoryStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/swarm.php' => config_path('swarm.php'),
            ], 'swarm-config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs'),
            ], 'swarm-stubs');

            $this->commands([
                MakeSwarmCommand::class,
            ]);
        }
    }
}
