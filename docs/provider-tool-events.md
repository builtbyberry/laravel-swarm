# Provider-tool activity

Laravel AI owns provider tool execution. Swarm preserves observed native
`ProviderToolEvent` activity as
[`SwarmProviderToolEvent`](../src/Streaming/Events/SwarmProviderToolEvent.php),
with the event type `swarm_provider_tool_event`.

```php
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmProviderToolEvent;

foreach (ResearchSwarm::make()->stream('Research the subject') as $event) {
    if ($event instanceof SwarmProviderToolEvent) {
        // providerType/providerStatus describe the native observation.
        // payload->status says whether its data is available.
        $data = $event->payload->data;
    }
}
```

## Availability by execution mode

| Path | Observed provider activity |
| --- | --- |
| Ordinary sequential `stream()` | Final agent only; earlier agents run through `prompt()`. |
| Opt-in top-level parallel `stream()` | Every process branch streams provider activity with explicit branch/attempt/sequence identity. Native IDs remain unchanged and may repeat across branches. |
| Static/generated hierarchy | Streamed worker nodes and sequential-mode parallel branches. Generated coordinators and concurrent-mode parallel branches use `prompt()` and provide no progress stream. |
| `broadcast()`, `broadcastNow()`, `broadcastOnQueue()` | Same events and capture policy as their underlying supported stream path. |
| Completed streamed response | The existing `events` collection retains observed events in order. No second reconstruction from native completed-response events. |
| Durable execution with `#[DurableStreaming]` | Applicable per-node and parallel-branch streams retain observed activity in the causal log. |
| `prompt()`, ordinary `queue()`, nonstreaming durable nodes | No progress events are fabricated. |
| `Swarm::fake()` | Records lazy stream/broadcast intent and emits its configured response fixture; it does not simulate native provider activity. Use a native stream fixture to exercise mapping. |

No new topology or provider runtime is introduced. Native approval continuation
remains unsupported and fail-closed. A provider status such as `completed` or
`result_received` does **not** prove tool execution succeeded. For example,
Laravel AI can emit an OpenAI item with event status `completed` whose native
`data.status` is `failed`; both remain distinct under Full capture. Anthropic
server-tool result blocks can contain error/denied details. Swarm does not
invent function-tool call/result pairs or convert these observations into a
workflow success or failure.

## Identity and ordering

The public event array carries `id` (native event ID), `item_id`,
`provider_type`, `provider_status`, nullable `provider`, native `timestamp`,
`invocation_id`, `run_id`, `step_index`, `agent_class`, `node_id`, and
`attempt_epoch`. Invocation comes directly from the native object, including
when its own `toArray()` omits it. Unknown provider types/statuses are opaque
strings; Swarm does not infer their meaning.

`type` is Swarm's discriminator. `provider_type` and `provider_status` retain
native semantics. Item IDs can repeat across invocations, nodes and attempts;
never use `item_id` alone as a workflow identity.

A separate opaque `causal_id` scopes native event identity to its run, step,
node, attempt and invocation. It is derived from current attribution, preserved
across serialization and replay, and used by the causal log's `event_uuid`
index. When explicitly voiding a provider event through `CausalLogStore`, pass
`$event->causalId()` as the target. Other event types retain their existing
causal ID convention. Do not replace native IDs with this causal identifier.

Raw replay retains append order and repeated delivery as repeated observations.
It does not deduplicate by item ID or generate a second copy at completion.
Causal presentation ordering remains a separate view option.

## Capture and bounds

The existing `CapturePolicy::outputs()` decision, including the run context and
actor, governs the payload. The envelope uses `data_status`, `data_reasons`, and
`data`:

| Status | Meaning |
| --- | --- |
| `available` | JSON-safe data, including genuinely empty `[]`. |
| `partial` with `limit` | This event's whole data was withheld by a byte/depth budget. Identity and status remain. |
| `redacted` | All data and arbitrary keys withheld; serialized `data` is `[]`. |
| `omitted` | Data withheld and the `data` key absent. |
| `unavailable` | Unsupported, malformed, or undecipherable data; static reason only. |
| `unknown` | Legacy/missing availability envelope; no assertion of empty evidence. |

Redact deliberately withholds the **entire** arbitrary structure, including
keys, because provider data can put sensitive text in keys. Recursive key
retention was rejected for this event. This follows citation envelope
withholding; existing function-tool/reasoning capture behavior is unchanged.
Skip and Redact decide before inspecting payload values or serialization hooks.
Native identity/type/status fields remain structural metadata under every policy.
Treat them as potentially identifying when publishing to clients.

