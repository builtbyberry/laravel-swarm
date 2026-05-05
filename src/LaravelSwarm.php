<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm;

/**
 * Static helper surface for Laravel Swarm.
 *
 * Mirrors the first-party Laravel package convention used by Cashier,
 * Sanctum, Passport, Horizon, and Telescope. Use this class for package-
 * level toggles and overrides that do not fit cleanly in config/swarm.php
 * (e.g. opt-outs of service-provider behavior, custom model bindings, or
 * UI auth callbacks if a first-party dashboard ships later).
 *
 * Configuration that is naturally env-driven should stay in config/swarm.php
 * to avoid splitting the configuration surface across two locations.
 */
final class LaravelSwarm
{
    /**
     * Indicates if Laravel Swarm should autoload its package migrations.
     */
    public static bool $runsMigrations = true;

    /**
     * Configure Laravel Swarm to not register its migrations.
     */
    public static function ignoreMigrations(): static
    {
        self::$runsMigrations = false;

        return new self;
    }
}
