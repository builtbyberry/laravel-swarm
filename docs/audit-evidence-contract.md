# Audit Evidence Contract

Laravel Swarm emits structured audit evidence records from the swarm runtime,
operator commands, durable wait/signal flows, and webhook idempotency paths.
Evidence is routed through the `SwarmAuditSink` contract, which defaults to a
no-op implementation. Applications bind a custom sink to route evidence into an
append-only store, queue listener, SIEM export, or object-storage archive.

## Operational Storage vs Audit Evidence

Swarm database tables are **operational workflow storage** — they use TTL-based
retention and can be pruned. They are not an immutable compliance archive.
Audit evidence is a separate concern:

- **Operational tables** store run state, steps, context, and durable cursors.
  They are queryable until pruned and support the runtime.
- **Audit evidence** captures what happened and when in a stable, append-only
  payload stream. Evidence is owned and retained by the application.

True immutability, legal hold, retention lock, and chain-of-custody controls
are application or infrastructure responsibilities. The Swarm audit evidence
contract provides the raw material for building those systems.

## Enabling and Configuring the Sink

Bind your implementation in your service provider before the swarm runtime
is exercised:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

$this->app->bind(SwarmAuditSink::class, MyAppAuditSink::class);
```

The contract is a single `emit(string $category, array $payload): void` method.
Every emitted payload includes `schema_version`, `category`, and `occurred_at`
merged in automatically by `SwarmAuditDispatcher`.

### Failure Policy

Sink exceptions are isolated from swarm execution. Control the behavior with
`swarm.audit.failure_policy` (`SWARM_AUDIT_FAILURE_POLICY`):

| Policy    | Behavior                                                           |
|-----------|--------------------------------------------------------------------|
| `swallow` | Silently discard the exception (default — safest for production).  |
| `log`     | Record the exception via the application logger, then continue.    |

Sink failures **never** fail or corrupt swarm execution regardless of policy.

## Evidence Payload Schema

Every payload is an array with the following invariant fields:

| Field            | Type   | Notes                                    |
|------------------|--------|------------------------------------------|
| `schema_version` | string | Currently `"1"`. Increments on breaking changes to the stable payload shape. |
| `category`       | string | Event category (see below).              |
| `occurred_at`    | string | ISO-8601 timestamp of the emission.      |

Additional fields depend on the category. Correlation fields present in every
category that carries them are listed below.

### Common Correlation Fields

| Field            | Present when                          |
|------------------|---------------------------------------|
| `run_id`         | All run, step, durable, wait, and signal categories. |
| `parent_run_id`  | Set when the run was spawned as a child run. |
| `swarm_class`    | FQCN of the swarm.                    |
| `topology`       | `sequential`, `parallel`, or `hierarchical`. |
| `execution_mode` | `run`, `queue`, `stream`, or `durable`. |
| `status`         | Current outcome of the event.         |

## Evidence Categories

### Run Lifecycle

| Category        | Description                                                  |
|-----------------|--------------------------------------------------------------|
| `run.started`   | A swarm run was started (synchronous, queued, streamed, or durable). |
| `run.completed` | A swarm run completed successfully.                          |
| `run.failed`    | A swarm run failed with an exception.                        |

`run.failed` includes `exception_class` and `duration_ms`.
`run.completed` includes `duration_ms`.

### Step Lifecycle

| Category          | Description                                         |
|-------------------|-----------------------------------------------------|
| `step.started`    | An individual agent step began execution.           |
| `step.completed`  | An individual agent step completed.                 |

Both include `step_index` and `agent_class`.
`step.completed` includes `duration_ms`, `metadata_keys`, and allowlisted
`metadata`.

### Durable State Transitions

| Category                         | Description                                                |
|----------------------------------|------------------------------------------------------------|
| `durable.checkpointed`           | Sequential durable run advanced to the next step.          |
| `durable.checkpointed_hierarchical` | Hierarchical durable run advanced to the next node.     |
| `durable.paused`                 | Durable run was paused at a step boundary.                 |
| `durable.pause_requested`        | Pause was requested via operator command or API. `immediately_paused: bool` indicates whether the run paused immediately or at next boundary. |
| `durable.resumed`                | Durable run was resumed from paused state.                 |
| `durable.cancelled`              | Durable run was cancelled at a step boundary.              |
| `durable.cancel_requested`       | Cancel was requested. `immediately_cancelled: bool` indicates whether the run cancelled immediately. |
| `durable.completed`              | Durable run finished successfully.                         |
| `durable.failed`                 | Durable run failed. Includes `exception_class`, `timed_out`, and `duration_ms`. |

### Durable Wait and Signal

| Category          | Description                                                       |
|-------------------|-------------------------------------------------------------------|
| `wait.created`    | A durable wait was registered. Includes `wait_name`, `reason`, and `timeout_seconds`. |
| `signal.received` | A signal arrived. Includes `signal_name`, `accepted`, `duplicate`, and `status`. |

`accepted: true` means the signal released a waiting run. `accepted: false`
means the run was not in a waiting state; the signal is recorded but did not
advance execution.

### Operator Commands

| Category          | Description                                                    |
|-------------------|----------------------------------------------------------------|
| `command.pause`   | `swarm:pause` was invoked for a run.                           |
| `command.resume`  | `swarm:resume` was invoked for a run.                          |
| `command.cancel`  | `swarm:cancel` was invoked for a run.                          |
| `command.recover` | `swarm:recover` was invoked. Includes `recovered_count` and `recovered_run_ids`. |
| `command.prune`   | `swarm:prune` completed. Includes `dry_run`, `prevent_prune`, `status`, and `counts` (row counts per table). |

All command categories include `actor: "artisan"`.
Failed pause, resume, cancel, and recover attempts emit the same command
category with `status: "failed"` and `exception_class`, then rethrow so console
behavior remains unchanged.

### Webhook Idempotency

| Category                  | Description                                            |
|---------------------------|--------------------------------------------------------|
| `webhook.start_accepted`  | Start webhook reserved a new run.                      |
| `webhook.start_duplicate` | Start webhook returned a duplicate (idempotency hit).  |
| `webhook.start_conflict`  | Start webhook rejected due to conflicting request hash.|
| `webhook.start_in_flight` | Start webhook rejected because another request is in flight. |
| `webhook.start_failed`    | Start webhook encountered an exception. Includes `exception_class`. |
| `webhook.signal_received` | Signal webhook processed an inbound signal.            |

All webhook categories include `swarm_class`, `has_idempotency_key`, and
`status`. Start categories also include `run_id` where available.

## Redaction and Capture Alignment

Evidence payloads respect the same capture policy as the rest of the runtime.
Raw prompt text and agent outputs are **not** included in any evidence payload.
Arbitrary run and step metadata values are default-deny: audit evidence includes
`metadata_keys` for top-level diagnostics and includes `metadata` values only for
top-level keys configured in `swarm.audit.metadata_allowlist`
(`SWARM_AUDIT_METADATA_ALLOWLIST`, comma-separated). Nested allowlisting is not
supported; if you allowlist a top-level key, its value is emitted as-is.

For example:

```php
'audit' => [
    'metadata_allowlist' => ['tenant_id', 'workflow_type'],
],
```

## Versioning

The `schema_version` field is `"1"` for all initial evidence categories. When
any stable payload field is removed or its type changes, `schema_version` will
increment and the change will be documented in the changelog.

Adding new optional fields does not increment `schema_version`. Applications
should handle unknown keys gracefully.

## Implementing a Custom Sink

### Append-Only Database Table

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

class DatabaseAuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        DB::table('swarm_audit_log')->insert([
            'category'       => $category,
            'run_id'         => $payload['run_id'] ?? null,
            'schema_version' => $payload['schema_version'],
            'occurred_at'    => $payload['occurred_at'],
            'payload'        => json_encode($payload),
            'created_at'     => now(),
        ]);
    }
}
```

