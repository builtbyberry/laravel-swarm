<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;

$swarmPersistenceDriver = env('SWARM_PERSISTENCE_DRIVER', 'cache');

return [
    'topology' => env('SWARM_TOPOLOGY', Topology::Sequential->value),

    // Best-effort orchestration deadline checked before and between swarm steps.
    // This does not hard-cancel an in-flight provider call.
    'timeout' => (int) env('SWARM_TIMEOUT', 300),

    'max_agent_steps' => (int) env('SWARM_MAX_AGENT_STEPS', 10),

    /*
     * When true, swarm:prune skips destructive deletes (scheduled pruning no-ops).
     * Use for regulated deployments that manage retention outside the package.
     * Dry-run (--dry-run) still reports counts when this is enabled.
     */
    'retention' => [
        'prevent_prune' => filter_var(env('SWARM_PREVENT_PRUNE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'persistence' => [
        'driver' => $swarmPersistenceDriver,
        /*
         * When the persistence driver is database, sensitive string columns (prompts,
         * agent outputs, branch I/O, etc.) are sealed with Laravel's encrypter (APP_KEY).
         * Override with SWARM_ENCRYPT_AT_REST=false only when you rely solely on database-level encryption.
         */
        'encrypt_at_rest' => filter_var(
            env('SWARM_ENCRYPT_AT_REST', $swarmPersistenceDriver === 'database'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
     * Capture controls what is persisted into history, context, and response payloads.
     * Defaults are conservative: opt in when you want full prompts and outputs stored.
     */
    'capture' => [
        'inputs' => env('SWARM_CAPTURE_INPUTS', false),
        'outputs' => env('SWARM_CAPTURE_OUTPUTS', false),
        'artifacts' => env('SWARM_CAPTURE_ARTIFACTS', false),
        'active_context' => env('SWARM_CAPTURE_ACTIVE_CONTEXT', false),
    ],

    /*
     * Audit evidence routing. Bind SwarmAuditSink in your service container to route
     * package-owned audit evidence to an append-only store, SIEM export, or queue listener.
     * The default binding (NoOpSwarmAuditSink) discards all evidence.
     *
     * failure_policy controls what happens when the sink throws an exception:
     *   swallow — silently discard (default, safest for production).
     *   log     — record via application logger, then continue.
     *
     * Sink failures never propagate into swarm execution regardless of policy.
     */
    'audit' => [
        'failure_policy' => env('SWARM_AUDIT_FAILURE_POLICY', 'swallow'),
        'metadata_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SWARM_AUDIT_METADATA_ALLOWLIST', '')),
        ))),
    ],

    /*
     * Observability telemetry routing. Bind SwarmTelemetrySink to export structured
     * correlation payloads to logs, metrics, or tracing adapters. The default binding
     * (NoOpSwarmTelemetrySink) discards all records.
     *
     * listen_to_events: when false, lifecycle and package queue job telemetry is not
     * subscribed; stream.event / broadcast.event direct hooks still respect "enabled".
     *
     * failure_policy: swallow | log — sink failures never propagate into swarm execution.
     */
    'observability' => [
        'enabled' => filter_var(env('SWARM_OBSERVABILITY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'listen_to_events' => filter_var(env('SWARM_OBSERVABILITY_LISTEN_EVENTS', true), FILTER_VALIDATE_BOOLEAN),
        'failure_policy' => env('SWARM_OBSERVABILITY_FAILURE_POLICY', 'swallow'),
        'metadata_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SWARM_OBSERVABILITY_METADATA_ALLOWLIST', '')),
        ))),
        'categories' => [
            'include' => null,
            'exclude' => null,
        ],
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
        /*
         * AdvanceDurableSwarm / AdvanceDurableBranch queue settings.
         * Job timeout is step_timeout + timeout_margin_seconds (not a separate absolute cap).
         */
        'job' => [
            'tries' => (int) env('SWARM_DURABLE_JOB_TRIES', 3),
            'timeout_margin_seconds' => (int) env('SWARM_DURABLE_JOB_TIMEOUT_MARGIN_SECONDS', 60),
            'backoff_seconds' => array_values(array_filter(array_map(
                static fn (string $part): int => (int) trim($part),
                explode(',', (string) env('SWARM_DURABLE_JOB_BACKOFF_SECONDS', '10,30,60'))
            ), static fn (int $n): bool => $n > 0)) ?: [10, 30, 60],
        ],
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
        'webhooks' => [
            'enabled' => env('SWARM_WEBHOOKS_ENABLED', false),
            'prefix' => env('SWARM_WEBHOOKS_PREFIX', 'swarm/webhooks'),
            'idempotency_ttl' => (int) env('SWARM_WEBHOOK_IDEMPOTENCY_TTL', 3600),
            'auth' => [
                'driver' => env('SWARM_WEBHOOK_AUTH_DRIVER', 'signed'),
                'secret' => env('SWARM_WEBHOOK_SECRET'),
                'token' => env('SWARM_WEBHOOK_TOKEN'),
                'signature_header' => env('SWARM_WEBHOOK_SIGNATURE_HEADER', 'X-Swarm-Signature'),
                'timestamp_header' => env('SWARM_WEBHOOK_TIMESTAMP_HEADER', 'X-Swarm-Timestamp'),
                'tolerance_seconds' => (int) env('SWARM_WEBHOOK_TOLERANCE_SECONDS', 300),
                'callback' => env('SWARM_WEBHOOK_AUTH_CALLBACK'),
            ],
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
        'durable_node_states' => env('SWARM_DURABLE_NODE_STATES_TABLE', 'swarm_durable_node_states'),
        'durable_run_state' => env('SWARM_DURABLE_RUN_STATE_TABLE', 'swarm_durable_run_state'),
        'durable_node_outputs' => env('SWARM_DURABLE_NODE_OUTPUTS_TABLE', 'swarm_durable_node_outputs'),
        'durable_branches' => env('SWARM_DURABLE_BRANCHES_TABLE', 'swarm_durable_branches'),
        'durable_signals' => env('SWARM_DURABLE_SIGNALS_TABLE', 'swarm_durable_signals'),
        'durable_waits' => env('SWARM_DURABLE_WAITS_TABLE', 'swarm_durable_waits'),
        'durable_labels' => env('SWARM_DURABLE_LABELS_TABLE', 'swarm_durable_labels'),
        'durable_details' => env('SWARM_DURABLE_DETAILS_TABLE', 'swarm_durable_details'),
        'durable_progress' => env('SWARM_DURABLE_PROGRESS_TABLE', 'swarm_durable_progress'),
        'durable_child_runs' => env('SWARM_DURABLE_CHILD_RUNS_TABLE', 'swarm_durable_child_runs'),
        'durable_webhook_idempotency' => env('SWARM_DURABLE_WEBHOOK_IDEMPOTENCY_TABLE', 'swarm_durable_webhook_idempotency'),
    ],
];
