# Native approval continuation and recovery proof

This document records a proof, not a Laravel Swarm approval bridge. The proof
targets the released `laravel/ai` v1.0.0 source at
`101c7ea33cd8569d82570f753fbf38e48b7d3d95` and the shipped Laravel Swarm
v0.27.0 baseline at `06ee4c8c095f3999f32aebd849c3534f5283d1a4`.
Later `laravel/ai ^1.0` releases are checked behaviorally rather than being
required to retain that source reference.

Laravel Swarm still rejects a native approval outcome by default. This proof
adds no runtime integration, configuration, migration, command, persistence,
or retention behavior. The future production approval bridge remains blocked
until this executable proof is independently accepted **and** the missing
continuation contract described below has an explicitly accepted disposition.
Neither condition alone authorizes production work.

## What the released public contracts prove

With controlled provider responses, Laravel AI's public contracts can:

- pause with pending approvals and expose those approvals through
  `ResolvesPendingApprovals`;
- verify conversation ownership through `VerifiesConversationOwnership`;
- continue a live paused turn from a complete `Decisions` set, including
  approve, reject, edited arguments, multiple approvals, an ordinary tool
  before the pause, and a second approval pause;
- store the approved, edited, and denied results before the next model call
  when the agent uses Laravel AI's `RemembersConversations` trait;
- reject a partial or repeated/already-resolved decision set before another
  provider request or gated effect; and
- preserve the exact ordered OpenAI Responses input across continuation.

A wrapper around the native database store must preserve each optional
capability explicitly. A wrapper that exposes only `ConversationStore` loses
pending-approval inspection and ownership verification even when its inner
store supports them.

The proof also finds a public-contract asymmetry. A custom agent that implements
the `RemembersConversations` contract without using the trait can remember the
final response, but Laravel AI does not record intermediate approval results
before the continued model call. The recorder path is selected through the
concrete trait, not the contract. That path is therefore not adequate for a
general production bridge without trait-independent recorder activation or a
documented trait requirement. `ConversationStore::storeApprovalResults()` is
already the public storage capability; the gap is that recorder selection checks
the concrete trait rather than the `RemembersConversations` contract.

Assertions against Laravel AI's conversation tables and serialized `steps` JSON
characterize the pinned v1.0.0 first-party database store. They are white-box
evidence for this proof, not a promise that the upstream schema is a public API.

## Saved-result continuation is not presently supported

The hard boundary is a crash after the approved tool result has been saved but
before the model returns. Replaying the same `Decisions` object is rejected as
already resolved. That is correct duplicate-effect protection, but it does not
finish the interrupted model turn.

Continuing the same conversational agent and sending another ordinary prompt
does produce and store model text, but it is not the same native continuation
operation:

- it adds a second user message instead of continuing the paused assistant turn;
- it bypasses native pending-approval validation;
- it emits no `ToolApprovalResolved` event for the recovered operation;
- it stores a new user/assistant turn rather than folding completion into the
  interrupted approval turn;
- it performs provider selection and failover as a new request; and
- it cannot prove identical tool, middleware, options, structured-output,
  streaming, usage, or storage semantics.

The proof consequently does **not** label an ordinary prompt over stored history
as supported continuation. The safe application-owned alternative is to stop automatic
recovery, reconcile the external effect, and let an authorized operator start
a new, explicitly identified user turn when that is appropriate. The new turn
must not be represented as completion of the interrupted turn.

The minimal upstream requirement is a public same-turn continuation operation
that accepts durable conversation identity plus the paused assistant/result
identity (or an opaque continuation token) and continues stored approval
results without a new user message or a repeated tool execution. It must
preserve the original provider, model, account, tools, middleware, options,
structured-output and streaming behavior; events and failover rules; usage and
conversation folding; and stale, deleted, ownership, and approval validation.
It also needs trait-independent activation of the existing public approval-result
recorder before the model call. Without those capabilities, the future production
approval bridge cannot honestly promise fresh-process continuation after the
saved-result boundary.

## Fresh-process crash matrix

Every row below starts with a fresh SQLite database and produces a real native
approval pause in one process. Decision ingress and continuation then run in
separate fresh processes where the boundary requires them. The parent waits for
a durably flushed barrier, sends the boundary process `SIGKILL`, and starts a
fresh recovery process that receives only the database/run identity. The
recovery action is derived from persisted evidence, never from the boundary
label. SQLite proves the process and reconstruction boundaries; it does not
prove production row-lock behavior.
The `SIGKILL` matrix requires process spawning and POSIX signals. Unsupported
local platforms skip it with an explicit reason; Linux CI remains the
authoritative execution lane.

| Crash boundary | Durable observation | Recovery classification |
| --- | --- | --- |
| Before persisted intent | No intent and no effect | Safe to resubmit through authenticated ingress |
| Intent persisted, worker not started | Recorded intent and no effect | Recover the recorded intent |
| Before tool effect | Claimed worker, recorded intent and no observed effect | Indeterminate for a non-idempotent external effect; reconcile, never blind-retry |
| After effect, before result | Effect exists without a result or receipt | Indeterminate; reconcile externally, never blind-retry |
| After saved result, before model | Saved result exists; replay is already resolved | Requires the upstream continuation primitive |
| After native completion, before checkpoint | Completed bound native message exists; application receipt is absent | Validate native identity under the active fence, then create the bound receipt and checkpoint |
| After checkpoint, before acknowledgement | Fenced checkpoint exists | Return the existing checkpoint; do not rerun |

