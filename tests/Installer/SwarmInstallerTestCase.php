<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarmInstallerTestkit\InstallerTestCase;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;

/**
 * Swarm-specific specialization of the shared installer-test harness.
 *
 * Supplies the service providers `swarm:install*` command tests need to
 * boot. See `builtbyberry/laravel-swarm-installer-testkit`'s own README for
 * the harness mechanics (skeleton materialization, assertions, idempotency
 * helper).
 */
abstract class SwarmInstallerTestCase extends InstallerTestCase
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
}
