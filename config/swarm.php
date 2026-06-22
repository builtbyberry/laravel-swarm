<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\Topology;
use BuiltByBerry\LaravelSwarm\Memory\DefaultMemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;

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
         * The memory propagation policy: decides which memory entries an agent
         * sees when it is invoked, across scopes (run / conversation / agent /
         * swarm). The frozen MemorySnapshot mirrors exactly what this policy
         * returns, so it governs both what the agent reads and the audit record.
         *
         * The default (DefaultPropagationPolicy) presents the Run-scoped view
         * only, preserving pre-v0.10 behaviour. Bind a class implementing
         * BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy to widen
         * or reshape the view globally, or override per swarm with the
         * #[PropagationPolicy(MyPolicy::class)] attribute.
         */
        'propagation_policy' => env('SWARM_MEMORY_PROPAGATION_POLICY', DefaultPropagationPolicy::class),

        /*
         * Whether runners persist each step's output to Run scope under the
         * reserved key `swarm:step.{n}.output`. This is what lets the
         * RemembersRunContext trait (with ConversationPropagationPolicy) render
         * a real turn-by-turn conversation, and what audit exports surface as
         * the per-step record.
         *
         * **Disabled by default** — enabling it persists each agent's output to
         * the memory store (and, when a surfacing policy is active, into frozen
         * snapshots), so it expands what your application stores at rest. Before
         * enabling, decide on:
         *   - retention: Run-scoped rows are pruned by `swarm:memory:purge` per
         *     `swarm.memory.retention.days.run` (null = never; see below), so set
         *     a window if you don't want step outputs kept indefinitely;
         *   - redaction: a `MemoryCapturePolicy` (capture_policy) can redact or
         *     skip these keys at write time if outputs may contain PII.
         *
         * Captured values are stored full-fidelity (NOT truncated by
         * `swarm.limits.max_output_bytes`, unlike artifacts/history/final output)
         * so the audit record stays complete — manage volume via retention, not
         * truncation. The keys are hidden from the default agent view
         * (DefaultPropagationPolicy excludes them), so enabling this never
         * changes what a non-trait agent sees.
         */
        'capture_step_output' => filter_var(
            env('SWARM_MEMORY_CAPTURE_STEP_OUTPUT', false),
            FILTER_VALIDATE_BOOLEAN,
        ),

        /*
         * Tuning for ConversationPropagationPolicy — the opt-in policy that
         * surfaces the reserved step-output keys (above) as an ordered
         * transcript, intended to pair with the RemembersRunContext trait.
         *
         * 'include_run_memory' controls what else the policy shows alongside the
         * transcript:
         *   false (default) — transcript only: just the ordered step outputs,
         *                     for a clean turn-by-turn conversation.
         *   true            — transcript plus the rest of the Run-scoped view
         *                     (last_output, user-written keys). Richer, noisier.
         */
        'conversation_view' => [
            'include_run_memory' => filter_var(
                env('SWARM_MEMORY_CONVERSATION_INCLUDE_RUN_MEMORY', false),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ],

        /*
         * Configuration for the RemembersRunContext trait
         * (Concerns\RemembersRunContext). Agents using the trait render the
         * active swarm's propagation-policy memory view as laravel/ai messages;
         * laravel/ai prepends them before the agent's new user turn.
         *
         * 'role' is the Laravel\Ai\Messages\MessageRole each rendered entry
         * carries: 'assistant' (default), 'user', or 'tool_result'. Override per
         * agent by overriding RemembersRunContext::runContextMessageRole().
         */
        'run_context_messages' => [
            'role' => env('SWARM_MEMORY_RUN_CONTEXT_ROLE', 'assistant'),
        ],

        /*
         * The memory capture policy: decides, at the write boundary, whether
         * each memory entry is persisted as-is, redacted, or dropped entirely —
         * by scope and key. This is the write-side counterpart to the audit
         * CapturePolicy (swarm.capture.*): redaction here keeps PII out of
         * memory entirely, so it never reaches a frozen MemorySnapshot.
         *
         * Decisions: CaptureDecision::Full persists the value unchanged;
         * ::Redact structurally redacts scalars to '[redacted]' (preserving
         * array shape); ::Skip drops the entry (no row, no MemoryWritten event).
         *
         * The default (DefaultMemoryCapturePolicy) returns Full for every write,
         * preserving pre-v0.10 behaviour exactly. Bind a class implementing
         * BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy to redact or
         * drop sensitive entries globally.
         */
        'capture_policy' => env('SWARM_MEMORY_CAPTURE_POLICY', DefaultMemoryCapturePolicy::class),

        /*
         * The agent-facing Recall and Remember memory tools (Tools\Recall,
         * Tools\Remember). Both implement Laravel AI's Tool contract and drop
         * into any agent's tools() array. Recall reads through the active
         * propagation policy (it can only surface what the policy permits);
         * Remember writes through the capture policy (redaction is applied at
         * the write boundary). Scope ids are resolved from the active run, never
         * taken from the model, and the reserved `swarm:` key prefix is rejected.
         *
         * 'enabled' is the master switch for the HasSwarmMemoryTools concern:
         * agents using that trait expose the tools only when this is true, so
         * adding the trait is inert until you opt in app-wide. It does NOT force
         * the tools onto agents that attach them directly. **Disabled by
         * default** — granting an LLM read/write access to shared run memory is
         * an explicit decision: review your propagation and capture policies
         * first so an agent cannot read or persist anything you would not want
         * it to.
         *
         * 'recall' / 'remember' toggle each tool individually (both default on
         * once 'enabled' is true). The class names are resolved from the
         * container, so bind a subclass to customise a tool's description or
         * bind it to a specific agent for Agent-scope addressing.
         */
        'tools' => [
            'enabled' => filter_var(
                env('SWARM_MEMORY_TOOLS_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'recall' => filter_var(
                env('SWARM_MEMORY_TOOLS_RECALL', true),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'remember' => filter_var(
                env('SWARM_MEMORY_TOOLS_REMEMBER', true),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ],

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

    'pulse' => [
        'memory' => [
            /*
             * Sample rate applied to the SwarmMemoryMetrics Pulse recorder
             * (entries per scope, write bytes, recall hit/miss, snapshot
             * bytes + entry counts). Values are clamped to [0.0, 1.0]:
             *
             *   1.0 — record every event (default; safe for low/moderate volume)
             *   0.1 — record ~10% of events (recommended for high-volume apps)
             *   0.0 — disable sampling entirely (recorder runs as a no-op)
             *
             * Sampling is uniform across writes, reads, and snapshots so the
             * averages stay statistically meaningful — they are the average of
             * a uniform random sample.
             */
            'sample_rate' => (float) env('SWARM_PULSE_MEMORY_SAMPLE_RATE', 1.0),
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
         * Queued swarm jobs (InvokeSwarm, BroadcastSwarm, ResumeQueuedHierarchicalSwarm) are attempted
         * ONCE by default, regardless of the worker's global --tries flag. A retry restarts the entire
         * swarm run from step 0 — re-dispatching tools and re-spending LLM tokens — so blind retries
         * are unsafe. Set SWARM_QUEUE_TRIES > 1 only if your swarms are idempotent and the token cost
         * of a full restart is acceptable.
         * SWARM_QUEUE_TIMEOUT is intentionally null (inherit worker --timeout) — a low package default
         * would kill legitimately long LLM runs; the safety concern is retries, not timeout.
         */
        'tries' => (int) env('SWARM_QUEUE_TRIES', 1),
        'timeout' => env('SWARM_QUEUE_TIMEOUT'),   // null = inherit worker --timeout
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
             * How old an unclaimed outbox row must be before swarm:health reports a warning —
             * applies to both the durable outbox (swarm:health --durable) and the audit outbox
             * (checked on bare swarm:health). Set to 0 to use 2 × reservation_timeout_seconds
             * (the default).
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
        'stream_step_checkpoints' => env('SWARM_STREAM_STEP_CHECKPOINTS_TABLE', 'swarm_stream_step_checkpoints'),
    ],
];