The crash worker is never trusted merely because it exited. The parent observes
the exact barrier in the database, explicitly releases it, asserts abnormal
termination, and only then starts recovery. The before-effect and
effect-before-result rows are intentionally not made safe by a local observation
or deduplication fiction: once a non-idempotent worker is claimed, absence of a
local effect row cannot prove that an external provider did not commit.

## Production design requirements established by the proof

The following are requirements for any later bridge, not behavior shipped by
this component:

1. **Identity and authorization.** Persist the Swarm run, native conversation,
   paused assistant message/result, every pending tool-call ID and argument
   digest, tenant/participant identity, topology location, revision, and fence.
   Re-authenticate the actor and record authentication provenance. Re-authorize
   current participant/conversation ownership **and** the application policy for
   each approve, reject, or edit action at decision ingress and again before
   dispatch. Record the policy/version and denied attempts without copying
   sensitive arguments. A changed or deleted owner is a denial, not a retry.
2. **Complete decisions.** Normalize the whole native pending set before
   accepting it. Missing, extra, stale, duplicate, or conflicting decisions
   must not reach the provider or an approved tool. Repeated pauses create a new
   revision and fence.
3. **Atomic intent.** Lock the wait row and record the normalized decision
   digest, idempotency key, revision, fence, actor, and audit record in one
   transaction. Dispatch after commit through a transactional outbox. If the
   Swarm and native stores use different connections, their writes are not
   atomic; recovery must reconcile explicit receipts rather than imply they are.
4. **Fencing and cancellation.** Expiry, cancellation, supersession, timeout,
   and lease loss advance the fence. Older workers may finish provider work but
   cannot commit a checkpoint, join, or completion. Joins remain waiting only
   for the active revision and must not consume a stale branch result.
5. **Effect idempotency.** Pass a stable application operation key to every
   side-effecting tool and downstream service that supports it. Classify effects
   as safely idempotent, receipt-reconcilable, or indeterminate. The last class
   requires operator intervention after the effect-before-result crash window.
6. **Audit and privacy.** Write an append-only, queryable audit lifecycle with
   tenant, authenticated actor, subject/conversation/run, request correlation,
   authorization result and policy/version, normalized decision/result digests,
   revision/fence/idempotency key, outcome, and timestamps without copying
   approval arguments by default. Audit retention and erasure rules are separate
   from sensitive payload retention and must preserve defensible provenance when
   arguments are deleted. Approval payloads, native conversation rows, results,
   and provider continuation data follow their owning capture, sealing, access,
   retention, pruning, and deletion policies. Swarm capture settings do not
   govern native conversation storage.
7. **Rollout and rollback.** Admission is default-off and capability-gated.
   Enable only workers that understand the persisted version and continuation
   mode. Drain active waits before rollback; old workers must reject unknown
   state rather than discard it. Streaming is a separate transport surface and
   needs equivalent pause, disconnect, resume, and replay proof.

The bounded cases have the following disposition:

| Case | Executable proof or required disposition |
| --- | --- |
| Prior ordinary tool; multiple approve/reject/edit decisions; partial decisions; repeated pause | Exercised through native HTTP parsing and exact ordered wire assertions. |
| Native failure and failover | The approved result is durably recorded before a rate-limit failure; the same conversational agent then demonstrates that an explicitly new prompt has different turn and failover semantics. |
| Optional custom-store capabilities | Native invocation proves the capability-preserving wrapper; a contract-only wrapper and contract-only agent characterize both losses. |
| Conversation ownership changes or deletion | Re-authorized before transport and gated effects; both deny. Content/revision interference must use the same fail-closed fence in a future bridge. |
| Concurrent duplicate/conflicting decisions and stale revision/fence | Exercised through a deterministic holder/contender rendezvous and real row locks on MySQL and PostgreSQL. The complete decision set is canonicalized with pending arguments and accepted fence. |
| Stream disconnect | There is no bridge to resume. A later approval must be a new transport session; the future bridge must prove native streamed pause and disconnect explicitly. |
| Hierarchy join waiting | There is no paused workflow node in this component. The future bridge must prove the join stays waiting for the active fenced approval revision. |
| Old persisted approval state | This proof ships no production schema. Future admission must reject unknown versions and prove mixed-worker readers before enabling writes. |

The stream, join, and old-state rows cannot be converted into executable bridge
claims by a proof-only component. They remain explicit production-bridge acceptance gates, not
silent exclusions. A separate MySQL/PostgreSQL process lane exercises real row
locks for duplicate, conflict, revision, and fence classification; SQLite is
deliberately skipped in that lane.

## Executable evidence

- [NativeApprovalRecoveryProofTest](../tests/Feature/Adoption/NativeApprovalRecoveryProofTest.php)
  proves the released public contracts, exact provider wire, ownership denial,
  result recording asymmetry, and saved-result limitation.
- [NativeApprovalRecoveryCrashTest](../tests/Feature/Adoption/NativeApprovalRecoveryCrashTest.php)
  kills a fresh boundary process and classifies persisted evidence from another
  fresh process at every named crash boundary.
- [NativeApprovalDecisionRaceTest](../tests/ProcessConcurrency/NativeApprovalDecisionRaceTest.php)
  is the real MySQL/PostgreSQL row-lock lane for concurrent decision ingress.

These tests use controlled provider wire fixtures. They do not claim live
provider, hosted service, production, or release publication evidence.
