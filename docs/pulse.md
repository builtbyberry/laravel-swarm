# Pulse

The [Laravel Pulse](https://laravel.com/docs/pulse) integration — recorders and dashboard cards for swarm runs, step durations, memory growth, and audit outbox observability — has moved to a companion package as of v0.17.1:

[`builtbyberry/laravel-swarm-pulse`](https://github.com/builtbyberry/laravel-swarm-pulse)

## Installation

```bash
composer require builtbyberry/laravel-swarm-pulse

composer require laravel/pulse

php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"

php artisan migrate

php artisan swarm:install:pulse
```

See the companion package's [README](https://github.com/builtbyberry/laravel-swarm-pulse#readme) for the full setup, card reference, and troubleshooting guide — behavior is unchanged from the pre-extraction integration, only the namespace (`BuiltByBerry\LaravelSwarmPulse\*`) and package boundary moved.

If you were referencing `BuiltByBerry\LaravelSwarm\Pulse\*` classes directly before v0.17.1, see [UPGRADING.md](../UPGRADING.md) for the migration steps.
