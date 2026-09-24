# Native approval outcomes

> **Planned v0.28 status:** the existing shipped default rejection remains the runtime
> behavior. The [native approval recovery proof](native-approval-recovery-proof.md)
> demonstrates the released Laravel AI contracts and crash boundaries without
> installing a production bridge. Saved-result fresh-process continuation still
> needs an upstream same-turn continuation primitive, so automatic adoption
> remains default-off.

Laravel Swarm v0.26.0 rejects pending native tool approvals. A native agent may
return an approval-pending response, but Swarm does not supply the native
approval decision/continuation integration. Such a response fails the affected
step, node or branch before its success guardrails, output conversion or
completion recording. Existing Swarm workflow waits and signals remain supported.

The internal `UnsupportedNativeApprovalException` reports this unsupported
integration. Laravel AI can instead throw its own `ApprovalNotResumableException`
when an agent cannot return a resumable approval outcome; that native exception
keeps its type. Both bypass application durable retry policies and fail the
actual queue job without automatic release, including when configured tries
exceeds one. Failure listeners, snapshot cleanup, and parent/join dispatch cannot
replace a detected native rejection with a retryable exception. Cleanup still
attempts the existing fenced writes; infrastructure outages can prevent those
writes or delay parent coordination and require the existing recovery procedures.

For Laravel's built-in process and fork drivers, a concurrent batch inspects all
returned worker outcomes before selecting its failure. An unsupported native
outcome takes precedence over an ordinary sibling failure, regardless of branch
order. With no native rejection, the first ordinary failure retains precedence.
Sync execution still stops at its first failure. Custom concurrency drivers keep
their existing behavior; Swarm cannot inspect a sibling outcome a custom driver
discards. Ordinary failures retain their existing retry policy. If an ordinary
exception's constructor data cannot be transported, the selected failure is an
explicit `SwarmException`; worker-side reporting preserves the original error.
An untransportable ordinary failure cannot hide a sibling native rejection.

## Effects, capture and operator recovery

Detection happens **after possible effects**. Native middleware, provider calls,
non-approval tools and native conversation storage may already have run. Live
streams may already have delivered output. Earlier ordinary tool events and their
arguments remain subject to the existing Swarm capture policy; rejection does
not erase them. The pending-approval event's IDs, reasons, arguments and raw
provider replay blocks are not copied into Swarm diagnostics or storage. The
new Swarm exception contains only a fixed explanation, with no approval payload
or chained provider exception. Native conversation storage has its own privacy
and retention policy, separate from Swarm capture.

Do not automatically restart a failed run. Inspect native/provider records and
external effects, reconcile any completed actions, and decide whether a new run
is safe. A restart can repeat those effects. Swarm does not invent an approval,
reject a tool on the user's behalf, create a synthetic wait, or resume a native
interrupted turn.

If failure telemetry itself throws while handling either native rejection,
the original rejection still reaches the queue job's permanent-failure guard.
Telemetry may be incomplete; its failure must not make the rejected work eligible
for automatic retry. Ordinary failures retain their existing exception and retry
behavior.

Durable branch failure still respects lease fencing and the configured
`swarm.durable.parallel.failure_policy`. Under `partial_success`, a parent with
successful siblings may complete using those siblings; the rejected branch stays
failed and contributes no successful step or output. With `fail_run` or
`collect_failures`, the existing parent failure behavior remains. A failed branch
job fails after attempting the existing parent/join dispatch path, even if that
follow-up operation also fails.

## Invocation inventory

Native execution stays in the existing callers. The validator inspects native
outcomes and selects failures from the existing concurrency driver results; it does not replace native agent invocation, queue
jobs, conversation storage, or topology execution. Provider/tool calls remain outside Swarm database transactions.

| Native caller | Invocation and check | Execution paths |
| --- | --- | --- |
| [SequentialRunner](../src/Runners/SequentialRunner.php) `runSingleStep` | `prompt`, response before conversion | Sync, single-job queue, durable sequential |
| `SequentialRunner::stream` | Non-final `prompt`; final `stream`, approval event then final response | Live sequential, non-final fallback and checkpoint resume |
| `SequentialRunner::streamSingleStep` | `stream`, approval event then final response | Durable sequential streaming |
| [ParallelRunner](../src/Runners/ParallelRunner.php) worker closure | `prompt`, worker-local response check | In-process and real process parallel |
| [HierarchicalRunner](../src/Runners/HierarchicalRunner.php) `executeAgent` | `prompt`, response before conversion | Coordinator, worker, queued/resumed worker and static plan execution |
| Hierarchical parallel worker closure | `prompt`, worker-local response check | Concurrent hierarchical/static workers |
| `HierarchicalRunner::executeDurableWorkerAgent` | `stream`, approval event then final response | Durable hierarchical/static worker streaming |
| [HierarchicalStreamRunner](../src/Runners/HierarchicalStreamRunner.php) coordinator | `prompt`, response before plan conversion | Live planned hierarchy |
| [StaticHierarchicalStreamRunner](../src/Runners/StaticHierarchicalStreamRunner.php) worker closure | `prompt`, worker-local response check | Concurrent branches in live hierarchy |
| Static hierarchical worker stream | `stream`, direct approval-event check then final response | Live static/planned hierarchical workers |
| [DurableBranchAdvancer](../src/Runners/Durable/DurableBranchAdvancer.php) `promptBranchAgent` | `prompt`, response before snapshot/output conversion | Durable and queued multi-worker branches; streaming kill-switch fallback |
| `DurableBranchAdvancer::streamBranchAgent` | `stream`, approval event then final response | Durable branch streaming |

The five stream sites inspect the final response through native `then()` after
iteration, when native completion callbacks have run. The event check runs
before mapping or capture: four paths use
[StreamEventMapper](../src/Streaming/StreamEventMapper.php), while the live
hierarchical fold checks directly. Rejection and abandonment keep the existing
`finally` cleanup. This does not change native middleware, options, failover,
tools, structured-output restrictions, usage folding, or Swarm guardrail order.

## Verification scope

[NativeOutcomeBoundaryTest](../tests/Feature/NativeOutcomeBoundaryTest.php)
exercises prompt/stream/coordinator/fallback paths, final callback outcomes,
durable steps and branches, partial-parent policies, queued resume and actual
Laravel worker retry handling. [NativeOutcomeHttpTest](../tests/Feature/NativeOutcomeHttpTest.php)
uses the official native HTTP runtime to verify middleware, model/options,
usage, possible earlier effects and pending rejection.
[NativeOutcomeConcurrencyTest](../tests/ProcessConcurrency/NativeOutcomeConcurrencyTest.php)
checks exception propagation through real process workers.

These are C2 boundary proofs, not complete adoption or release-readiness evidence.
The broader retained-workflow and upgrade matrix is separate work. This change
adds no database schema, serialized job fields, store signatures, configuration
keys or retained data; existing maintenance commands and retention owners apply.
