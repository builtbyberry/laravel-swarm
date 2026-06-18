# Configuration Reference

The Laravel Swarm configuration file controls every aspect of the package — topologies, execution modes, persistence, capture, limits, observability, and durable execution. The recommended way to publish it is via the bundled installer; `swarm:install` handles the publish step (along with `.env` seeding, migrations, and sub-installer wiring) in one pass. The `vendor:publish` invocation below remains supported for manual setups — see [Advanced Setup](advanced-setup.md) for the full manual flow.

---

## Publishing Config

`swarm:install` publishes `config/swarm.php` as part of its first-run flow. To publish manually:

```bash
php artisan vendor:publish --tag=swarm-config
```

The published file appears at `config/swarm.php` in your application. Most options can be overridden with `SWARM_*` environment variables without re-publishing.

---

## Core

Top-level settings that apply to every swarm run regardless of topology or execution mode.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.topology` | string | `sequential` | `SWARM_TOPOLOGY` | Default topology used when a swarm class does not declare `#[Topology]`. Values: `sequential`, `parallel`, `hierarchical`. |
| `swarm.timeout` | int | `300` | `SWARM_TIMEOUT` | Best-effort orchestration deadline in seconds. Checked before and between swarm steps. Does not hard-cancel an in-flight provider call. |
| `swarm.max_agent_steps` | int | `10` | `SWARM_MAX_AGENT_STEPS` | Maximum number of agent steps per run. The swarm fails before exceeding this limit. |
| `swarm.retention.prevent_prune` | bool | `false` | `SWARM_PREVENT_PRUNE` | When `true`, `swarm:prune` skips all destructive deletes (scheduled pruning becomes a no-op). Use in regulated deployments that manage retention outside the package. `--dry-run` still reports counts when this is enabled. |

---

## Persistence

Controls the primary persistence driver and at-rest encryption behavior. Changing the driver requires migrating existing data manually.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.persistence.driver` | string | `cache` | `SWARM_PERSISTENCE_DRIVER` | Primary persistence driver for context, artifacts, and history. Values: `cache`, `database`. |
| `swarm.persistence.encrypt_at_rest` | bool | `false` (cache) / `true` (database) | `SWARM_ENCRYPT_AT_REST` | When any database persistence driver is active, designated sensitive string columns (prompts, agent outputs, branch I/O, etc.) are sealed with Laravel's encrypter (`APP_KEY`). Defaults to `true` when `SWARM_PERSISTENCE_DRIVER` or any sub-driver is `database`. Set to `false` only when relying on database-level or volume-level encryption instead. |
| `swarm.persistence.decrypt_failure_policy` | string | `null_with_log` | `SWARM_PERSISTENCE_DECRYPT_FAILURE_POLICY` | What happens when decrypting a `sw0:`-prefixed column fails (wrong or rotated `APP_KEY`, corrupt rows). `null_with_log` — log a warning and return `null` for that field. `legacy` — return stored bytes unchanged (surfaces ciphertext strings). `throw` — rethrow the decryption exception. Unrecognized values are treated as `null_with_log`. |
| `swarm.persistence.warn_on_invalid_decrypt_failure_policy` | bool | `true` | `SWARM_WARN_ON_INVALID_DECRYPT_FAILURE_POLICY` | When `true`, logs once per worker if `decrypt_failure_policy` is set to an unrecognized value. Disable to avoid extra log lines in strict environments. |

> **Note:** JSON columns (context data, metadata, artifacts) remain structured JSON in the database; `encrypt_at_rest` seals designated string columns only. Do not store secrets inside JSON payloads unless your application encrypts them separately.

> **Key rotation:** Rotating `APP_KEY` without re-encrypting existing rows leaves them undecipherable. Plan key rotation with your operational model. See [APP_KEY Rotation](app-key-rotation.md) for the runbook.

---

## Context, Artifacts, and History Stores

Sub-driver overrides for individual stores. By default each store inherits from `swarm.persistence.driver`. Set a specific env var to route that store to a different driver.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.context.driver` | string\|null | `null` (inherits) | `SWARM_CONTEXT_DRIVER` | Storage driver for `RunContext` data. `null` inherits from `swarm.persistence.driver`. |
| `swarm.context.ttl` | int | `3600` | `SWARM_CONTEXT_TTL` | Cache TTL in seconds for context entries when using the cache driver. |
| `swarm.context.store` | string\|null | `null` | `SWARM_CONTEXT_STORE` | Named cache store to use for context (e.g. `redis`). `null` uses the default cache store. |
| `swarm.context.prefix` | string | `swarm:context:` | `SWARM_CONTEXT_PREFIX` | Cache key prefix for context entries. |
| `swarm.artifacts.driver` | string\|null | `null` (inherits) | `SWARM_ARTIFACTS_DRIVER` | Storage driver for run artifacts. `null` inherits from `swarm.persistence.driver`. |
| `swarm.artifacts.store` | string\|null | `null` | `SWARM_ARTIFACTS_STORE` | Named cache store for artifacts. `null` uses the default cache store. |
| `swarm.artifacts.prefix` | string | `swarm:artifacts:` | `SWARM_ARTIFACTS_PREFIX` | Cache key prefix for artifact entries. |
| `swarm.history.driver` | string\|null | `null` (inherits) | `SWARM_HISTORY_DRIVER` | Storage driver for run history and step records. `null` inherits from `swarm.persistence.driver`. |
| `swarm.history.store` | string\|null | `null` | `SWARM_HISTORY_STORE` | Named cache store for history entries. `null` uses the default cache store. |
| `swarm.history.prefix` | string | `swarm:history:` | `SWARM_HISTORY_PREFIX` | Cache key prefix for history entries. |
| `swarm.history.index_prefix` | string | `swarm:index:` | `SWARM_HISTORY_INDEX_PREFIX` | Cache key prefix for history index entries. |
| `swarm.history.latest_prefix` | string | `swarm:index:latest` | `SWARM_HISTORY_LATEST_PREFIX` | Cache key prefix for the latest-run index. |

