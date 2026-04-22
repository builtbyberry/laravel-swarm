<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;

return [
    'topology' => env('SWARM_TOPOLOGY', Topology::Sequential->value),

    'timeout' => (int) env('SWARM_TIMEOUT', 300),

    'max_agent_steps' => (int) env('SWARM_MAX_AGENT_STEPS', 10),

    'context' => [
        'driver' => env('SWARM_CONTEXT_DRIVER', 'cache'),
        'ttl' => (int) env('SWARM_CONTEXT_TTL', 3600),
        'store' => env('SWARM_CONTEXT_STORE'),
        'prefix' => env('SWARM_CONTEXT_PREFIX', 'swarm:context:'),
    ],

    'artifacts' => [
        'driver' => env('SWARM_ARTIFACTS_DRIVER', 'cache'),
        'store' => env('SWARM_ARTIFACTS_STORE'),
        'prefix' => env('SWARM_ARTIFACTS_PREFIX', 'swarm:artifacts:'),
    ],

    'history' => [
        'driver' => env('SWARM_HISTORY_DRIVER', 'cache'),
        'store' => env('SWARM_HISTORY_STORE'),
        'prefix' => env('SWARM_HISTORY_PREFIX', 'swarm:history:'),
    ],

    'queue' => [
        'connection' => env('SWARM_QUEUE_CONNECTION'),
        'name' => env('SWARM_QUEUE'),
    ],
];
