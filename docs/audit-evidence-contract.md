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

Sink exceptions are routed through a `SinkFailureHandler` (see [Sink Failure
Handler](#sink-failure-handler) below). The default handler reads
`swarm.audit.failure_policy` (`SWARM_AUDIT_FAILURE_POLICY`):

| Policy    | Behavior                                                                 |
|-----------|--------------------------------------------------------------------------|
| `swallow` | Silently discard the exception (default — safest for production).        |
| `log`     | Record the exception via the application logger, then continue.          |
| `halt`    | Log the exception, then throw `AuditSinkHaltedException` to fail the run.|

`swallow` and `log` keep swarm execution isolated from audit-write failures.
`halt` is opt-in for regulated workloads that must not continue when evidence
cannot be emitted.

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
| `topology`       | `sequential`, `parallel`, `hierarchical`, or `static_hierarchical`. |
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
| `command.relay`   | `swarm:relay` completed or failed. Always includes `dispatched_count`, `skipped_count`, `failed_count`, `claimed_count` (rows reserved in phase 1), `reclaimed_count` (of those, rows with a stale prior reservation), `types`, `limit`, `drain_until_empty`, `max_attempts` (null when not set), `attempts` (number of drain iterations executed), and `actor`. Status values: `dispatched` (at least one entry dispatched, no transient failures at exit); `skipped` (only permanently invalid entries processed, none dispatched); `none_found` (outbox was empty); `transient_failure` (one or more entries could not be dispatched due to a transient queue error — `failed_count` is > 0, entries remain in the outbox for reclaim); `error` (an unhandled exception escaped the drain loop — includes `exception_class`). Note: `exception_class` is present only on `status: "error"` events. Individual `run_id`s of permanently deleted rows (counted in `skipped_count`) are not in the audit payload — they are in the application error tracker via `report()`, where the exception message includes the outbox entry ID. Cross-reference by entry ID for post-incident reconstruction. |
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

## Frozen Schema for the v0.x Line

The following envelope shape is frozen for the entire `0.x` release line.
Sinks that rely on these fields will continue to receive them, with the same
names and types, across every `0.x` release. A breaking change to any frozen
field — removal, rename, or type change — is reserved for a `schema_version`
bump and the `1.0` major boundary.

The frozen surface covers two things: the **envelope** that every payload
carries, and the **set of evidence categories** the package emits along with
their guaranteed correlation fields.

### Stability Promise

- The frozen envelope fields, the frozen list of category names, and the
  correlation fields enumerated below will not change within `0.x` without a
  `schema_version` bump.
- New categories may be added in any `0.x` minor release. Sinks MUST tolerate
  unknown categories.
- New optional fields may be added to existing categories in any `0.x` minor
  release. Sinks MUST tolerate unknown keys.
- Existing fields will not be removed, renamed, or repurposed within `0.x`.
- Pre-1.0 semver permits a `1.0` major bump to break the schema. The v0.x
  promise does not extend across the `1.0` boundary; the upgrade guide for
  `1.0` will enumerate any envelope changes alongside a `schema_version`
  increment.

### Frozen Envelope Fields

Every payload, in every category, carries the following top-level fields:

| Field            | Type   | Notes                                                                 |
|------------------|--------|-----------------------------------------------------------------------|
| `schema_version` | string | `"1"` for the v0.x line. Bumps signal a breaking envelope change.     |
| `category`       | string | One of the frozen category names below.                               |
| `occurred_at`    | string | ISO-8601 timestamp of the emission.                                   |

In addition, on every category whose emit site routes metadata through
`SwarmAuditDispatcher::metadata()` (run, step, durable runtime, and durable
checkpoint categories), the envelope carries:

| Field           | Type                  | Notes                                                                 |
|-----------------|-----------------------|-----------------------------------------------------------------------|
| `metadata_keys` | array&lt;string&gt;   | All original metadata key names, sorted. Always emitted.              |
| `metadata`      | array&lt;string, mixed&gt; | Allowlisted metadata values plus reserved keys. May be empty.    |

The `metadata` array always includes any reserved keys present on the run,
regardless of the configured allowlist. The reserved set is published as
`EvidenceEnvelope::RESERVED_METADATA_KEYS`. The only reserved key in `0.x` is
`actor`. New reserved keys may be added additively; the constant is the
authoritative list.

`actor` is delivered in one of two slots depending on which emit site
produced the record:

| Source                                         | Slot                |
|------------------------------------------------|---------------------|
| Run, step, durable runtime, and webhook events | `metadata.actor`    |
| Operator commands (`command.*`)                | Top-level `actor`   |

Both slots are frozen for `0.x`. Sinks that need a single read path should
fall back from `metadata.actor` to top-level `actor`.

### Frozen Categories

The package emits the categories below today. Sinks may rely on these names
appearing across the `0.x` line; no frozen category will be renamed or
removed without a `schema_version` bump.

#### Run Lifecycle

| Category        |
|-----------------|
| `run.started`   |
| `run.completed` |
| `run.failed`    |

Correlation fields on every `run.*` event:

| Field            | Type   | Notes                                                          |
|------------------|--------|----------------------------------------------------------------|
| `run_id`         | string | The run identifier.                                            |
| `parent_run_id`  | string&#124;null | Set when the run was spawned as a child.               |
| `swarm_class`    | string | FQCN of the swarm.                                             |
| `topology`       | string | `sequential`, `parallel`, `hierarchical`, or `static_hierarchical`. |
| `execution_mode` | string | `run`, `queue`, `stream`, or `durable`.                        |
| `status`         | string | `started`, `completed`, or `failed`.                           |

Additional frozen fields by category:

| Category        | Additional frozen fields                       |
|-----------------|------------------------------------------------|
| `run.completed` | `duration_ms` (int)                            |
| `run.failed`    | `exception_class` (string), `duration_ms` (int) |

#### Step Lifecycle

| Category          |
|-------------------|
| `step.started`    |
| `step.completed`  |

Correlation fields on every `step.*` event:

| Field            | Type   | Notes                                                          |
|------------------|--------|----------------------------------------------------------------|
| `run_id`         | string |                                                                |
| `parent_run_id`  | string&#124;null |                                                      |
| `swarm_class`    | string |                                                                |
| `topology`       | string |                                                                |
| `execution_mode` | string |                                                                |
| `step_index`     | int    | Zero-based step index within the run.                          |
| `agent_class`    | string | FQCN of the agent that ran the step.                           |
| `status`         | string | `started` or `completed`.                                      |

Additional frozen fields by category:

| Category         | Additional frozen fields  |
|------------------|---------------------------|
| `step.completed` | `duration_ms` (int)       |

#### Durable Runtime

| Category                              |
|---------------------------------------|
| `durable.checkpointed`                |
| `durable.checkpointed_hierarchical`   |
| `durable.paused`                      |
| `durable.pause_requested`             |
| `durable.resumed`                     |
| `durable.cancelled`                   |
| `durable.cancel_requested`            |
| `durable.completed`                   |
| `durable.failed`                      |

Correlation fields on every `durable.*` event:

| Field            | Type             | Notes                                                          |
|------------------|------------------|----------------------------------------------------------------|
| `run_id`         | string           |                                                                |
| `parent_run_id`  | string&#124;null |                                                                |
| `swarm_class`    | string           | Sourced from the durable run row (non-null since v0.4.1).      |
| `topology`       | string           | Sourced from the durable run row (non-null since v0.4.1).      |
| `execution_mode` | string           | Always `durable` for these categories.                         |
| `status`         | string           | Category-specific status value.                                |

Additional frozen fields by category:

| Category                              | Additional frozen fields                                                            |
|---------------------------------------|-------------------------------------------------------------------------------------|
| `durable.checkpointed`                | `next_step_index` (int)                                                             |
| `durable.checkpointed_hierarchical`   | `next_step_index` (int)                                                             |
| `durable.pause_requested`             | `immediately_paused` (bool)                                                         |
| `durable.cancel_requested`            | `immediately_cancelled` (bool)                                                      |
| `durable.cancelled`                   | `duration_ms` (int) (since v0.4.1)                                                  |
| `durable.completed`                   | `duration_ms` (int)                                                                 |
| `durable.failed`                      | `exception_class` (string), `timed_out` (bool), `duration_ms` (int)                 |

#### Durable Wait and Signal

| Category          |
|-------------------|
| `wait.created`    |
| `signal.received` |

Correlation fields on every `wait.created` and `signal.received` event:

| Field            | Type   | Notes                                                          |
|------------------|--------|----------------------------------------------------------------|
| `run_id`         | string |                                                                |
| `swarm_class`    | string |                                                                |
| `topology`       | string |                                                                |
| `execution_mode` | string |                                                                |
| `status`         | string | Category-specific status value.                                |

Additional frozen fields by category:

| Category          | Additional frozen fields                                                            |
|-------------------|-------------------------------------------------------------------------------------|
| `wait.created`    | `wait_name` (string), `reason` (string&#124;null), `timeout_seconds` (int&#124;null) |
| `signal.received` | `signal_name` (string), `accepted` (bool), `duplicate` (bool)                       |

#### Operator Commands

| Category          |
|-------------------|
| `command.pause`   |
| `command.resume`  |
| `command.cancel`  |
| `command.recover` |
| `command.relay`   |
| `command.prune`   |

Every `command.*` event carries:

| Field    | Type   | Notes                                                          |
|----------|--------|----------------------------------------------------------------|
| `actor`  | string | Always `"artisan"` for emits from the bundled commands.        |
| `status` | string | Category-specific status value.                                |

Additional frozen fields by category:

| Category          | Additional frozen fields                                                                                          |
|-------------------|-------------------------------------------------------------------------------------------------------------------|
| `command.pause`   | `run_id` (string). `exception_class` (string) when `status: "failed"`.                                            |
| `command.resume`  | `run_id` (string). `exception_class` (string) when `status: "failed"`.                                            |
| `command.cancel`  | `run_id` (string). `exception_class` (string) when `status: "failed"`.                                            |
| `command.recover` | `target_run_id` (string&#124;null), `target_swarm_class` (string&#124;null). On success: `recovered_count` (int), `recovered_run_ids` (array&lt;string&gt;). On failure: `exception_class` (string). |
| `command.relay`   | `types` (array&lt;string&gt;), `limit` (int), `drain_until_empty` (bool), `max_attempts` (int&#124;null), `attempts` (int), `dispatched_count` (int), `skipped_count` (int), `failed_count` (int), `claimed_count` (int), `reclaimed_count` (int). |
| `command.prune`   | `dry_run` (bool), `prevent_prune` (bool), `counts` (array&lt;string, int&gt;).                                    |

#### Webhook Idempotency

| Category                  |
|---------------------------|
| `webhook.start_accepted`  |
| `webhook.start_duplicate` |
| `webhook.start_conflict`  |
| `webhook.start_in_flight` |
| `webhook.start_failed`    |
| `webhook.signal_received` |

Every `webhook.start_*` event carries:

| Field                 | Type             | Notes                                                |
|-----------------------|------------------|------------------------------------------------------|
| `swarm_class`         | string           | FQCN of the swarm bound to the webhook route.        |
| `has_idempotency_key` | bool             | Whether the request supplied `Idempotency-Key`.      |
| `status`              | string           | Category-specific status value.                      |

Additional frozen fields by category:

| Category                  | Additional frozen fields                                                  |
|---------------------------|---------------------------------------------------------------------------|
| `webhook.start_accepted`  | `run_id` (string)                                                         |
| `webhook.start_duplicate` | `run_id` (string&#124;null)                                               |
| `webhook.start_failed`    | `exception_class` (string)                                                |

The `webhook.signal_received` envelope is frozen with the following fields:
`run_id` (string), `swarm_class` (string, since v0.4.1), `signal_name` (string),
`accepted` (bool), `duplicate` (bool), `has_idempotency_key` (bool), and
`status` (string).

### What is Not Frozen

The frozen list above is deliberately narrow. The following are out of scope
for the `0.x` stability promise and may change without a `schema_version`
bump:

- Any field returned through `metadata_keys` and `metadata` beyond the
  reserved `actor` key. Application metadata shape is application-owned.
- The exact textual value of `status` strings on categories where the value
  is described as "category-specific" — the field is frozen, the precise
  enumeration is not. Status strings observed today are documented in the
  Evidence Categories tables above; treat unknown values as additive.
- Fields emitted by signers via `SwarmAuditSigner::sign()`. Signers compose
  alongside the frozen envelope; the signature shape is implementation-owned.
- The telemetry envelope emitted by `SwarmTelemetryDispatcher`. Telemetry is
  a sibling stream with its own categories and is not part of this contract.

### Version-Bump Path

When a breaking change to the frozen surface becomes necessary, the package
will:

1. Increment `EvidenceEnvelope::SCHEMA_VERSION` to `"2"` (or the next
   integer).
2. Document the change in `CHANGELOG.md` and add a per-version migration
   block to `UPGRADING.md` enumerating which fields moved, which were renamed
   or removed, and how to read both shapes during a transitional period.
3. Land the bump on a minor or major release boundary as appropriate. A
   breaking envelope change inside `0.x` would land on a minor version
   (`0.N+1.0`) per pre-1.0 semver; a `1.0` release may bundle envelope
   changes with other breaking surfaces.

Sinks that parse the envelope strictly SHOULD switch on `schema_version`
before reading category-specific fields. Sinks that only forward the payload
verbatim do not need to switch.

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

## Audit Extension Points

The audit dispatcher exposes four container-bindable surfaces that compose
inside `SwarmAuditDispatcher::emit()`. Resolution order at run entry and emit
time is: actor resolves once, capture policy decides per category, the signer
(if bound) runs after envelope enrichment, and the failure handler arbitrates
any sink or signing exception.

### Actor Binding

An `Actor` is the identity attached to a swarm run. Every evidence record
includes the resolved actor under the reserved `metadata.actor` key, which is
emitted regardless of the configured metadata allowlist.

`BuiltByBerry\LaravelSwarm\Audit\Actor` is an immutable value object with
`id`, `type`, optional `name`, and optional `metadata`:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;

$system = Actor::system('cron:nightly');
$user   = Actor::user(auth()->user());
$token  = Actor::fromAny('api_token:abc123');
$any    = Actor::fromAny($mixedInput); // Actor | Authenticatable | string
```

Resolution happens once at run entry through the `ActorResolver` contract:

```php
namespace BuiltByBerry\LaravelSwarm\Contracts;

interface ActorResolver
{
    public function resolve(): ?Actor;
}
```

The default binding, `BuiltByBerry\LaravelSwarm\Audit\DefaultActorResolver`,
reads `Context::get('swarm:actor')` first, then falls back to the
authenticated user, then returns `null`. The `Context` lookup is preferred
because Laravel context bindings survive queue serialization; the `auth()`
fallback only works inside a request.

Override the resolver to source identity from request state, signed job
payloads, API token introspection, or any other application-specific surface:

```php
use BuiltByBerry\LaravelSwarm\Contracts\ActorResolver;

$this->app->bind(ActorResolver::class, MyAppActorResolver::class);
```

Callers may also bind an actor directly on the `RunContext` before dispatch.
`RunContext::withActor()` takes precedence over the resolver:

```php
$context->withActor($user);
$context->withActor('api_token:abc123');
$context->withActor(Actor::system('billing-cron'));
```

The `metadata.actor` key is reserved on `EvidenceEnvelope::RESERVED_METADATA_KEYS`.
Reserved keys are always emitted on evidence and telemetry payloads regardless
of `swarm.audit.metadata_allowlist`. Applications cannot override this slot via
user-supplied metadata.

For regulated deployments that treat unattributed runs as a compliance
violation, set `swarm.audit.actor.required=true`
(`SWARM_AUDIT_ACTOR_REQUIRED=true`). A run entering the runner without a
resolvable actor then throws `MissingActorException`:

```php
'audit' => [
    'actor' => [
        'required' => true,
    ],
],
```

The default is `false` to preserve v0.3 behavior.

### Capture Policy

The capture policy decides, per category, whether captured inputs, outputs,
artifacts, and active context are stored as-is, redacted, or skipped. Policies
return a decision; they never see the captured payload itself, so policy code
cannot couple to payload shapes or leak the unredacted payload through its
own logging.

```php
namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

interface CapturePolicy
{
    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision;
}
```

`BuiltByBerry\LaravelSwarm\Audit\CaptureDecision` has three cases:

| Case     | Behavior                                                                 |
|----------|--------------------------------------------------------------------------|
| `Full`   | Store the payload as-is.                                                 |
| `Redact` | Store the payload structure with scalar values replaced by `SwarmCapture::REDACTED`. |
| `Skip`   | Omit the payload from audit and history. In v0.4 the runtime treats `Skip` identically to `Redact` at the field level. Per-field omit lands in v0.5 alongside the audit dispatcher `Skip`-aware path. The contract is locked; custom policies may return `Skip` today to declare intent. |

The default binding, `BuiltByBerry\LaravelSwarm\Audit\BooleanCapturePolicy`,
reads the existing `swarm.capture.*` booleans and returns `Full` when true /
`Redact` when false, preserving v0.3 behavior exactly. The booleans are
deprecated in v0.4 and scheduled for removal in v0.5.

Bind a custom policy to make decisions per-run with context and actor
visibility — for example, capture inputs only for runs initiated by service
accounts, or redact outputs whenever a tenant flag is present:

```php
use BuiltByBerry\LaravelSwarm\Audit\Actor;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\CapturePolicy;
use BuiltByBerry\LaravelSwarm\Support\RunContext;

class TenantAwareCapturePolicy implements CapturePolicy
{
    public function inputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return $actor?->type === 'system'
            ? CaptureDecision::Full
            : CaptureDecision::Redact;
    }

    public function outputs(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Redact;
    }

    public function artifacts(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Redact;
    }

    public function activeContext(?RunContext $context = null, ?Actor $actor = null): CaptureDecision
    {
        return CaptureDecision::Skip;
    }
}
```

```php
$this->app->bind(CapturePolicy::class, TenantAwareCapturePolicy::class);
```

### Audit Signing

The signer slot runs inside `SwarmAuditDispatcher::emit()` after envelope
enrichment (`schema_version`, `category`, `occurred_at`, and any reserved
metadata such as `actor`) and before the payload is handed to the sink. No
signer is bound by default; the dispatcher emits enriched payloads as-is,
matching v0.3 behavior.

```php
namespace BuiltByBerry\LaravelSwarm\Contracts;

interface SwarmAuditSigner
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sign(string $category, array $payload): array;
}
```

Implementations MUST NOT mutate or remove existing keys. Signature fields are
added alongside the existing envelope — conventionally `signature`,
`signature_algorithm`, `signed_at`, and optionally `previous_signature_id` for
chain-signing trails.

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSigner;

class HmacAuditSigner implements SwarmAuditSigner
{
    public function __construct(private readonly string $key) {}

    public function sign(string $category, array $payload): array
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $payload + [
            'signature' => hash_hmac('sha256', $canonical, $this->key),
            'signature_algorithm' => 'hmac-sha256',
            'signed_at' => now()->toIso8601String(),
        ];
    }
}
```

```php
$this->app->bind(SwarmAuditSigner::class, fn () => new HmacAuditSigner(
    config('app.swarm_signing_key'),
));
```

Signing scope, algorithm, canonicalization, and chain-signing semantics are
implementation concerns. A signer may also refuse to sign a given category by
returning the input payload unchanged:

```php
public function sign(string $category, array $payload): array
{
    if (! str_starts_with($category, 'run.')) {
        return $payload;
    }

    // ...sign run.* categories only
}
```

Signing failures route through the bound `SinkFailureHandler`. Strict
no-unsigned-evidence semantics are achieved by setting
`swarm.audit.failure_policy=halt` or by binding a custom handler that
inspects `$exception` to differentiate signing exceptions from sink
exceptions.

### Sink Failure Handler

Sink and signing exceptions are arbitrated by the `SinkFailureHandler`
contract. The handler is the dispatcher's loop control: as long as it returns
`RetryInline`, the dispatcher retries the same emit synchronously.

```php
namespace BuiltByBerry\LaravelSwarm\Contracts;

use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use Throwable;

interface SinkFailureHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision;
}
```

`BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision` has three cases:

| Case          | Behavior                                                            |
|---------------|---------------------------------------------------------------------|
| `Swallow`     | Stop emitting; continue swarm execution.                            |
| `RetryInline` | Retry the same emit synchronously.                                  |
| `Halt`        | Throw `AuditSinkHaltedException` (carries `HaltsSwarmExecution`).   |

The default binding, `BuiltByBerry\LaravelSwarm\Audit\ConfiguredSinkFailureHandler`,
maps `swarm.audit.failure_policy` to a decision:

- `swallow` → `Swallow` (no logging).
- `log` → log via the application logger, then `Swallow`.
- `halt` → log via the application logger, then `Halt`.

Unknown policy values fall back to `Swallow` with a one-time warning, matching
the conservative posture of the v0.3 dispatcher. The default handler never
returns `RetryInline` — retry semantics are reserved for custom handlers.

`AuditSinkHaltedException` carries the `HaltsSwarmExecution` marker interface.
The runner detects the marker on caught exceptions and surfaces the halt as a
deliberate, attributable run-level failure — the history store records the
failure and the exception is rethrown to the dispatch caller. Reserve `Halt`
for regulated workloads that require no-unsigned-evidence or
no-unattributed-evidence semantics; the default audit configuration never
produces a halting exception.

The dispatcher caps retries at `SwarmAuditDispatcher::MAX_HANDLER_ITERATIONS = 5`.
A handler that returns `RetryInline` past that bound triggers a runtime
exception, preventing infinite loops from buggy custom handlers.

Custom handlers may inspect `$exception` to differentiate sink failures from
signing failures and route them through different paths:

```php
use BuiltByBerry\LaravelSwarm\Audit\SinkFailureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\SinkFailureHandler;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;
use Throwable;

class TieredSinkFailureHandler implements SinkFailureHandler
{
    public function __construct(
        private readonly SinkFailureHandler $inner,
        private readonly TransientErrorClassifier $classifier,
    ) {}

    public function handle(
        SwarmAuditSink $sink,
        string $category,
        array $payload,
        Throwable $exception,
    ): SinkFailureDecision {
        if ($this->classifier->isTransient($exception)) {
            return SinkFailureDecision::RetryInline;
        }

        if ($exception instanceof SigningException) {
            return SinkFailureDecision::Halt;
        }

        return $this->inner->handle($sink, $category, $payload, $exception);
    }
}
```

```php
$this->app->bind(SinkFailureHandler::class, TieredSinkFailureHandler::class);
```

The `Queue` and `DeadLetter` cases land in v0.5 alongside the audit outbox
table and the `swarm:relay --type=audit` lane. Adding enum cases later is
non-breaking; handlers that return `Swallow`, `RetryInline`, or `Halt` today
remain valid.

## Metadata Governance

Run metadata is developer-supplied and is not validated or sanitized by the package. By default, metadata values are excluded from audit and telemetry payloads — only key names are included. Use the controls below to decide exactly what reaches your sinks.

> See [Metadata Allowlist Governance](metadata-allowlist-governance.md) for the policy guide — what belongs in metadata, named anti-patterns (raw user identifiers, regulated product names, authentication material), and the review checklist to apply when extending the allowlist.

### Allowlist approach

Set a comma-separated list of allowed metadata key names. Only keys in the list will have their values included in the `metadata` field of sink payloads:

```env
SWARM_AUDIT_METADATA_ALLOWLIST=customer_id,workflow_type
SWARM_OBSERVABILITY_METADATA_ALLOWLIST=customer_id,workflow_type
```

The `metadata_keys` field (the array of all original key names) is always emitted regardless of the allowlist, so you know which keys were present without receiving any values. An empty allowlist (the default) means the `metadata` field in sink payloads is always an empty array.

### Custom sink redaction

For cases where dropping values is not enough — for example hashing a value rather than omitting it — implement a custom `SwarmAuditSink`:

```php
use BuiltByBerry\LaravelSwarm\Contracts\SwarmAuditSink;

class RedactingAuditSink implements SwarmAuditSink
{
    public function emit(string $category, array $payload): void
    {
        if (isset($payload['metadata']['account_number'])) {
            $payload['metadata']['account_number'] = hash('sha256', $payload['metadata']['account_number']);
        }

        // forward $payload to your store
    }
}
```

Bind it in a service provider:

```php
$this->app->bind(SwarmAuditSink::class, RedactingAuditSink::class);
```

The same pattern applies to `SwarmTelemetrySink` via `SWARM_OBSERVABILITY_METADATA_ALLOWLIST`.

### Scope

These controls apply to **sink and telemetry payloads only**. They do not affect what is stored in `RunContext` at runtime or persisted to the database when `capture.active_context` is enabled. For storage-level protection, rely on the conservative capture defaults (all capture off by default) and `SWARM_ENCRYPT_AT_REST=true`.

## Production Checklist for Regulated Environments

- [ ] Bind a custom `SwarmAuditSink` implementation in a service provider.
- [ ] Choose an append-only target (database with `INSERT`-only grants, object
  storage, SIEM, or audit-log service).
- [ ] Set `swarm.audit.failure_policy` to `log` if silent failures are not
  acceptable in your compliance model, or `halt` if runs must hard-fail when
  evidence cannot be emitted.
- [ ] Set `swarm.audit.actor.required=true` if unattributed runs are a
  compliance violation, and bind an `ActorResolver` that sources identity
  from your authentication surface.
- [ ] Bind a `SwarmAuditSigner` if evidence records must be cryptographically
  signed before they reach the sink.
- [ ] Bind a `CapturePolicy` if capture decisions need to vary per run or
  actor rather than via the static `swarm.capture.*` booleans.
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