---

## Capture

Controls what is persisted into history, context, and response payloads. Defaults are conservative — opt in when you need full prompts and outputs stored. Treat prompts, outputs, and tool payloads as sensitive whenever capture is enabled.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.capture.inputs` | bool | `false` | `SWARM_CAPTURE_INPUTS` | Persist the swarm's input prompt/task into run history. |
| `swarm.capture.outputs` | bool | `false` | `SWARM_CAPTURE_OUTPUTS` | Persist the final agent output into run history. With this off, streamed tool and reasoning payloads redact values to `[redacted]` while preserving keys. |
| `swarm.capture.artifacts` | bool | `false` | `SWARM_CAPTURE_ARTIFACTS` | Persist step artifacts into the artifact store. |
| `swarm.capture.active_context` | bool | `false` | `SWARM_CAPTURE_ACTIVE_CONTEXT` | Persist the active `RunContext` data snapshot into run history. |

> See [persistence-and-history.md](persistence-and-history.md) for full capture behavior and redaction rules.

---

## Limits

Hard limits on payload sizes. All limits default to `null` (uncapped). Enforced at run start across all execution modes.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.limits.max_input_bytes` | int\|null | `null` | `SWARM_MAX_INPUT_BYTES` | Maximum size of the run input payload in bytes. `null` means uncapped. |
| `swarm.limits.max_output_bytes` | int\|null | `null` | `SWARM_MAX_OUTPUT_BYTES` | Maximum size of the final output payload in bytes. `null` means uncapped. |
| `swarm.limits.max_metadata_bytes` | int\|null | `null` | `SWARM_MAX_METADATA_BYTES` | Maximum size of the run metadata array when JSON-encoded, in bytes. `null` means uncapped. Only the `fail` overflow strategy applies to metadata; `truncate` is not supported for structured arrays. |
| `swarm.limits.overflow` | string | `fail` | `SWARM_LIMIT_OVERFLOW` | What happens when a limit is exceeded. `fail` — throw an exception before the run starts. `truncate` — truncate the payload to the limit (not applicable to metadata). |

