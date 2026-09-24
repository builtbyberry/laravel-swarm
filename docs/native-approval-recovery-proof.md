# Native approval continuation and recovery proof

This document records a proof, not a Laravel Swarm approval bridge. The proof
targets the released `laravel/ai` v1.0.0 source at
`101c7ea33cd8569d82570f753fbf38e48b7d3d95` and the shipped Laravel Swarm
v0.27.0 baseline at `06ee4c8c095f3999f32aebd849c3534f5283d1a4`.
Later `laravel/ai ^1.0` releases are checked behaviorally rather than being
required to retain that source reference.

Laravel Swarm still rejects a native approval outcome by default. This proof
adds no runtime integration, configuration, migration, command, persistence,
or retention behavior. P6, the production bridge, remains blocked pending an
explicit decision about the missing continuation contract described below and
independent acceptance of that decision.

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
- reject a partial, stale, or repeated decision set before another provider
  request or gated effect; and
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
general production bridge without an upstream extension point or a documented
trait requirement.

## Saved-result continuation is not presently supported

The hard boundary is a crash after the approved tool result has been saved but
before the model returns. Replaying the same `Decisions` object is rejected as
already resolved. That is correct duplicate-effect protection, but it does not
finish the interrupted model turn.

Reconstructing the public message history and sending another prompt does
produce model text, but it is not the same native continuation operation:

- it adds a second user message instead of continuing the paused assistant turn;
- it bypasses native pending-approval validation;
- it emits no `ToolApprovalResolved` event for the recovered operation;
- it does not remember or fold the response into the original conversation;
- it performs provider selection and failover as a new request; and
- it cannot prove identical tool, middleware, options, structured-output,
  streaming, usage, or storage semantics.

The proof consequently does **not** label reconstructed history as supported
continuation. The safe application-owned alternative is to stop automatic
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
It also needs a contract-level extension point for recording approval results
before the model call. Without that primitive, P6 cannot honestly promise
fresh-process continuation after the saved-result boundary.

## Fresh-process crash matrix

Every row below starts with a fresh SQLite database, produces a real native
approval pause in one process, resumes in a second process, waits until a
durably flushed barrier is visible to the parent, sends that process `SIGKILL`,
and classifies recovery in a third process. SQLite proves the process and
reconstruction boundaries; it does not prove production row-lock behavior.

| Crash boundary | Durable observation | Recovery classification |
| --- | --- | --- |
| Before persisted intent | No intent and no effect | Safe to resubmit through authenticated ingress |
| Intent persisted, worker not started | Recorded intent and no effect | Recover the recorded intent |
| Before tool effect | Recorded intent and no effect | Retry the recorded intent |
| After effect, before result | Effect exists without a result or receipt | Indeterminate; reconcile externally, never blind-retry |
| After saved result, before model | Saved result exists; replay is already resolved | Requires the upstream continuation primitive |
| After native completion, before checkpoint | Validated native receipt exists | Reconcile the receipt and write the fenced checkpoint |
| After checkpoint, before acknowledgement | Fenced checkpoint exists | Return the existing checkpoint; do not rerun |

The crash worker is never trusted merely because it exited. The parent observes
the exact barrier in the database, explicitly releases it, asserts abnormal
termination, and only then starts recovery. The effect-before-result row is
intentionally not made safe by a local deduplication fiction: an external
provider may have committed while the application has no receipt.

## Production design requirements established by the proof

The following are requirements for any later bridge, not behavior shipped by
this component:

1. **Identity and authorization.** Persist the Swarm run, native conversation,
   paused assistant message/result, every pending tool-call ID and argument
   digest, tenant/participant identity, topology location, revision, and fence.
   Re-authorize the current participant and current conversation ownership at
   decision ingress and again before dispatch. A changed or deleted owner is a
   denial, not a retry.
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
6. **Audit and privacy.** Audit who decided, when, the normalized decision
   digest, revision/fence, and outcome without copying approval arguments by
   default. Approval payloads, native conversation rows, results, and provider
   continuation data follow their owning capture, sealing, access, retention,
   pruning, and deletion policies. Swarm capture settings do not govern native
   conversation storage.
7. **Rollout and rollback.** Admission is default-off and capability-gated.
   Enable only workers that understand the persisted version and continuation
   mode. Drain active waits before rollback; old workers must reject unknown
   state rather than discard it. Streaming is a separate transport surface and
   needs equivalent pause, disconnect, resume, and replay proof.

The bounded cases have the following disposition:

| Case | P5 evidence or required disposition |
| --- | --- |
| Prior ordinary tool; multiple approve/reject/edit decisions; partial decisions; repeated pause | Exercised through native HTTP parsing and exact ordered wire assertions. |
| Native failure and failover | The approved result is durably recorded before a rate-limit failure; reconstruction then demonstrates that a new prompt has different failover semantics. |
| Optional custom-store capabilities | Native invocation proves the capability-preserving wrapper; a contract-only wrapper and contract-only agent characterize both losses. |
| Conversation ownership changes or deletion | Re-authorized before transport and gated effects; both deny. Content/revision interference must use the same fail-closed fence in P6. |
| Concurrent duplicate/conflicting decisions and stale revision/fence | Exercised through real process workers and row locks on MySQL and PostgreSQL. |
| Stream disconnect | There is no bridge to resume. A later approval must be a new transport session; P6 must prove native streamed pause and disconnect explicitly. |
| Hierarchy join waiting | There is no paused workflow node in this component. P6 must prove the join stays waiting for the active fenced approval revision. |
| Old persisted approval state | No P5 schema is shipped. P6 admission must reject unknown versions and prove mixed-worker readers before enabling writes. |

The stream, join, and old-state rows cannot be converted into executable bridge
claims by a proof-only component. They remain explicit P6 acceptance gates, not
silent exclusions. A separate MySQL/PostgreSQL process lane exercises real row
locks for duplicate, conflict, revision, and fence classification; SQLite is
deliberately skipped in that lane.

## Executable evidence

- [NativeApprovalRecoveryProofTest](../tests/Feature/Adoption/NativeApprovalRecoveryProofTest.php)
  proves the released public contracts, exact provider wire, ownership denial,
  result recording asymmetry, and saved-result limitation.
- [NativeApprovalRecoveryCrashTest](../tests/Feature/Adoption/NativeApprovalRecoveryCrashTest.php)
  kills and recovers fresh processes at every named crash boundary.
- [NativeApprovalDecisionRaceTest](../tests/ProcessConcurrency/NativeApprovalDecisionRaceTest.php)
  is the real MySQL/PostgreSQL row-lock lane for concurrent decision ingress.

These tests use controlled provider wire fixtures. They do not claim live
provider, hosted service, production, or release publication evidence.
