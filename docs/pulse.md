# Pulse

Laravel Swarm integrates with [Laravel Pulse](https://laravel.com/docs/pulse) through optional recorders and cards. If your application already uses Pulse, you can add swarm-level observability without changing how your swarms run.

## Install Pulse

Install and publish Pulse in your application first:

```bash
composer require laravel/pulse

php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"

php artisan migrate
```

Once Pulse is installed, Laravel Swarm will register its Pulse cards automatically.

## Register The Recorders

Add the swarm recorders to the `recorders` array in `config/pulse.php`:

```php
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmStepDurations;

'recorders' => [
    // ...

    SwarmRuns::class => [
        'enabled' => env('PULSE_SWARM_RUNS_ENABLED', true),
    ],

    SwarmStepDurations::class => [
        'enabled' => env('PULSE_SWARM_STEP_DURATIONS_ENABLED', true),
    ],
],
```

`SwarmRuns` records run totals, failures, failure rate inputs, topology usage, and average run duration. `SwarmStepDurations` records average step duration by swarm, topology, and agent.

Pulse is the aggregate observability layer. If your browser needs a live
operations feed for individual runs, listen to Laravel Swarm lifecycle events
and broadcast your own application event.

See [Operations Dashboard](../examples/operations-dashboard/README.md) for that
run-level pattern.

## Add The Cards

Publish Pulse's dashboard view if you have not already:

```bash
php artisan vendor:publish --tag=pulse-dashboard
```

Then add the swarm cards to `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:swarm.runs cols="6" />
<livewire:swarm.steps cols="6" />
```

`<livewire:swarm.runs />` shows per-swarm totals, failures, failure rate, average run duration, and topology mix. `<livewire:swarm.steps />` shows the slowest average swarm steps by agent.