---

## Guardrails

Guardrails validate input, each agent step, and final output. They are not orchestration middleware. See [guardrails.md](guardrails.md) for implementing guardrail classes.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.guardrails.input` | array | `[]` | — | Array of input guardrail class names applied before the swarm runs. |
| `swarm.guardrails.step` | array | `[]` | — | Array of step guardrail class names applied after each agent step. |
| `swarm.guardrails.output` | array | `[]` | — | Array of output guardrail class names applied to the final swarm output. |
| `swarm.guardrails.child_inheritance` | string | `own_and_global` | `SWARM_GUARDRAILS_CHILD_INHERITANCE` | How child swarms inherit guardrails. `own_and_global` — global config entries plus the swarm's own `DefinesGuardrails::guardrails()`. `own_global_and_parent` — also merges parent swarm guardrails when `parent_run_id` resolves via history. |
| `swarm.guardrails.parallel_failure_policy` | string | `existing` | `SWARM_GUARDRAILS_PARALLEL_FAILURE_POLICY` | Applies to sync `ParallelRunner` only; durable queued parallel branches fall back to `existing`. `existing` — validate each branch immediately before that branch's step is recorded. `batch_validate_before_record` — validate every parallel output before any step completion row is written. |

Guardrail classes are registered in the config array, not via env vars. Example:

```php
'guardrails' => [
    'input'  => [App\Swarm\Guardrails\ProfanityGuardrail::class],
    'step'   => [App\Swarm\Guardrails\StepSizeGuardrail::class],
    'output' => [App\Swarm\Guardrails\OutputPiiGuardrail::class],
],
```

---

## Static Hierarchical

Controls behavior specific to the `StaticHierarchical` topology.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.static_hierarchical.stream_parallel_branches` | string | `concurrent` | `SWARM_STATIC_HIERARCHICAL_STREAM_PARALLEL_BRANCHES` | How parallel groups behave when a static hierarchical swarm is streamed. `concurrent` — branches run via `ConcurrencyManager` (no live text deltas from branches; sequential nodes after the join stream normally). `sequential` — branches stream one at a time in declaration order; sequential nodes after the join stream normally. |

---

## Queue

