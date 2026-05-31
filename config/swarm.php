<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;

$swarmPersistenceDriver = env('SWARM_PERSISTENCE_DRIVER', 'cache');
$swarmContextDriver = env('SWARM_CONTEXT_DRIVER');
$swarmArtifactsDriver = env('SWARM_ARTIFACTS_DRIVER');
$swarmHistoryDriver = env('SWARM_HISTORY_DRIVER');
$swarmStreamReplayDriver = env('SWARM_STREAM_REPLAY_DRIVER');
$swarmDatabasePersistenceEnabled = in_array('database', [
    $swarmPersistenceDriver,
    $swarmContextDriver,
    $swarmArtifactsDriver,
    $swarmHistoryDriver,
    $swarmStreamReplayDriver,
], true);

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
         * When any database persistence driver is active, sensitive string columns
         * (prompts, agent outputs, branch I/O, etc.) are sealed with Laravel's encrypter (APP_KEY).
         * Override with SWARM_ENCRYPT_AT_REST=false only when you rely solely on database-level encryption.
         */
        'encrypt_at_rest' => filter_var(
            env('SWARM_ENCRYPT_AT_REST', $swarmDatabasePersistenceEnabled),
            FILTER_VALIDATE_BOOLEAN
        ),
        /*
         * When decrypting sw0:-prefixed columns fails (wrong or rotated APP_KEY, corrupt rows):
         * null_with_log — log a warning without ciphertext, return null for that field (default).
         * legacy — return the stored bytes unchanged (previous package behavior; surfaces ciphertext strings).
         * throw — rethrow the decryption exception.
         * Unrecognized non-empty values are treated as null_with_log; see warn_on_invalid_decrypt_failure_policy.
         */
        'decrypt_failure_policy' => env('SWARM_PERSISTENCE_DECRYPT_FAILURE_POLICY', 'null_with_log'),
        /*
         * When true, log once per worker if decrypt_failure_policy is set to an unrecognized value
         * (effective policy remains null_with_log). Disable to avoid extra log lines in strict environments.
         */
        'warn_on_invalid_decrypt_failure_policy' => filter_var(
            env('SWARM_WARN_ON_INVALID_DECRYPT_FAILURE_POLICY', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /*
         * JSON columns on persisted rows (for example context data/metadata/artifacts) remain
         * structured JSON in the database; encrypt_at_rest seals designated string columns only.
         * Do not store secrets inside JSON payloads unless your application encrypts them.
         */
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
     *   swallow     — silently discard.
     *   log         — record via application logger, then continue.
     *   queue       — persist the failed record to the audit outbox for retry
     *                 via swarm:relay --type=audit (default since v0.5; requires
     *                 the database persistence driver and the swarm_audit_outbox
     *                 migration — on cache driver the dispatcher degrades to
     *                 log-and-swallow).
     *   dead_letter — persist directly to the dead-letter status (no retry).
     *   halt        — throw AuditSinkHaltedException; the run fails.
     *
     * Sink failures never propagate into swarm execution under swallow, log,
     * queue, and dead_letter. Only halt is allowed to fail a run.
     *
     * actor.required: when true, runs entering the runner without a resolvable
     * Actor throw MissingActorException. Bind an Actor via $context->withActor()
     * before dispatch, Context::add('swarm:actor', $actor) inside a request, or
     * supply a custom ActorResolver in the container. Default false — set to
     * true for regulated deployments that treat unattributed runs as a
     * compliance violation.
     *
     * outbox.max_attempts: maximum re-emission attempts before a record moves
     * to the dead-letter status. Default 5.
     */
    'audit' => [
        'failure_policy' => env('SWARM_AUDIT_FAILURE_POLICY', 'queue'),
        'actor' => [
            'required' => filter_var(env('SWARM_AUDIT_ACTOR_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'metadata_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SWARM_AUDIT_METADATA_ALLOWLIST', '')),
        ))),
        'outbox' => [
            'max_attempts' => (int) env('SWARM_AUDIT_OUTBOX_MAX_ATTEMPTS', 5),
            /*
             * Retention window for dead-lettered audit records, in days. Default
             * null preserves dead-letter rows indefinitely so regulated callers
             * (FDA 21 CFR Part 11, SOC 2, etc.) do not silently erase compliance
             * evidence before reconciliation. Set to a positive integer to opt
             * into automatic pruning via `swarm:prune`. Pending and reserved
             * rows are never pruned by this policy.
             */
            'dead_letter_retention_days' => env('SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS') !== null
                ? (int) env('SWARM_AUDIT_OUTBOX_DEAD_LETTER_RETENTION_DAYS')
                : null,
        ],
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
        /*
         * Maximum size of the run metadata array when JSON-encoded, in bytes.
         * null = uncapped (default). Enforced at run start across all execution modes.
         * The truncate overflow strategy does not apply to metadata (structured array);
         * only fail is supported when a limit is set.
         */
        'max_metadata_bytes' => env('SWARM_MAX_METADATA_BYTES'),
        'overflow' => env('SWARM_LIMIT_OVERFLOW', 'fail'),
    ],

    /*
     * Guardrails validate input, each agent step, and final output. They are not orchestration or middleware.
     *
     * child_inheritance:
     *   own_and_global — global config entries plus the swarm's DefinesGuardrails::guardrails().
     *   own_global_and_parent — also merge parent swarm guardrails when parent_run_id resolves via history.
     *
     * parallel_failure_policy (sync ParallelRunner only; durable queued parallel branches fall back to existing):
     *   existing — validate each branch immediately before that branch's step is recorded.
     *   batch_validate_before_record — validate every parallel output before any step completion row is written.
     */
    'guardrails' => [
        'input' => [],
        'step' => [],
        'output' => [],
        'child_inheritance' => env('SWARM_GUARDRAILS_CHILD_INHERITANCE', 'own_and_global'),
        'parallel_failure_policy' => env('SWARM_GUARDRAILS_PARALLEL_FAILURE_POLICY', 'existing'),
    ],

    'context' => [
        'driver' => $swarmContextDriver,
        'ttl' => (int) env('SWARM_CONTEXT_TTL', 3600),
        'store' => env('SWARM_CONTEXT_STORE'),
        'prefix' => env('SWARM_CONTEXT_PREFIX', 'swarm:context:'),
    ],

    'artifacts' => [
        'driver' => $swarmArtifactsDriver,
        'store' => env('SWARM_ARTIFACTS_STORE'),
        'prefix' => env('SWARM_ARTIFACTS_PREFIX', 'swarm:artifacts:'),
    ],

    'history' => [
        'driver' => $swarmHistoryDriver,
        'store' => env('SWARM_HISTORY_STORE'),
        'prefix' => env('SWARM_HISTORY_PREFIX', 'swarm:history:'),
        'index_prefix' => env('SWARM_HISTORY_INDEX_PREFIX', 'swarm:index:'),
        'latest_prefix' => env('SWARM_HISTORY_LATEST_PREFIX', 'swarm:index:latest'),
    ],

    'static_hierarchical' => [
        /*
         * How parallel groups behave when a static hierarchical swarm is streamed.
         * concurrent  — branches run via ConcurrencyManager (no live text deltas from branches);
         *               sequential nodes after the join stream normally.
         * sequential  — branches stream one at a time in declaration order;
         *               sequential nodes after the join stream normally.
         */
        'stream_parallel_branches' => env('SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES', 'concurrent'),
    ],

    'memory' => [
        /*
         * Controls how a durable swarm re-executes after a crash-resume.
         *
         * 'frozen_view'     — agents re-execute against the memory snapshot frozen
         *                     at the original invocation. Live writes are buffered
         *                     and never reach the backing store, preserving the
         *                     canonical audit record. Recommended for reproducible runs.
         *
         * 'fresh_execution' — agents re-execute against live memory with no snapshot
         *                     guard. Use only when idempotency is guaranteed externally.
         *
         * Override per swarm with the #[MemoryReplay(mode: ReplayMode::...)] attribute.
         */
        'replay_mode' => env('SWARM_MEMORY_REPLAY_MODE', 'frozen_view'),

        /*
         * Per-scope retention windows for `swarm:memory:purge`.
         *
         * Each value is the maximum age in days for entries in that scope —
         * rows whose `created_at` is older than `now() - N days` are eligible
         * for purge. `null` disables retention enforcement for that scope
         * (the default for every scope, so existing applications never lose
         * data without an explicit policy decision). The minimum enforceable
         * window is 1 day: a value below 1 (e.g. `0`) is treated as `null`
         * (disabled) and the command warns rather than purging everything.
         *
         * Scope hint:
         *   run          — bounded to a single swarm run; usually the shortest
         *                  window (PII-heavy step I/O via memory writes).
         *   conversation — multi-run conversation thread state.
         *   agent        — per-agent-class persistent state (preferences,
         *                  remembered knowledge); typically the longest window.
         *   swarm        — package- or workflow-wide shared state.
         *
         * The `swarm:memory:purge` Artisan command reads this map. Schedule it
         * via Laravel's scheduler once you have set windows that match your
         * compliance commitments — see docs/advanced-setup.md.
         */
        'retention' => [
            'days' => [
                'run' => env('SWARM_MEMORY_RETENTION_RUN_DAYS') !== null
                    ? (int) env('SWARM_MEMORY_RETENTION_RUN_DAYS')
                    : null,
                'conversation' => env('SWARM_MEMORY_RETENTION_CONVERSATION_DAYS') !== null
                    ? (int) env('SWARM_MEMORY_RETENTION_CONVERSATION_DAYS')
                    : null,
                'agent' => env('SWARM_MEMORY_RETENTION_AGENT_DAYS') !== null
                    ? (int) env('SWARM_MEMORY_RETENTION_AGENT_DAYS')
                    : null,
                'swarm' => env('SWARM_MEMORY_RETENTION_SWARM_DAYS') !== null
                    ? (int) env('SWARM_MEMORY_RETENTION_SWARM_DAYS')
                    : null,
            ],
            /*
             * When true (default), `swarm_memory_snapshots` rows owned by a
             * purged Run-scoped memory are removed in the same purge run.
             * Override per invocation with `--keep-snapshots`. Snapshot rows
             * are addressed by `run_id`; non-Run scopes never own snapshots,
             * so this flag only affects the Run scope's cascade.
             */
            'prune_snapshots' => filter_var(
                env('SWARM_MEMORY_RETENTION_PRUNE_SNAPSHOTS', true),
                FILTER_VALIDATE_BOOLEAN
            ),
        ],
    ],

    'streaming' => [
        'replay' => [
            'enabled' => env('SWARM_STREAM_REPLAY_ENABLED', false),
            'driver' => $swarmStreamReplayDriver,
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
        'relay' => [
            /*
             * The transactional outbox relay drains swarm_durable_outbox rows and
             * dispatches the corresponding queue jobs. It must be scheduled to run
             * regularly (e.g. every minute) for durable execution to advance:
             *
             *   Schedule::command('swarm:relay')->everyMinute();
             *
             * Without the relay, durable runs will stall permanently after the
             * first step completes.
             */

            /*
             * How long a relay worker's claim on an outbox entry is considered valid.
             * Entries whose reserved_at is older than this many seconds are treated as
             * abandoned and become eligible for re-claim by the next relay run.
             */
            'reservation_timeout_seconds' => (int) env('SWARM_DURABLE_RELAY_RESERVATION_TIMEOUT_SECONDS', 60),

            /*
             * Maximum number of outbox entries drained per relay invocation.
             * The --limit option on swarm:relay overrides this, capped at 10,000.
             */
            'limit' => (int) env('SWARM_DURABLE_RELAY_LIMIT', 100),

            /*
             * How old an unclaimed outbox row must be before swarm:health --durable reports a
             * warning. Set to 0 to use 2 × reservation_timeout_seconds (the default).
             */
            'stale_warning_threshold_seconds' => (int) env('SWARM_DURABLE_RELAY_STALE_WARNING_THRESHOLD_SECONDS', 0),
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
    //
    // Table-name overrides are configuration-only: the SWARM_*_TABLE env vars
    // below are not seeded into .env by swarm:install. Operators who need to
    // rename a Swarm table (e.g. multi-tenant schemas) declare the env var in
    // .env themselves, or override the value directly in config/swarm.php
    // after publishing.
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
        'durable_outbox' => env('SWARM_DURABLE_OUTBOX_TABLE', 'swarm_durable_outbox'),
        'audit_outbox' => env('SWARM_AUDIT_OUTBOX_TABLE', 'swarm_audit_outbox'),
        'memories' => env('SWARM_MEMORIES_TABLE', 'swarm_memories'),
        'memory_snapshots' => env('SWARM_MEMORY_SNAPSHOTS_TABLE', 'swarm_memory_snapshots'),
    ],
];
