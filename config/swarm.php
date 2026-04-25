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

    'capture' => [
        'inputs' => env('SWARM_CAPTURE_INPUTS', true),
        'outputs' => env('SWARM_CAPTURE_OUTPUTS', true),
        'artifacts' => env('SWARM_CAPTURE_ARTIFACTS', true),
        'active_context' => env('SWARM_CAPTURE_ACTIVE_CONTEXT', true),
    ],

    'limits' => [
        'max_input_bytes' => env('SWARM_MAX_INPUT_BYTES'),
        'max_output_bytes' => env('SWARM_MAX_OUTPUT_BYTES'),
        'overflow' => env('SWARM_LIMIT_OVERFLOW', 'fail'),
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

    'durable' => [
        'step_timeout' => (int) env('SWARM_DURABLE_STEP_TIMEOUT', 300),
        'queue' => [
            'connection' => env('SWARM_DURABLE_QUEUE_CONNECTION'),
            'name' => env('SWARM_DURABLE_QUEUE'),
        ],
        'recovery' => [
            'grace_seconds' => (int) env('SWARM_DURABLE_RECOVERY_GRACE_SECONDS', 300),
        ],
    ],

    // These table names are honored by the database repositories at runtime.
    // If you change them, publish and update the package migrations as well.
    'tables' => [
        'contexts' => env('SWARM_CONTEXTS_TABLE', 'swarm_contexts'),
        'artifacts' => env('SWARM_ARTIFACTS_TABLE', 'swarm_artifacts'),
        'history' => env('SWARM_RUN_HISTORIES_TABLE', 'swarm_run_histories'),
        'history_steps' => env('SWARM_RUN_HISTORY_STEPS_TABLE', 'swarm_run_steps'),
        'durable' => env('SWARM_DURABLE_RUNS_TABLE', 'swarm_durable_runs'),
    ],
];
