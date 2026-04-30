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

    'streaming' => [
        'replay' => [
            'enabled' => env('SWARM_STREAM_REPLAY_ENABLED', false),
            'driver' => env('SWARM_STREAM_REPLAY_DRIVER'),
            'failure_policy' => env('SWARM_STREAM_REPLAY_FAILURE_POLICY', 'fail'),
            'store' => env('SWARM_STREAM_REPLAY_STORE'),
            'prefix' => env('SWARM_STREAM_REPLAY_PREFIX', 'swarm:stream:'),
        ],
    ],

    'queue' => [
        'connection' => env('SWARM_QUEUE_CONNECTION'),
        'name' => env('SWARM_QUEUE'),
        /*
         * Hierarchical swarms dispatched with queue() can coordinate parallel route nodes across workers
         * when coordination is multi_worker (requires database-backed persistence and durable tables).
         */
        'hierarchical_parallel' => [
            'coordination' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_COORDINATION', 'in_process'),
            'connection' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_CONNECTION'),
            'name' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_NAME'),
            'branch' => [
                'connection' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_CONNECTION'),
                'name' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_NAME'),
            ],
            'resume' => [
                'connection' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_CONNECTION'),
                'name' => env('SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_NAME'),
            ],
        ],
    ],

    'durable' => [
        'step_timeout' => (int) env('SWARM_DURABLE_STEP_TIMEOUT', 300),
        'parallel' => [
            'failure_policy' => env('SWARM_DURABLE_PARALLEL_FAILURE_POLICY', 'collect_failures'),
            'queue' => [
                'connection' => env('SWARM_DURABLE_PARALLEL_QUEUE_CONNECTION'),
                'name' => env('SWARM_DURABLE_PARALLEL_QUEUE'),
            ],
        ],
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
        'stream_events' => env('SWARM_STREAM_EVENTS_TABLE', 'swarm_stream_events'),
        'durable' => env('SWARM_DURABLE_RUNS_TABLE', 'swarm_durable_runs'),
        'durable_node_outputs' => env('SWARM_DURABLE_NODE_OUTPUTS_TABLE', 'swarm_durable_node_outputs'),
        'durable_branches' => env('SWARM_DURABLE_BRANCHES_TABLE', 'swarm_durable_branches'),
    ],
];