Queue connection and name used for `queue()` execution and hierarchical parallel coordination.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.queue.connection` | string\|null | `null` | `SWARM_QUEUE_CONNECTION` | Laravel queue connection for queued swarm jobs. `null` uses the application's default queue connection. |
| `swarm.queue.name` | string\|null | `null` | `SWARM_QUEUE` | Queue name for queued swarm jobs. `null` uses the connection's default queue. |
| `swarm.queue.tries` | int | `1` | `SWARM_QUEUE_TRIES` | Number of attempts for `InvokeSwarm` and `BroadcastSwarm` jobs. Defaults to `1` regardless of the worker's `--tries` flag — a retry restarts the full swarm run (no checkpoint), re-dispatching all tools and re-spending LLM tokens. Raise only if your swarms are idempotent and the token cost of a full restart is acceptable. Does **not** affect durable advance jobs (`AdvanceDurableSwarm`/`AdvanceDurableBranch`), which derive their tries from `swarm.durable.job.tries`. |
| `swarm.queue.timeout` | int\|null | `null` | `SWARM_QUEUE_TIMEOUT` | Queue worker timeout (seconds) for queued swarm jobs. `null` inherits the worker's `--timeout` — a package-level default would kill legitimately long LLM runs. Set explicitly only when you need a hard ceiling. |
| `swarm.queue.hierarchical_parallel.coordination` | string | `in_process` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_COORDINATION` | How hierarchical parallel route nodes coordinate when dispatched with `queue()`. `in_process` — parallel groups execute sequentially in declaration order within the same job. `multi_worker` — branches are dispatched as separate jobs and coordinated across workers (requires database-backed persistence and durable tables). |
| `swarm.queue.hierarchical_parallel.connection` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_CONNECTION` | Queue connection for hierarchical parallel coordination jobs. |
| `swarm.queue.hierarchical_parallel.name` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_NAME` | Queue name for hierarchical parallel coordination jobs. |
| `swarm.queue.hierarchical_parallel.branch.connection` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_CONNECTION` | Queue connection for individual parallel branch jobs. |
| `swarm.queue.hierarchical_parallel.branch.name` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_NAME` | Queue name for individual parallel branch jobs. |
| `swarm.queue.hierarchical_parallel.resume.connection` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_CONNECTION` | Queue connection for the resume-after-join job. |
| `swarm.queue.hierarchical_parallel.resume.name` | string\|null | `null` | `SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_NAME` | Queue name for the resume-after-join job. |

---

## Streaming / Replay

Controls the optional persisted stream replay feature. Replay is disabled by default. See [streaming.md](streaming.md) for full streaming and replay behavior.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.streaming.replay.enabled` | bool | `false` | `SWARM_STREAM_REPLAY_ENABLED` | When `true`, all streamed swarm runs are automatically stored for replay via `SwarmHistory::replay($runId)`. Can also be enabled per-run with `storeForReplay()`. |
| `swarm.streaming.replay.driver` | string\|null | `null` (inherits) | `SWARM_STREAM_REPLAY_DRIVER` | Storage driver for stream replay events. `null` inherits from `swarm.persistence.driver`. |
| `swarm.streaming.replay.failure_policy` | string | `fail` | `SWARM_STREAM_REPLAY_FAILURE_POLICY` | What happens when writing a replay event fails. `fail` — the stream fails (default). `continue` — the failure is swallowed and partial replay is unavailable. |
| `swarm.streaming.replay.store` | string\|null | `null` | `SWARM_STREAM_REPLAY_STORE` | Named cache store for stream replay events. `null` uses the default cache store. |
| `swarm.streaming.replay.prefix` | string | `swarm:stream:` | `SWARM_STREAM_REPLAY_PREFIX` | Cache key prefix for stream replay entries. |

---

## Durable

Settings for `dispatchDurable()` execution. Durable runs are database-backed and checkpointed, allowing them to survive worker restarts. See [durable-execution.md](durable-execution.md) for full durable execution behavior.

### Core Durable Settings

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.step_timeout` | int | `300` | `SWARM_DURABLE_STEP_TIMEOUT` | Maximum seconds a single durable step may run before the job times out. The actual queue job timeout is `step_timeout + job.timeout_margin_seconds`. |

### Durable Job Settings

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.job.tries` | int | `3` | `SWARM_DURABLE_JOB_TRIES` | Number of attempts for `AdvanceDurableSwarm` and `AdvanceDurableBranch` jobs before they are marked failed. |
| `swarm.durable.job.timeout_margin_seconds` | int | `60` | `SWARM_DURABLE_JOB_TIMEOUT_MARGIN_SECONDS` | Seconds added to `step_timeout` to compute the queue job's hard timeout. Gives the step room to clean up before the job is killed. |
| `swarm.durable.job.backoff_seconds` | array | `[10, 30, 60]` | `SWARM_DURABLE_JOB_BACKOFF_SECONDS` | Retry backoff delays in seconds as a comma-separated string (e.g. `10,30,60`). Zero values are filtered out. |

### Durable Parallel Settings

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.parallel.failure_policy` | string | `collect_failures` | `SWARM_DURABLE_PARALLEL_FAILURE_POLICY` | How parallel branch failures are handled in durable runs. `collect_failures` — all branches run to completion; failures are collected and the run fails after all branches finish. |
| `swarm.durable.parallel.queue.connection` | string\|null | `null` | `SWARM_DURABLE_PARALLEL_QUEUE_CONNECTION` | Queue connection for durable parallel branch jobs. `null` falls back to `swarm.durable.queue.connection`. |
| `swarm.durable.parallel.queue.name` | string\|null | `null` | `SWARM_DURABLE_PARALLEL_QUEUE` | Queue name for durable parallel branch jobs. |

### Durable Queue Settings

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.queue.connection` | string\|null | `null` | `SWARM_DURABLE_QUEUE_CONNECTION` | Queue connection for durable advance jobs. `null` uses the application's default queue connection. |
| `swarm.durable.queue.name` | string\|null | `null` | `SWARM_DURABLE_QUEUE` | Queue name for durable advance jobs. |

