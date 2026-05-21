<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Installer\Fixtures;

use BuiltByBerry\LaravelSwarm\Commands\Install\InstallCommand;

/**
 * Test-only InstallCommand subclass that pretends Laravel Pulse is not
 * installed, even when laravel/pulse is on the test runner's classpath.
 *
 * Used to assert the "swarm:install silently skips swarm:install:pulse when
 * Pulse is not installed" contract without mucking with the autoloader.
 * Lives in `tests/Installer/Fixtures/` so other test files can reuse the
 * same name without colliding on a top-level class declaration.
 */
final class PulseAbsentInstallCommand extends InstallCommand
{
    protected function pulseIsInstalled(): bool
    {
        return false;
    }
}
