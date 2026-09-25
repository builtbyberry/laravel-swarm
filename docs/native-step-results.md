# Native Step Results

Every completed `SwarmStep` exposes a bounded `NativeStepResult` projection of
the Laravel AI response that completed that step:

```php
$response = ArticlePipeline::make()->prompt('Draft the article.');

$native = $response->steps[0]->nativeResult;

$native?->structured;
$native?->reasoning;
$native?->provider;
$native?->model;
$native?->invocationId;
```

This is a plain-data, versioned projection. It is not the native response
object, and it is not a provider-continuation surface.

No existing result symbol is replaced or removed. `SwarmStep::output`,
`SwarmStep::usage`, and `SwarmStep::citationEvidence` remain the compatibility
surfaces for final text, final usage, and citations. Their native companion is
`SwarmStep::nativeResult`, whose value is `NativeStepResult`. The smallest
remaining adapter is the internal `NativeStepResultProjector`: it converts a
completed Laravel AI `AgentResponse` into that bounded plain-data contract once,
before any queue, process, event, or persistence boundary.

## What Is Projected

Format version `1` uses these live properties and wire keys:

| Live property | Wire key | Meaning |
| --- | --- | --- |
| `formatVersion` | `format_version` | Projection schema version, currently `1`. |
| `status`, `reasons` | `status`, `reasons` | Availability and any limit, capture, legacy, malformed, version, or decrypt reason. |
| `structured` | `structured` | Typed nested scalar/array response data within configured bounds. |
| `reasoning` | `reasoning` | Final native reasoning string, when supplied. |
| `provider`, `model` | `provider`, `model` | Native response identity. |
| `invocationId` | `invocation_id` | Laravel AI invocation identity, separate from Swarm run, node, branch, step, and attempt IDs. |
| `conversationId`, `userMessageId`, `assistantMessageId` | `conversation_id`, `user_message_id`, `assistant_message_id` | Native row identities, when supplied. Missing IDs stay missing. |
| `generationSteps` | `generation_steps` | Entries may contain `text`, `structured`, `reasoning`, `finish_reason`, `provider`, `model`, and their own `usage` map. |
| `tools` | `tools` | Entries contain `call_id`, `result_id`, `name`, and `status` (`pending`, `succeeded`, `denied`, or `failed`). |

`status` is one of `available`, `partial`, `redacted`, `omitted`, or
`unavailable`. `reasons` explains limits, capture policy, malformed data,
unsupported versions, decryption failure, or legacy/custom storage gaps.

The projection deliberately omits raw Laravel AI objects, raw provider
responses, message objects, replay blocks, unrestricted provider payloads,
tool arguments/results/errors, pending approval objects, encrypted reasoning
continuation data, and thought signatures. Those values are either executable
continuation state, unbounded/private provider data, or already owned by a
more precise Swarm surface.

Final response usage is not copied into `NativeStepResult`; it remains on
`SwarmStep::usage` and the aggregate response usage. Final citations likewise
remain on `SwarmStep::citationEvidence` and the response citation evidence.
Generation snapshots retain only their own native usage report so applications
can inspect provider generations without counting final usage twice.