### Durable Recovery

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.recovery.grace_seconds` | int | `300` | `SWARM_DURABLE_RECOVERY_GRACE_SECONDS` | How many seconds a durable run must be stalled before `swarm:recover` considers it eligible for recovery. Prevents re-dispatching runs that are still actively processing. |

### Durable Relay

The transactional outbox relay (`swarm:relay`) drains `swarm_durable_outbox` rows and dispatches the corresponding queue jobs. **You must schedule this command for durable execution to advance:**

```php
// routes/console.php
Schedule::command('swarm:relay')->everyMinute();
```

Without the relay, durable runs stall permanently after the first step completes.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.relay.reservation_timeout_seconds` | int | `60` | `SWARM_DURABLE_RELAY_RESERVATION_TIMEOUT_SECONDS` | How long a relay worker's claim on an outbox entry is valid. Entries whose `reserved_at` is older than this are treated as abandoned and become eligible for re-claim by the next relay run. |
| `swarm.durable.relay.limit` | int | `100` | `SWARM_DURABLE_RELAY_LIMIT` | Maximum outbox entries drained per relay invocation. The `--limit` option on `swarm:relay` overrides this at runtime, capped at 10,000. |
| `swarm.durable.relay.stale_warning_threshold_seconds` | int | `0` | `SWARM_DURABLE_RELAY_STALE_WARNING_THRESHOLD_SECONDS` | How old an unclaimed outbox row must be before `swarm:health --durable` reports a warning. `0` uses `2 × reservation_timeout_seconds` as the threshold. |

### Durable Webhooks

