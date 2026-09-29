# Vercel and AG-UI protocol projection

Laravel Swarm can project a `stream()` response through Laravel AI's native
[Vercel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk#vercel-ai-sdk-protocol) and
[AG-UI](https://laravel.com/framework/docs/13.x/ai-sdk#agent-user-interaction-protocol) encoders.
Swarm adds only a typed workflow adapter and protocol-specific custom progress
event. Laravel AI continues to own standard message, tool, citation, terminal,
header, and error encoding.

The integration is default off. The config key is
`swarm.streaming.native_protocols.enabled`; its environment alias is:

```dotenv
SWARM_NATIVE_CHAT_PROTOCOLS_ENABLED=true
```

Changing the environment does not bypass Laravel's config cache or reload an
already-running worker. Use the [activation checklist](#activation-and-rollback)
before sending production traffic. Swarm recursively supplies the missing
default to applications with an older published `config/swarm.php` while
preserving their nested overrides; a previously built config cache remains
authoritative until the application rebuilds it. See the [configuration
reference](configuration.md#streaming--replay).

## Vercel example

Pass a caller-owned Vercel UI message ID. It is not a Swarm run ID, a streamed
content-block ID, or a database conversation-row ID. Client-supplied Vercel
message IDs and AG-UI thread IDs must be non-blank valid UTF-8, contain no
control characters, and be at most 512 bytes.

```php
use App\Ai\Swarms\SupportSwarm;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use Illuminate\Support\Facades\Gate;

Route::post('/chat/vercel', function () {
    Gate::authorize('runSwarm', SupportSwarm::class);

    return SupportSwarm::make()
        ->stream(request()->string('message')->toString())
        ->usingVercelDataProtocol(
            messageId: request()->string('message_id')->toString(),
            projection: NativeProtocolProjection::Workflow,
        );
});
```

Workflow progress is emitted as Vercel `data-swarm` data parts. Applications
can render them with the AI SDK's custom data-part support. Standard Vercel
frames remain Laravel AI's contract.

## AG-UI example

Pass the application-authorized AG-UI thread ID. The protocol run ID is always
the real Swarm run ID. Passing a different run ID is rejected rather than
creating a second identity for the same execution.

```php
use App\Ai\Swarms\SupportSwarm;
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use Illuminate\Support\Facades\Gate;

Route::post('/chat/ag-ui/{thread}', function (string $thread) {
    Gate::authorize('updateThread', [$thread, SupportSwarm::class]);

    return SupportSwarm::make()
        ->stream(request()->string('message')->toString())
        ->usingAgentUserInteractionProtocol(
            threadId: $thread,
            projection: NativeProtocolProjection::Workflow,
        );
});
```

Workflow progress is emitted as AG-UI `CUSTOM` events named
`laravel-swarm`. Standard AG-UI events remain Laravel AI's contract.

## Choose a projection

| Projection | Live topologies | Output contract | Important omissions |
| --- | --- | --- | --- |
| `Workflow` (default) | Sequential, enabled process-parallel, generated hierarchical, static hierarchical | Complete whitelisted workflow progress in `data-swarm` / `CUSTOM`; a real workflow completion is then followed by one native protocol terminal | Does not turn interleaved branch output into a linear chat message; reasoning content is withheld |
| `FinalAgent` | Sequential only | Buffers final-step content until the matching step and workflow both complete; emits available text, tools, provider activity, and citations through standard native frames | No live partial answer; buffered content is discarded if a later step begins, the final step is unmatched, or the workflow fails |

`FinalAgent` rejects a non-sequential response before iteration. This prevents a
failed projection choice from abandoning or terminalizing a live run. While the
answer is buffered, the adapter continues consuming the source lazily and emits
only capture-safe `projection_progress` events (`buffering`, `step_started`, and
`step_completed`), not standard answer frames. A client disconnect can therefore
still abandon the consumer and trigger the stream's
ordinary cancellation/cleanup path; buffering does not turn execution into an
uncancellable background run. A workflow uses one aggregate native protocol
step. Swarm step and node boundaries remain custom progress; they are not
presented as one agent's model/tool steps.

## Workflow event and identity contract

The custom progress payload is a strict allowlist. It never forwards a raw
Swarm event array. Depending on the event, it can include:

- `event_id` and `run_id`;
- native Laravel AI `invocation_id` when one exists;
- Swarm `node_id`, `branch_id`, `attempt_id`, `branch_sequence`, and durable
  `attempt_epoch`;
- streamed content-block `message_id` and payload/content availability status;
- function-tool call IDs and provider item IDs;
- lifecycle status, exact aggregate usage at the native terminal, and
  capture-shaped user-facing output.

These namespaces are not interchangeable:

- the Vercel UI message ID belongs to the client message;
- the AG-UI thread ID belongs to the application conversation, while its run ID
  is the Swarm run ID;
- `message_id` on text frames identifies an upstream streamed content block;
- `user_message_id` and `assistant_message_id` are native persisted message-row
  IDs exposed by [native step results](native-step-results.md), and remain absent
  unless the final sequential step actually provides them;
- tool-call, provider-item, event, invocation, node, branch, and attempt IDs keep
  their own meanings.

Missing identity remains missing. Workflow projection can report a missing
content-block ID as custom progress because it does not manufacture a standard
message. `FinalAgent` projection produces a terminal protocol error if an
otherwise available text delta needs a standard frame but lacks the ID; it
never substitutes the Swarm run ID or client message ID.

The raw `swarm_text_delta` / `swarm_text_end` events preserve that upstream
`message_id`. Raw text and function-tool events preserve `payload_status` as
`available`, `redacted`, or `omitted`. A historical row without the field reads
as `PayloadAvailability::Unknown`; the adapter does not inspect `[redacted]`, an
empty array, or `null` and guess that the payload was available. See
[raw event identity and payload availability](streaming.md#raw-event-identity-and-payload-availability).
Workflow custom progress exposes that same lower-case state as `content_status`
for text and `payload_status` for function tools, including `unknown` for legacy
rows.

For process-parallel streams, `(run_id, branch_id, attempt_id,
branch_sequence)` is the ordering identity. `branch_sequence` is meaningful
only inside one branch attempt. Arrival across branches is scheduler-dependent;
the adapter does not invent a global order or merge branch tool namespaces into
one standard chat transcript.

## Tools, text, reasoning, citations, and capture

The adapter consumes already capture-shaped Swarm events:

- Full text and function-tool payloads carry `available`. In `FinalAgent`, only
  this state can use standard native text or tool frames.
- Redacted text and tools carry `redacted` in custom progress. Protocol progress
  withholds the text delta and tool arguments/result; capture-shaped status and
  redacted error metadata can remain custom. `[redacted]` is never emitted as
  genuine standard native message content or a genuine tool argument/result.
- Skip text and tools carry `omitted`. `null` text, empty arguments, or a null
  result are never presented as genuine standard native values.
- Legacy events without `payload_status` are `unknown`. They remain custom
  unavailable progress and never become standard native frames.
- Arbitrary provider-tool payload keys and values remain withheld unless the
  existing provider-tool capture status is `available`.
- Reasoning text and summaries are never projected. Workflow clients receive a
  `reasoning_withheld` progress event without content.
- Citations use standard native frames only when their captured evidence and
  message identity are available; otherwise custom progress reports their
  availability status without fabricating a source.

Protocol projection does not hydrate conversations, history, tools, or tenant
state. Authorize the live run before creating it. Authorize the exact tenant and
run again before calling `SwarmHistory::replay($runId)`; possession of a run ID
is not authorization.

Route access, tenant/thread ownership, client-message ownership, and delivery
audit records remain application responsibilities. Swarm's run history and
audit evidence record workflow execution under the existing capture policy;
the adapter does not add a protocol-access audit category, a delivery receipt,
or proof that a browser rendered a frame. If those facts are required, record
the authorization decision and transport outcome in application-owned audit
evidence, correlated by tenant, Swarm run ID, and the caller-owned client/thread
identifier without copying withheld protocol payloads.

## Completion, errors, cancellation, and replay

Success requires a real `SwarmStreamEnd` and exact non-negative aggregate
`input_tokens` and `output_tokens` for every non-empty run. A structurally empty
run with no agent steps has exact zero usage. Missing, partial, mixed legacy, or
invalid accounting produces a terminal protocol error rather than a false zero.
`FinalAgent` additionally requires the final started step to have a matching
`SwarmStepEnd`; a `SwarmStreamEnd` while that step is still open is a terminal
protocol error with reason `swarm_stream_incomplete_step`, never permission to
reuse an earlier completed step.

A server-observed provider failure, timeout, cancellation, unsupported native
approval request, or incomplete persisted prefix produces the protocol's
terminal error and no success or finish frame. Vercel still writes Laravel AI's
ordinary `[DONE]` transport terminator after an error; that terminator is not a
successful `finish` part. AG-UI writes `RUN_ERROR` and no `RUN_FINISHED`.

When the adapter itself cannot project safely, it first emits inspection-safe
custom `projection_error` progress and dispatches the application event
`NativeProtocolProjectionFailed`. That event contains only `runId`, protocol,
projection, a bounded reason code, and timestamp. It carries no prompt, output,
tool payload, tenant identity, or client acknowledgement. Listen to it for
server-side monitoring or application-owned audit enrichment; it is not an
access audit record or proof of transport delivery.

The public event uses these reason codes:

| Reason | Projection | Trigger |
| --- | --- | --- |
| `swarm_stream_incomplete` | Both | The source ended without `SwarmStreamEnd` or `SwarmStreamError`. |
| `swarm_stream_incomplete_step` | `FinalAgent` | `SwarmStreamEnd` arrived while the final started step was still open. |
| `swarm_usage_unavailable` | Both | A non-empty successful run lacks exact non-negative aggregate input or output tokens. |
| `swarm_usage_invalid` | Both | An optional aggregate usage value is present but is not a non-negative integer. |
| `swarm_message_identity_unavailable` | `FinalAgent` | A projected text block lacks its real streamed message identity. |
| `swarm_stream_failed` | Both | Another exception escaped while the adapter was projecting the source stream. |

Treat these values as stable machine-readable categories. New categories may be
added in a minor release; applications should retain an unknown-code fallback.

Client disconnect is different: a disconnected client cannot receive a
terminal frame. Swarm records abandonment and process-parallel execution reaps
local branches on a best-effort basis, but neither action proves cancellation of
remote provider work already accepted.

Workflow completion and protocol delivery are separate facts. A
`workflow_completed` custom event reflects the underlying `SwarmStreamEnd`; the
Vercel `finish` or AG-UI `RUN_FINISHED` part is generated afterward by the HTTP
encoder. A socket, proxy, or client failure can prevent delivery after the
workflow has already completed. Neither a generated terminal part nor Vercel's
`[DONE]` transport marker proves client receipt. Use authorized persisted replay
for reconnect, and keep any delivery acknowledgement in the application layer.

Persisted reconnect is explicit and application-authorized:

```php
use BuiltByBerry\LaravelSwarm\Enums\NativeProtocolProjection;
use BuiltByBerry\LaravelSwarm\Facades\SwarmHistory;
use Illuminate\Support\Facades\Gate;

Gate::authorize('viewSwarmRun', [$tenant, $runId]);

return SwarmHistory::replay($runId)
    ->usingVercelDataProtocol(
        messageId: $clientMessageId,
        projection: NativeProtocolProjection::Workflow,
    );
```

The same completed response can also be projected after `broadcast()` or
`broadcastNow()` consumes it, using its in-memory event cache. The delivery
surfaces stay distinct:

- `prompt()` accepts native input but returns `SwarmResponse`, not protocol SSE;
- `stream()` returns the HTTP-projectable response;
- `broadcast()` and `broadcastNow()` send typed Swarm events, not protocol
  frames;
- `broadcastOnQueue()` returns `QueuedSwarmResponse` and cannot serve an HTTP
  protocol stream.

Inbound Vercel or AG-UI approval decisions remain outside this output adapter.
They are rejected by native input admission before `stream()` returns a
response. Outbound Laravel AI approval requests retain Swarm's permanent,
non-retryable unsupported-approval boundary and become a terminal protocol
error. No approval-interrupt frame, fabricated decision, success terminal, or
finish frame is emitted.

## Compatibility and operations

The two Swarm protocol classes extend Laravel AI's public, non-final encoders
and override only their protected event-mapping seam. Compatibility tests pin
that seam and executable Vercel/AG-UI client fixtures validate the emitted
state machines. Laravel AI owns protocol evolution, so every Laravel AI update
remains an integration-test event as described in the project upgrade policy.

This adapter adds no migration, persistence table, retention owner, pruning
hook, operational command, cross-system transaction, or recovery process.

### Activation and rollback

Activation is a configuration deployment, not just an environment edit:

1. Review any published `config/swarm.php` with the package upgrade. Swarm's
   recursive merge supplies this missing default to older published files while
   retaining their nested overrides, so no manual addition is required unless
   the application bypasses the package service provider's config merge.
2. Set `SWARM_NATIVE_CHAT_PROTOCOLS_ENABLED=true`, clear/rebuild Laravel's cached
   configuration through the application's normal deploy process, and recycle
   every long-lived application worker that can serve the route (for example
   PHP-FPM, Octane, RoadRunner, or a persistent container replica).
3. Smoke an authorized Vercel and/or AG-UI route through the real proxy/CDN.
   Verify the expected response headers, custom progress, one terminal outcome,
   and end-to-end flushing. Also verify an unauthorized tenant/thread is denied
   before a run is created.
4. Admit production traffic only after every serving process reports the new
   configuration. The flag does not create routes or authorization policy.

Rollback is revert-safe after active projected responses drain:

1. Stop admitting new projected streams at the application route or rollout
   gate. Let active responses finish, or cancel them through the application's
   existing stream controls. Disabling the flag does not mutate a response that
   already captured the enabled setting.
2. Set `SWARM_NATIVE_CHAT_PROTOCOLS_ENABLED=false`, rebuild the config cache,
   and recycle the same long-lived workers. Smoke that new protocol selection
   fails closed while ordinary typed Swarm streaming still works.
3. Revert the adapter code only after no active process can still execute it.

Changing `.env` alone is insufficient in a config-cached application, and
writing `env()` reads into application code is not a supported workaround. See
the [v0.28.0 upgrade steps](../UPGRADING.md#native-chat-protocol-adapters).
