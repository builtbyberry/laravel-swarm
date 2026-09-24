<?php

use BuiltByBerry\LaravelSwarmPulse\Recorders\SwarmRuns;
use BuiltByBerry\LaravelSwarmPulse\Recorders\SwarmStepDurations;

return array_replace_recursive(require base_path('vendor/laravel/pulse/config/pulse.php'), [
    'enabled' => true,
    'storage' => ['database' => ['connection' => 'sqlite']],
    'ingest' => ['driver' => 'storage'],
    'recorders' => [
        SwarmRuns::class => ['enabled' => true],
        SwarmStepDurations::class => ['enabled' => true],
    ],
]);