Webhook support for durable runs allows external systems to deliver signals and results via HTTP. See [durable-webhooks.md](durable-webhooks.md) for full webhook configuration and signing.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.durable.webhooks.enabled` | bool\|string | `false` | `SWARM_WEBHOOKS_ENABLED` | Enable the webhook routes for durable run signal delivery. |
| `swarm.durable.webhooks.prefix` | string | `swarm/webhooks` | `SWARM_WEBHOOKS_PREFIX` | URL prefix for registered webhook routes. |
| `swarm.durable.webhooks.idempotency_ttl` | int | `3600` | `SWARM_WEBHOOK_IDEMPOTENCY_TTL` | How long (in seconds) webhook idempotency keys are retained to prevent duplicate delivery processing. |
| `swarm.durable.webhooks.auth.driver` | string | `signed` | `SWARM_WEBHOOK_AUTH_DRIVER` | Authentication driver for incoming webhooks. `signed` — HMAC signature verification. `token` — bearer token verification. `callback` — custom PHP callable for auth logic. |
| `swarm.durable.webhooks.auth.secret` | string\|null | `null` | `SWARM_WEBHOOK_SECRET` | HMAC secret for the `signed` auth driver. |
| `swarm.durable.webhooks.auth.token` | string\|null | `null` | `SWARM_WEBHOOK_TOKEN` | Bearer token for the `token` auth driver. |
| `swarm.durable.webhooks.auth.signature_header` | string | `X-Swarm-Signature` | `SWARM_WEBHOOK_SIGNATURE_HEADER` | HTTP header name carrying the HMAC signature. |
| `swarm.durable.webhooks.auth.timestamp_header` | string | `X-Swarm-Timestamp` | `SWARM_WEBHOOK_TIMESTAMP_HEADER` | HTTP header name carrying the request timestamp for replay-attack prevention. |
| `swarm.durable.webhooks.auth.tolerance_seconds` | int | `300` | `SWARM_WEBHOOK_TOLERANCE_SECONDS` | Maximum age (in seconds) of a signed webhook request before it is rejected as a replay. |
| `swarm.durable.webhooks.auth.callback` | string\|null | `null` | `SWARM_WEBHOOK_AUTH_CALLBACK` | Fully-qualified class or callable string for the `callback` auth driver. |

---

## Observability

Controls the telemetry sink that exports structured correlation payloads. Bind `SwarmTelemetrySink` in your service container to route records to logs, metrics, or tracing adapters. The default binding (`NoOpSwarmTelemetrySink`) discards all records. See [observability-logging-tracing.md](observability-logging-tracing.md) for sink implementation and correlation contract details.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.observability.enabled` | bool | `true` | `SWARM_OBSERVABILITY_ENABLED` | Master switch for the telemetry pipeline. When `false`, no records are sent to the sink. `stream.event` and `broadcast.event` direct hooks still respect this flag. |
| `swarm.observability.listen_to_events` | bool | `true` | `SWARM_OBSERVABILITY_LISTEN_EVENTS` | When `false`, lifecycle and package queue job telemetry event listeners are not registered. `stream.event` and `broadcast.event` direct hooks still fire when `enabled` is `true`. |
| `swarm.observability.failure_policy` | string | `swallow` | `SWARM_OBSERVABILITY_FAILURE_POLICY` | What happens when the telemetry sink throws. `swallow` — silently discard (default). `log` — record via application logger, then continue. Sink failures never propagate into swarm execution. |
| `swarm.observability.metadata_allowlist` | array | `[]` | `SWARM_OBSERVABILITY_METADATA_ALLOWLIST` | Comma-separated list of run metadata keys forwarded to telemetry payloads. Only listed keys are included. Empty list means no metadata is forwarded. |
| `swarm.observability.categories.include` | array\|null | `null` | — | When set, only telemetry records in the listed categories are forwarded to the sink. `null` includes all categories. |
| `swarm.observability.categories.exclude` | array\|null | `null` | — | When set, telemetry records in the listed categories are dropped before reaching the sink. `null` excludes nothing. |

`categories.include` and `categories.exclude` are set directly in the config array (no env var):

```php
'observability' => [
    'categories' => [
        'include' => ['swarm.run', 'swarm.step'],
        'exclude' => null,
    ],
],
```

---

## Audit

Controls the audit evidence sink. Bind `SwarmAuditSink` in your service container to route package-owned audit evidence to an append-only store, SIEM export, or queue listener. The default binding (`NoOpSwarmAuditSink`) discards all evidence. See [audit-evidence-contract.md](audit-evidence-contract.md) for sink implementation details.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.audit.failure_policy` | string | `queue` | `SWARM_AUDIT_FAILURE_POLICY` | What happens when the audit sink throws. `swallow` — silently discard. `log` — log via the application logger, then continue. `queue` (default) — enqueue the failed record to the audit outbox for retry via `swarm:relay`. `dead_letter` — route the failed record to the outbox dead-letter status (no retry). `halt` — log and halt the swarm run. Only `queue` and `dead_letter` use the audit outbox table. `halt` is the one policy that propagates into run execution. |
| `swarm.audit.metadata_allowlist` | array | `[]` | `SWARM_AUDIT_METADATA_ALLOWLIST` | Comma-separated list of run metadata keys forwarded to audit evidence payloads. Only listed keys are included. |

---

## Memory

Controls the memory subsystem introduced in v0.9.0. Memory stores scoped values (Run, Conversation, Agent, and Swarm scopes) and captures per-step snapshots for deterministic crash-resume replay. See [Swarm Memory](memory.md) for the full subsystem reference.

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| `swarm.memory.replay_mode` | string | `frozen_view` | `SWARM_MEMORY_REPLAY_MODE` | Controls what memory a durable agent sees when a step is retried after a crash. `frozen_view` — the agent re-executes against the `MemoryScope::Run` entries frozen in the snapshot taken at the original invocation; live writes during the retry are buffered and never reach the backing store. `fresh_execution` — the agent re-executes against live memory with no snapshot guard; use only when idempotency is guaranteed externally. Override per swarm class with the `#[MemoryReplay]` attribute. |

