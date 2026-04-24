<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;

return [
    'topology' => env('SWARM_TOPOLOGY', Topology::Sequential->value),

    // Best-effort orchestration deadline checked before and between swarm steps.
    // This does not hard-cancel an in-flight provider call.
    'timeout' => (int) env('SWARM_TIMEOUT', 300),

    'max_agent_steps' => (int) env('SWARM_MAX_AGENT_STEPS', 10),

    'persistence' => [
        'driver' => env('SWARM_PERSISTENCE_DRIVER', 'cache'),
    ],

    'context' => [
        'driver' => env('SWARM_CONTEXT_DRIVER'),
        'ttl' => (int) env('SWARM_CONTEXT_TTL', 3600),
        'store' => env('SWARM_CONTEXT_STORE'),
        'prefix' => env('SWARM_CONTEXT_PREFIX', 'swarm:context:'),
    ],

    'artifacts' => [
        'driver' => env('SWARM_ARTIFACTS_DRIVER'),
        'store' => env('SWARM_ARTIFACTS_STORE'),
        'prefix' => env('SWARM_ARTIFACTS_PREFIX', 'swarm:artifacts:'),
    ],

    'history' => [
        'driver' => env('SWARM_HISTORY_DRIVER'),
        'store' => env('SWARM_HISTORY_STORE'),
        'prefix' => env('SWARM_HISTORY_PREFIX', 'swarm:history:'),
        'index_prefix' => env('SWARM_HISTORY_INDEX_PREFIX', 'swarm:index:'),
        'latest_prefix' => env('SWARM_HISTORY_LATEST_PREFIX', 'swarm:index:latest'),
    ],

    'queue' => [
        'connection' => env('SWARM_QUEUE_CONNECTION'),
        'name' => env('SWARM_QUEUE'),
    ],

    // These table names are honored by the database repositories at runtime.
    // If you change them, publish and update the package migrations as well.
    'tables' => [
        'contexts' => env('SWARM_CONTEXTS_TABLE', 'swarm_contexts'),
        'artifacts' => env('SWARM_ARTIFACTS_TABLE', 'swarm_artifacts'),
        'history' => env('SWARM_RUN_HISTORIES_TABLE', 'swarm_run_histories'),
    ],
];