### Queue Listener (fire-and-forget)

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

class QueuedAuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        SendAuditEvidenceToSiem::dispatch($category, $payload);
    }
}
```

### Object Storage Archive

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

class S3AuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        $runId = $payload['run_id'] ?? 'no-run-id';
        $name = hash('sha256', $category.'|'.$payload['occurred_at'].'|'.json_encode($payload));

        Storage::disk('s3-audit')->put(
            "swarm/evidence/{$category}/{$runId}/{$payload['occurred_at']}-{$name}.json",
            json_encode($payload),
        );
    }
}
```

## Production Checklist for Regulated Environments

- [ ] Bind a custom `SwarmAuditSink` implementation in a service provider.
- [ ] Choose an append-only target (database with `INSERT`-only grants, object
  storage, SIEM, or audit-log service).
- [ ] Set `swarm.audit.failure_policy` to `log` if silent failures are not
  acceptable in your compliance model.
- [ ] Confirm that your sink does not expose raw prompt/output content; evidence
  payloads never include them, but verify your sink does not add them.
- [ ] Configure `swarm.audit.metadata_allowlist` only for top-level metadata keys
  approved for evidence export.
- [ ] Implement a periodic test that emits a sentinel evidence record and
  verifies it arrives in your audit target.
- [ ] Establish a legal-hold workflow that protects archived evidence records
  from deletion independent of `swarm:prune` scheduling.
- [ ] Rotate `APP_KEY` in coordination with your encryption-at-rest plan for
  database-persisted operational rows; archived evidence payloads are not
  affected by key rotation.