Swarm's existing unknown and mixed historical usage rules are unchanged. Native
step results do not call `TextUsage::add()`, relabel historical keys, turn
missing values into zero, or claim that generation reports form a complete
aggregate. See [usage accounting](../UPGRADING.md#usage-accounting-and-stored-evidence).

## Live And Persisted Availability

Fresh sync, process, queue, and durable callers receive the full bounded
projection regardless of history capture policy. Supported streaming exposes
the capture-shaped, transport-bounded `swarm_step_end` projection and rebuilds
`StreamedSwarmResponse` from that event. Persistence uses the existing
output-capture decision independently:

| Output capture | Live `SwarmStep` | History/checkpoint/replay |
| --- | --- | --- |
| Full | Full bounded projection; event-bounded in streams | Full bounded projection; event-bounded in replay |
| Redact | Full bounded projection; Redact in stream events | Operational identity/status only; content and native conversation/message IDs are removed |
| Shipped `swarm.capture.outputs=false` | Full bounded projection; Redact in stream events | Redacted operational identity/status projection |
| Custom policy returns Skip | Full bounded projection; omitted in stream events | Status-only `omitted` marker; no native-result payload |

The shipped boolean capture policy maps `false` to Redact, never Skip. Redacted
persistence retains provider/model, invocation ID, generation finish
reason/provider/model/usage, and tool identity/status. It removes structured
values, reasoning, generation text/structured/reasoning, and native
conversation/message row IDs. Only a custom `CapturePolicy` returning Skip
stores the status-only omitted marker, which remains distinguishable from an old
row that never had this feature.

`SwarmStepCompleted` and `swarm_step_end` carry the capture-shaped projection.
The completed in-process response retains the live projection. Persisted stream
replay returns the capture-shaped event, not the live value.

Normally completed steps retain the full bounded live projection. A non-final
streamed step reused from a prior checkpoint has no new native response, so its
live `nativeResult` is the stored capture-shaped projection: Full resumes as
Full, Redact as Redact, and Skip as omitted. Withheld data is never reconstructed.

## Execution Modes

The same projection is carried through:

- synchronous sequential, parallel, generated hierarchical, and static
  hierarchical execution;
- real process-concurrency worker return values;
- queued whole-workflow and coordinated hierarchical execution;
- durable steps, branches, node outputs, retries, and durable streaming; and
- supported sequential, generated hierarchical, and static hierarchical live
  streams, including persisted replay.

Top-level parallel live streaming remains unsupported. Native result access
does not add a new execution mode.

Queue and process boundaries serialize only the bounded array projection.
Laravel AI response objects are never serialized. A missing native ID is never
replaced with a Swarm identity or inferred by matching arguments.

## Bounds

The projection is bounded before it can cross a process boundary or enter a
store:

| Key | Default | Hard ceiling |
| --- | --- | --- |
| `swarm.native_results.max_bytes` | 256 KiB | 16 MiB |
| `swarm.native_results.max_event_bytes` | 4 KiB | 8 KiB |
| `swarm.native_results.max_generation_steps` | 64 | 4,096 |
| `swarm.native_results.max_tool_statuses` | 256 | 4,096 |
| `swarm.native_results.max_structured_depth` | 32 | 128 |
| `swarm.native_results.max_structured_items` | 4,096 | 100,000 |
| `swarm.native_results.max_reasoning_bytes` | 64 KiB | 1 MiB |

Configured limits are clamped to the hard ceilings above. Count and depth
limits have a minimum of one; total and event byte limits have a 256-byte minimum so
the version, status, and truncation reason can always be represented. When the
total limit is reached, optional fields and whole native identifiers are
omitted in a deterministic order. Identifiers are never shortened into values
that could be mistaken for real native IDs.

Other configured values below one clamp to one; all values above their ceiling
clamp to the ceiling.
When a bound is reached, the result becomes `partial` with reason `limit`.
The total-byte guard removes content sections conservatively instead of
truncating JSON into an invalid or misleading shape.
The event bound limits only the native-result portion of a broadcastable
`swarm_step_end` and uses reason `transport_limit`. `StreamedSwarmResponse` and
persisted replay both reflect that event-safe shape; non-streaming live responses
retain the ordinary `max_bytes` projection.

## Database Storage, Transactions, And Retention

The v0.28 migration adds nullable `native_result_status` and `native_result`
columns to the configured history-step, durable-branch, durable-node-output,
and stream-step-checkpoint tables. Stream replay stores its projection inside
the existing event payload envelope.

With database persistence and `swarm.persistence.encrypt_at_rest=true`, every
non-omitted native-result envelope is sealed as one `sw0:` value. The status
column remains readable for availability checks. An unreadable envelope returns
`unavailable` with reason `decrypt_failed`; malformed and unsupported payloads
degrade similarly without exposing ciphertext.

The projection is written in the same transaction/upsert as its owning step,
branch, node output, or stream checkpoint. It does not introduce a second store
or an eventually consistent side write. A retry replaces the owning checkpoint
or node record under the existing identity rules.

Native-result rows use their owner's retention lifecycle. `swarm:prune` removes
them with run history, durable state, checkpoints, and hot replay rows. Cold
stream archives are application-owned and are not deleted by `swarm:prune`;
apply the deletion and legal-hold policy in the
[streaming substrate operator runbook](operator-runbook-streaming-substrate.md).
The projection does not create a separately prunable table.

Custom history stores remain source-compatible and receive the capture-shaped
`NativeStepResult` on each `SwarmStep`; persisted parity is implementation-defined.
They must serialize and rehydrate the versioned projection if their read API
promises it. Swarm cannot synthesize data a custom history reader omits.

Custom durable stores may implement `StoresDurableNativeStepResults` (which
extends the citation-evidence capability), and custom checkpoint stores may
implement `NativeResultAwareStreamStepCheckpointStore`. Built-in database stores
also implement `ChecksNativeStepResultStorage` so execution and `swarm:health`
fail before provider work when columns are missing. `unavailable` /
`custom_store` is synthesized only at durable or checkpoint boundaries whose
custom store lacks the optional native-result capability.

## Deployment And Rollback

Run the v0.28 migration and deploy readers before upgraded workers write native
results. Restart long-lived queue workers after deployment. The columns are
additive and nullable, so existing rows read as `unavailable` with reason
`legacy`.

Code rollback is unsafe after native-result payloads are written while any
P3-written step, branch, node, or checkpoint identity can be resumed or retried.
A pre-v0.28 writer updates the old columns but omits the new nullable columns,
which can leave stale native evidence attached to a newer output. Retaining the
columns only lets older readers ignore the schema; it does not make mixed writers
safe.

Before code rollback, stop intake, drain or terminate active queued, durable,
and streamed work, preserve or deliberately clean affected evidence, deploy the
old code to every process, and restart every long-lived worker before resuming
intake. Schema rollback remains destructive: wait through retention or run
`swarm:prune`, verify no native-result evidence is required, and only then run
the migration `down()`.

Include native-result columns and nested stream/cold-event envelopes in an
`APP_KEY` rotation inventory. See [APP_KEY rotation](app-key-rotation.md).

## Native Conversation Privacy

Conversation and message row IDs are correlation identifiers, not authorization.
Do not expose them to a caller merely because that caller can inspect a Swarm
run. Apply application authorization and tenancy rules to history and event
readers. Redacted persistence removes these IDs. The shipped false capture flag
is Redact; only a custom Skip decision stores no projection payload.

Laravel AI conversation rows remain application-owned. Swarm does not copy,
seal, prune, authorize, or migrate their message content through this feature.
See [native conversation upgrade](native-conversation-upgrade.md).