```ini
# .env — override the global default
SWARM_MEMORY_REPLAY_MODE=frozen_view
```

```php
// config/swarm.php
'memory' => [
    'replay_mode' => env('SWARM_MEMORY_REPLAY_MODE', 'frozen_view'),
],
```

---

## Tables

Table name overrides for all database-backed stores. If you change these, publish and update the package migrations as well.

| Key | Default Table | Env Var |
|-----|---------------|---------|
| `swarm.tables.contexts` | `swarm_contexts` | `SWARM_CONTEXTS_TABLE` |
| `swarm.tables.artifacts` | `swarm_artifacts` | `SWARM_ARTIFACTS_TABLE` |
| `swarm.tables.history` | `swarm_run_histories` | `SWARM_RUN_HISTORIES_TABLE` |
| `swarm.tables.history_steps` | `swarm_run_steps` | `SWARM_RUN_HISTORY_STEPS_TABLE` |
| `swarm.tables.stream_events` | `swarm_stream_events` | `SWARM_STREAM_EVENTS_TABLE` |
| `swarm.tables.memories` | `swarm_memories` | `SWARM_MEMORIES_TABLE` |
| `swarm.tables.memory_snapshots` | `swarm_memory_snapshots` | `SWARM_MEMORY_SNAPSHOTS_TABLE` |
| `swarm.tables.stream_step_checkpoints` | `swarm_stream_step_checkpoints` | `SWARM_STREAM_STEP_CHECKPOINTS_TABLE` |
| `swarm.tables.durable` | `swarm_durable_runs` | `SWARM_DURABLE_RUNS_TABLE` |
| `swarm.tables.durable_node_states` | `swarm_durable_node_states` | `SWARM_DURABLE_NODE_STATES_TABLE` |
| `swarm.tables.durable_run_state` | `swarm_durable_run_state` | `SWARM_DURABLE_RUN_STATE_TABLE` |
| `swarm.tables.durable_node_outputs` | `swarm_durable_node_outputs` | `SWARM_DURABLE_NODE_OUTPUTS_TABLE` |
| `swarm.tables.durable_branches` | `swarm_durable_branches` | `SWARM_DURABLE_BRANCHES_TABLE` |
| `swarm.tables.durable_signals` | `swarm_durable_signals` | `SWARM_DURABLE_SIGNALS_TABLE` |
| `swarm.tables.durable_waits` | `swarm_durable_waits` | `SWARM_DURABLE_WAITS_TABLE` |
| `swarm.tables.durable_labels` | `swarm_durable_labels` | `SWARM_DURABLE_LABELS_TABLE` |
| `swarm.tables.durable_details` | `swarm_durable_details` | `SWARM_DURABLE_DETAILS_TABLE` |
| `swarm.tables.durable_progress` | `swarm_durable_progress` | `SWARM_DURABLE_PROGRESS_TABLE` |
| `swarm.tables.durable_child_runs` | `swarm_durable_child_runs` | `SWARM_DURABLE_CHILD_RUNS_TABLE` |
| `swarm.tables.durable_webhook_idempotency` | `swarm_durable_webhook_idempotency` | `SWARM_DURABLE_WEBHOOK_IDEMPOTENCY_TABLE` |
| `swarm.tables.durable_outbox` | `swarm_durable_outbox` | `SWARM_DURABLE_OUTBOX_TABLE` |

Table names are honored by all database repositories at runtime.

---

## Common Configuration Patterns

### Local Development

Prioritizes debuggability: use the cache driver so no database migrations are required, disable encryption, and turn all capture flags on so prompts and outputs are visible in run history.