Full capture accepts bounded arrays and JSON scalar values. Objects/resources,
non-finite numbers, invalid UTF-8 (including keys), and malformed envelopes
become unavailable without invoking object serialization or copying protected
values into exception messages. Recursive/deep or oversized data is withheld
with `partial`/`limit`. Data is never cut into misleading fragments.

`config/swarm.php` provides:

- `provider_tools.max_event_bytes`: 65,536 JSON data bytes per event, capped at 1 MiB.
- `provider_tools.max_step_bytes`: 262,144 captured data bytes per streamed step,
  capped at 16 MiB. Counters are local to each streamed execution.
- `provider_tools.max_depth`: 32 nested levels, capped at 64.

Zero/negative integer budgets withhold data as limits require. Non-integer values
use defaults; values above the hard ceilings are clamped. These are data-volume
limits, **not an event-count cap**. Identity envelopes continue in order after a
data limit; existing stream/context-growth controls still apply. Persisted readers
accept the fixed hard ceilings rather than reinterpreting historical evidence
under a subsequently lowered runtime setting.

## Storage, replay and retention

The existing event tables are sufficient; v0.26.3 adds no migration. Database
stream/causal rows seal the entire new data envelope in `provider_tool_evidence`
using the existing `SwarmPersistenceCipher` and `encrypt_at_rest` setting. Cold
raw rows retain the envelope; cold fold snapshots follow existing snapshot
sealing. Disabling encryption stores plaintext. Cache replay is capture-filtered
plaintext: it does not gain database encryption. Structural event metadata stays
queryable/unsealed. This is not blanket encryption of every existing stream field.

A lost key or malformed envelope is reported as unavailable, never genuinely
empty data. New data does not enter unsealed run-history metadata, tool snapshots,
or telemetry. Run history remains a summary; use replay for the event timeline.
In-memory and persisted replay never invoke an agent to reconstruct activity.
Existing replay write `fail`/`continue` behavior remains: continue discards partial
persisted playback when a write fails.

Hot event retention follows the existing `swarm:prune` run retention rules.
**Cold archives intentionally outlive run history** and are exempt from TTL pruning
as long-term audit records. Set an application retention policy for them. Explicit
`StreamEventStore::forget($runId)` on the tiered database store deletes both hot
and cold data, including provider payloads. A replay query may still find a cold
archive after ordinary history pruning. Do not equate history deletion with cold
evidence deletion.

## Durable attempts and transaction boundaries

Provider activity during an attempt is provisional. Use `CausalLogView` with
`ViewSupersession::Clean` for effective activity; raw replay and `Everything` are
audit surfaces that retain invalidated observations. `Everything` wraps invalidated
activity in `VoidedEvent` with `NodeReexecuted`.

Recovery emits `swarm_provider_tool_attempt_invalidated`, an internal control
marker carrying `node_id` and `before_epoch`. It invalidates provider observations
from earlier attempts of that node even when the old worker emits its first event
late, or an event arrives after a seal. Repeated markers have the same monotonic
meaning. Sibling nodes and current attempts remain separate. This marker represents
orchestration control, not provider tool execution, and remains active when the
streaming emission kill-switch is off.

Event appends are independent progress writes before step completion. The caller's
durable lease orders invalidation before fresh emission; the seal remains inside
the lease-fenced checkpoint transaction. A failed checkpoint leaves provisional
activity for recovery to invalidate. Existing void-edge seal checks remain intact.
These rules do not cancel provider calls or make external effects exactly once.

New durable rows retain storage-only attempt metadata for ordinary event anchors
as well as provider events. Cold graduation also copies the authoritative hot
attempt column from older rows before reclaiming them. The cold graduation/base
pointer update stays in one transaction; hot reclaim follows its success. This
preserves attempt attribution without changing older event types' public arrays.

## Mixed versions and rollback

Upgrade event consumers and workers together. Older database/cold readers skip the
unknown new event types; cache/custom readers can expose `SwarmUnknownEvent`.
Skipping is not evidence-preserving rollback. Older workers also cannot preserve
provider events they never mapped, and older folds do not apply the new late-event
watermark. Keep an immutable backup and the new readers available for audit; pause
and drain streaming work before rolling workers back. Restore v0.26.3 readers to
interpret retained evidence. Do not run down migrations for this release. Historical
cold data whose attempt attribution was already discarded cannot be reconstructed
by this upgrade.
