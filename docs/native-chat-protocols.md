# Vercel and AG-UI protocol projection

Laravel Swarm can project a `stream()` response through Laravel AI's native
[Vercel AI SDK](https://laravel.com/framework/docs/13.x/ai-sdk#vercel-ai-sdk-protocol) and
[AG-UI](https://laravel.com/framework/docs/13.x/ai-sdk#agent-user-interaction-protocol) encoders.
Swarm adds only a typed workflow adapter and protocol-specific custom progress
event. Laravel AI continues to own standard message, tool, citation, terminal,
header, and error encoding.

The integration is default off:

```dotenv
SWARM_NATIVE_CHAT_PROTOCOLS_ENABLED=true
```

## Vercel example

Pass a caller-owned Vercel UI message ID. It is not a Swarm run ID, a streamed
content-block ID, or a database conversation-row ID.

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
| `Workflow` (default) | Sequential, enabled process-parallel, generated hierarchical, static hierarchical | Complete whitelisted workflow progress in `data-swarm` / `CUSTOM`, followed by one native protocol terminal | Does not turn interleaved branch output into a linear chat message; reasoning content is withheld |
| `FinalAgent` | Sequential only | Buffers the final completed step and emits its text, available tools, provider activity, and citations through standard native frames after workflow success | No live partial text; no buffered answer is emitted if a later workflow failure occurs |

`FinalAgent` rejects a non-sequential response before iteration. This prevents a
failed projection choice from abandoning or terminalizing a live run. A
workflow uses one aggregate native protocol step. Swarm step and node boundaries
remain custom progress; they are not presented as one agent's model/tool steps.

## Workflow event and identity contract

The custom progress payload is a strict allowlist. It never forwards a raw
Swarm event array. Depending on the event, it can include:

- `event_id` and `run_id`;
- native Laravel AI `invocation_id` when one exists;
- Swarm `node_id`, `branch_id`, `attempt_id`, `branch_sequence`, and durable
  `attempt_epoch`;
- streamed content-block `message_id`;
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

Missing identity remains missing. A legacy replay whose text delta lacks its
native content-block message ID produces a terminal protocol error rather than
substituting the Swarm run ID or client message ID.

For process-parallel streams, `(run_id, branch_id, attempt_id,
branch_sequence)` is the ordering identity. `branch_sequence` is meaningful
only inside one branch attempt. Arrival across branches is scheduler-dependent;
the adapter does not invent a global order or merge branch tool namespaces into
one standard chat transcript.

## Tools, reasoning, citations, and capture

The adapter consumes already capture-shaped Swarm events:

- Full function-tool payloads can use standard native tool frames.
- Redacted payloads carry an explicit `redacted` status and their shaped value
  only in custom workflow progress.
- Skip payloads carry `omitted` and never serialize an empty array or `null` as
  though it were the real tool argument or result.
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

## Completion, errors, cancellation, and replay

Success requires a real `SwarmStreamEnd` and exact non-negative aggregate
`input_tokens` and `output_tokens` for every non-empty run. A structurally empty
run with no agent steps has exact zero usage. Missing, partial, mixed legacy, or
invalid accounting produces a terminal protocol error rather than a false zero.

A server-observed provider failure, timeout, cancellation, unsupported native
approval request, or incomplete persisted prefix produces the protocol's
terminal error and no success or finish frame. Vercel still writes Laravel AI's
ordinary `[DONE]` transport terminator after an error; that terminator is not a
successful `finish` part. AG-UI writes `RUN_ERROR` and no `RUN_FINISHED`.

Client disconnect is different: a disconnected client cannot receive a
terminal frame. Swarm records abandonment and process-parallel execution reaps
local branches on a best-effort basis, but neither action proves cancellation of
remote provider work already accepted.

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
hook, operational command, cross-system transaction, or recovery process. To
roll it back, stop new projected streams, drain active streams, disable
`SWARM_NATIVE_CHAT_PROTOCOLS_ENABLED`, and revert the code.