```php
# .env (local)
SWARM_PERSISTENCE_DRIVER=cache
SWARM_ENCRYPT_AT_REST=false
SWARM_CAPTURE_INPUTS=true
SWARM_CAPTURE_OUTPUTS=true
SWARM_CAPTURE_ARTIFACTS=true
SWARM_CAPTURE_ACTIVE_CONTEXT=true
SWARM_OBSERVABILITY_ENABLED=true
SWARM_STREAM_REPLAY_ENABLED=true
```

### Production Minimum

Database persistence with encryption enabled, conservative capture (nothing stored by default), and observability active. Run `swarm:prune` on a schedule to manage retention.

```php
# .env (production)
SWARM_PERSISTENCE_DRIVER=database
SWARM_ENCRYPT_AT_REST=true
SWARM_CAPTURE_INPUTS=false
SWARM_CAPTURE_OUTPUTS=false
SWARM_CAPTURE_ARTIFACTS=false
SWARM_CAPTURE_ACTIVE_CONTEXT=false
SWARM_OBSERVABILITY_ENABLED=true
SWARM_OBSERVABILITY_FAILURE_POLICY=swallow
SWARM_AUDIT_FAILURE_POLICY=swallow
```

Schedule in `routes/console.php`:

```php
Schedule::command('swarm:prune')->daily();
```

### Full Durable Production

Everything from the production minimum, plus the durable relay scheduled, recovery enabled, and webhook authentication configured. See [durable-execution.md](durable-execution.md) for full setup steps.

```php
# .env (durable production)
SWARM_PERSISTENCE_DRIVER=database
SWARM_ENCRYPT_AT_REST=true

# Durable relay
SWARM_DURABLE_RELAY_LIMIT=200
SWARM_DURABLE_RELAY_RESERVATION_TIMEOUT_SECONDS=60

# Recovery
SWARM_DURABLE_RECOVERY_GRACE_SECONDS=300

# Webhook auth (if accepting external webhook signals)
SWARM_WEBHOOKS_ENABLED=true
SWARM_WEBHOOK_AUTH_DRIVER=signed
SWARM_WEBHOOK_SECRET=your-secret-here
SWARM_WEBHOOK_TOLERANCE_SECONDS=300
```

Schedule in `routes/console.php`:

```php
Schedule::command('swarm:relay')->everyMinute();
Schedule::command('swarm:recover')->everyFiveMinutes();
Schedule::command('swarm:prune')->daily();
```

### High-Volume

Route durable advance jobs, parallel branches, and hierarchical coordination to dedicated queues and connections so they do not compete with your application's default queue workers. Use table name prefixes if you need to isolate swarm tables from other package tables in the same database.

```php
# .env (high-volume)
SWARM_QUEUE_CONNECTION=redis-swarm
SWARM_QUEUE=swarm-default

SWARM_DURABLE_QUEUE_CONNECTION=redis-swarm
SWARM_DURABLE_QUEUE=swarm-durable

SWARM_DURABLE_PARALLEL_QUEUE_CONNECTION=redis-swarm
SWARM_DURABLE_PARALLEL_QUEUE=swarm-parallel

SWARM_QUEUE_HIERARCHICAL_PARALLEL_CONNECTION=redis-swarm
SWARM_QUEUE_HIERARCHICAL_PARALLEL_NAME=swarm-hierarchical
SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_CONNECTION=redis-swarm
SWARM_QUEUE_HIERARCHICAL_PARALLEL_BRANCH_NAME=swarm-branches
SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_CONNECTION=redis-swarm
SWARM_QUEUE_HIERARCHICAL_PARALLEL_RESUME_NAME=swarm-resume

# Optional: isolate table names with a prefix
SWARM_DURABLE_RUNS_TABLE=app_swarm_durable_runs
SWARM_DURABLE_OUTBOX_TABLE=app_swarm_durable_outbox
```

Ensure dedicated queue workers are running for each queue name:

```bash
php artisan queue:work redis-swarm --queue=swarm-durable,swarm-parallel,swarm-hierarchical,swarm-branches,swarm-resume,swarm-default
```
