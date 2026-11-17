# Native Ownership and Limits

This is the v0.28.0 ownership contract for native Laravel AI feature access through
Swarm workflows: what belongs to native `laravel/ai` and what Swarm retains, the
limits Swarm keeps on purpose (with the evidence and the trigger that would reopen
each), and the legacy-retirement schedule. It closes the retained and replaced
decisions from the v0.28.0 native-feature adoption assessment without reopening the
audit.

It is verified against `laravel/ai` **v1.0.1** for the entries predating the
realtime-dispatch release, and against **v1.3.0, v1.4.0 and v1.4.1** for the
tool-failure entry below, except where that entry says otherwise; `composer.json` requires `^1.0`. It sits on top of — and is distinct from — the earlier
`laravel/ai` **preservation baseline** (the C1–C6 review findings and the R01–R30
disposition ledger in
[Laravel AI 1.0 adoption evidence](ai-1-release-evidence.md) and
[historical 0.11.2 adoption evidence](ai-0112-release-evidence.md)). Do not conflate
the two numbering schemes: the R01–R30/C1–C6 ledger is the upgrade preservation
baseline; the R/F identifiers below are the native-feature adoption assessment that
this release delivered against.

## What is native-owned and what Swarm retains

"Native" means `laravel/ai`. Execution of the model, providers, and tools already
runs through the native agent invocation; Swarm does not reimplement it. Swarm owns
the orchestration around that invocation — topology, memory, durability, capture,
identity, and accounting.

| Concern | Owner | Notes and evidence |
| --- | --- | --- |
| Provider invocation and failover | Native | Failover is inside a native invocation; workflow/job retries are separate Swarm operations. See [Retry and operator limits](ai-0112-release-evidence.md#retry-and-operator-limits). |
| Tool execution, the tool-call loop, and filesystem tools | Native | Swarm carries `ToolCall` / `ToolResult` as opaque passthrough; AgentTool nesting is an agent operation, not a Swarm worker. Swarm ships tool implementations (e.g. [filesystem tools](filesystem-tools.md), memory tools) that execute inside the native loop. **One exception since realtime-dispatch:** Swarm retains the *failure outcome* of that loop inside a swarm run — see [Tool-failure recovery is not backported below laravel/ai 1.4](#tool-failure-recovery-is-not-backported-below-laravelai-14). |
| Structured-output generation | Native | A structured-output agent produces one parsed object, not a token stream — see [Limits](#limits-kept-on-purpose). |
| Native conversation storage, authorization, retention | Native / application | Separately configured; **not** covered by Swarm capture or sealing — see [Native conversation storage](#native-conversation-storage-is-configured-separately). |
| Workflow orchestration (topologies, routing, joins) | Swarm | Sequential, parallel, hierarchical, static hierarchical. |
| Memory subsystem (scoped entries, snapshot replay) | Swarm | [Swarm Memory](memory.md). |
| Durable execution, checkpointing, and recovery | Swarm | [Durable Execution](durable-execution.md). |
| Capture, redaction, and encryption-at-rest / sealing | Swarm (Swarm stores only) | [Compliance & Audit](compliance-audit.md). Native rows are out of scope — see below. |
| Usage accounting, including the unknown-usage distinction | Swarm | Unknown or non-scalar usage fields are omitted rather than treated as provider payload, and mixed legacy/native generations stay unavailable in aggregate while raw steps keep meaning. Proven by `tests/Feature/Adoption/UsageAggregationTest.php` and `tests/Unit/Adoption/UsageAccountingTest.php`; see [Native Step Results](native-step-results.md). |
| Run/step identity, history, replay, and citations | Swarm | Preserved across every supported execution path. |

Provider-tool support filtering also remains native-owned. Swarm adds no
preflight check, event, or log entry for an unsupported provider tool. Laravel AI
v1.1.0 and later, which contain `TextGenerationLoop::toolsSupportedBy()`, omit unsupported
provider tools and continue the request, which allows a provider failover target
with a smaller tool surface to run. For unsupported `ToolSearch`, the wrapper is
removed and its deferred tools become ordinary function tools. Laravel AI v1.0.1
and earlier throw before any request or local tool effect. An application can
distinguish the installed behavior with
`method_exists(\Laravel\Ai\Gateway\TextGenerationLoop::class, 'toolsSupportedBy')`;
Swarm does not compare version strings.

## Retained and replaced decisions carried forward

The v0.28.0 native-feature adoption assessment tracked findings (`F1`–`F9`) and
retained/replacement decisions (`R1`–`R9`). These are **internal assessment
identifiers with no verbatim definitions** in the repository; each is closed by the
component below, whose goal states the closure. They are not the C1–C6 / R01–R30
preservation baseline referenced above.

| Component | Findings | Decisions | Closure focus | Evidence |
| --- | --- | --- | --- | --- |
| P1 native-message-inputs | F1 | R7, R9 | Accept native input without losing content or exposing attachments to unintended workers. | [Native Messages and Attachments](native-inputs.md) |
| P2 reconstruct-native-agent-settings | F2 | — | No silent loss of runtime tool/message/conversation settings when an agent is recreated by class. | `RunContext::withAgentConfiguration()` |
| P3 native-step-result-access | F3 | R5, R8, R9 | Preserve native response data while keeping workflow summaries and distinct privacy rules for live access versus persistence. | [Native Step Results](native-step-results.md) |
| P4 native-agent-onboarding | — | R1, R3 | Native `make:agent` and Promptable as the normal authoring path, with an explicit offline demonstration path. | [Native Agent Onboarding](native-agent-onboarding.md) |
| P5 native-approval-recovery-proof | F4 | R7, R9 | Resolve the bounded recovery questions with executable proof. | [Native Approval Recovery Proof](native-approval-recovery-proof.md) |
| P7 parallel-live-stream-multiplexing | F7 | R8 | Bounded top-level parallel live streaming without claiming a global cross-branch order. | [Streaming — Parallel live multiplexing](streaming.md#parallel-live-multiplexing) |
| P8 native-chat-protocol-adapters | F5 | R8 | Faithful native protocol reuse with explicit workflow mapping. | [Vercel and AG-UI protocol projection](native-chat-protocols.md) |
| P9 workflow-callback-conveniences | F6 | — | Native-like convenience without confusing an agent's completion with the whole workflow. | [Error Handling — Terminal Workflow Callbacks](error-handling.md#terminal-workflow-callbacks) |
| P10 native-capability-workflow-examples | F9 | — | Native media and retrieval capabilities demonstrated inside workflows. | [Native Media & Retrieval Capabilities](native-capabilities.md) |
| **P11 native-ownership-and-limits-contract (this document)** | **F8** | **R2, R4, R5, R6, R7, R8, R9** | Close every retained/replacement decision with evidence and an explicit maintenance path. | this document |
| P12 native-feature-ecosystem-proof | F1–F9, R1–R9 final closure | | Combine the new capabilities with the completed adoption baseline and deliver ready-for-wrap evidence. | [Laravel AI 1.0 adoption evidence](ai-1-release-evidence.md), [ecosystem evidence](ai-1-ecosystem-evidence.md) |

P6 (native-approval-workflow-bridge, which also covered F4) is **cancelled for
v0.28.0**. Swarm ships no fork, patch, or disguised continuation for behavior
`laravel/ai` does not officially support; the permanent fail-closed approval
boundary is preserved — see [Native outcome boundary](native-outcome-boundary.md)
and [Limits](#limits-kept-on-purpose).

## Limits kept on purpose

### Structured-output streaming is not supported

A structured-output agent produces a single parsed object, not an incremental token
stream, so it cannot be streamed. This limit is upstream, not a Swarm choice.

- **Exact upstream evidence.** In `laravel/ai` v1.0.1, the stream terminus throws
  before any provider call:
  `vendor/laravel/ai/src/Providers/Concerns/StreamsText.php` —
  `throw new InvalidArgumentException('Streaming structured output is not currently supported.')`
  when the agent implements `Laravel\Ai\Contracts\HasStructuredOutput`.
- **Preserved Swarm behavior (early rejection).** Swarm fails loud in its own domain
  with `StructuredOutputStreamingException` ("… cannot be streamed") *before* the
  vendor call, so the operator gets a swarm-context error, not a bare
  `InvalidArgumentException`. It is rejected at dispatch for the durable
  per-node-streaming opt-in
  (`DispatchValidator::ensureDurableStreamingWorkersStreamable()`), with per-site
  backstops at every live stream surface (`SequentialRunner`, `HierarchicalRunner`,
  `StaticHierarchicalStreamRunner`, `ParallelStreamRunner`,
  `ParallelStreamBranchWorker`, and the durable `DurableBranchAdvancer`). Proven by
  `tests/Feature/Streaming/StructuredOutputStreamGuardTest.php`.
- **Preserved Swarm behavior (synchronous structured output).** Structured output
  runs synchronously via `prompt()`. The hierarchical **coordinator** legitimately
  implements `HasStructuredOutput` — it plans — and is run synchronously, never
  streamed; it is the one agent excluded from the durable-streaming worker check
  (`DispatchValidator` skips `agents()[0]` on the hierarchical topology). Swarm does
  **not** fake streaming by buffering a synchronous result.
- **Affected modes.** Every `stream()` surface (sequential, hierarchical, static
  hierarchical, parallel, and parallel branch workers) and the `#[DurableStreaming]`
  per-node opt-in. **Unaffected:** `prompt()` / `run()`, `queue()`, and durable
  non-streaming execution — a structured-output agent runs there normally.
- **Trigger for reevaluation.** Revisit this limit when `laravel/ai` removes the
  rejection at the stream terminus (the `StreamsText` throw above) — i.e. when a
  `HasStructuredOutput` agent becomes streamable upstream — or when the pinned
  `laravel/ai` release moves past v1.0.1. Until then the rejection and the
  synchronous path stay as-is.

### Tool-failure recovery is not backported below laravel/ai 1.4

`laravel/ai` v1.4.0 made a throwing tool recoverable: the failure is reported and
returned to the model as a failed tool result instead of ending the run. Below
1.4 the capability does not exist upstream.

- **Exact upstream evidence.** `laravel/ai` v1.4.0,
  `Gateway/Concerns/InvokesTools.php` — a throwing handler is rethrown as
  `new ToolExecutionException($exception)` from that method's `catch (Throwable)`,
  which `Gateway/TextGenerationLoop.php` catches in `executedToolResult()`, then
  reports and converts into a failed tool result (inline in v1.4.0, in
  `failedToolResult()` from v1.4.1 — same behaviour). v1.3.0 has neither the
  exception class nor `executedToolResult()`'s catch.
- **Preserved Swarm behavior (propagation).** Swarm's documented contract is the
  pre-1.4 one — a tool call failure surfaces at the agent-execution boundary —
  so `swarm.native_tool_failure_policy` defaults to `propagate` and Swarm
  rethrows the handler's own exception from inside `InvokesTools::executeTool()`'s
  `catch (Throwable)`, where `ToolFailed` is dispatched before the wrap, on every
  supported version. The exception a caller sees is the tool's own, never
  `laravel/ai`'s `@internal` wrapper, which does not exist before 1.4. Proven by
  `tests/Feature/Adoption/NativeToolFailurePropagationTest.php`.
- **The limit.** Setting `swarm.native_tool_failure_policy` to `recover` opts into
  the native behavior, and on `laravel/ai` >= 1.4 it does exactly that. On 1.0–1.3
  there is nothing to defer to, so `recover` is indistinguishable from
  `propagate`. Swarm does **not** synthesise recovery below 1.4: doing so would
  reimplement a native capability behind the package's own API, which is the
  thing this document exists to refuse.
- **Scope, and what the override does not reach.** The policy governs tool
  failures inside a **swarm run**; agents the application prompts directly keep
  the native behavior. A tool throwing a `ValidationException` keeps it even
  inside a swarm, on every version, because `laravel/ai` returns the messages to
  the model as a corrective result. A tool throwing inside a nested `AgentTool`
  did too on 1.0–1.4.0, where upstream degraded a child-run failure to
  `Agent failed: <message>` — but **v1.4.1 removed that catch**, so such a
  failure is now an ordinary tool failure carrying the parent agent, and
  `propagate` fails the parent run. Swarm follows upstream here rather than
  re-creating the degradation: what counts as a tool failure is `laravel/ai`'s to
  decide. Both are documented in
  [Error Handling](error-handling.md#when-a-failing-tool-does-not-fail-the-run).
- **Affected modes.** Every mode drives the native text loop, so the policy
  applies in all of them, subject to the native paths above.
- **What the override assumes upstream.** That `ToolFailed` is dispatched from
  inside `executeTool()`'s catch before the wrap; that it carries the handler's
  own `$exception`; that `executedToolResult()` catches only
  `ToolExecutionException`, so a rethrown handler exception escapes unwrapped;
  and — added after v1.4.1 moved it — whatever `AgentTool` does with a child-run
  failure, since that decides whether a nested failure reaches the policy at all.
  The nightly lane re-resolves `^1.0` and runs the full suite daily, so a change
  to any of the three that reaches a `laravel/ai` release surfaces within a day;
  one still on its `1.x` branch does not, since no lane tracks that branch.
- **Trigger for reevaluation.** Revisit when the supported `laravel/ai` floor
  moves to >= 1.4, at which point `recover` is uniformly available and the
  version-dependent half of this limit disappears. The `propagate` **default**
  is a separate commitment: retire it when upstream marks the pre-1.4 semantics
  unsupported, or at the next Swarm major, whichever comes first.

### Other recorded limits

The retained limits documented here are unchanged by this release: no post-result
native same-turn continuation and no completion-receipt reconciliation (see
[Native Approval Recovery Proof](native-approval-recovery-proof.md) and
[Native outcome boundary](native-outcome-boundary.md)); and the retry and effect-safety
limits in
[Retry and operator limits](ai-0112-release-evidence.md#retry-and-operator-limits).
Whole-workflow queued `then()` / `catch()` are
[opt-in terminal callbacks](error-handling.md#terminal-workflow-callbacks), default off
and enabled with `swarm.callbacks.enabled` under the database persistence driver;
lifecycle events remain the always-on, guaranteed path.

## Native conversation storage is configured separately

Swarm capture flags and designated-column encryption govern **Swarm stores only**.
Native conversation rows can retain plaintext prompts and tool data even when every
Swarm capture flag is off and Swarm sealing is on. Set native storage, access,
retention, and encryption policy separately — see
[Opt-in native tools and history](ai-0112-release-evidence.md#opt-in-native-tools-and-history)
for the full statement and the independent-store evidence, and
[Native Conversation Upgrade](native-conversation-upgrade.md) for the
application-owned migration, authorization, and restore procedure.

## Result and stream mapping: reviewed, no further change

The duplicated result and stream mapping was reviewed only where P3, P7, and P8
required changes; no broad cleanup was undertaken. Those components landed their own
mapping and each preserves capture, identity, replay, and malformed-payload
behavior: native result access ([Native Step Results](native-step-results.md)),
parallel live-stream identity ([Streaming](streaming.md#parallel-live-multiplexing)),
and native protocol projection
([Vercel and AG-UI protocol projection](native-chat-protocols.md), where a tool
value that JSON cannot represent degrades to a typed placeholder rather than
crashing the run). No result/stream mapping change is required by this component.
The open items carried out of P9 and P10 (dead-letter listing, registered-row
cleanup, a shared outbox base, `SwarmFake` callback assertions, and P10's
attribution of tenant/attachment isolation to P1–P3) are deferred or accepted
follow-ups, not result/stream mapping changes.

## Deprecation and legacy retirement schedule

The [public surface index](public-surface.md) is the authoritative, maintained
deprecation table for every deprecated symbol — including the `make:swarm` command and
`Enums\OutboxDispatchType`, both scheduled for a future major release. To keep one
source of truth this section does not restate them; it records only the symbol
scheduled specifically for **v1.0** and its tracked owner. Do not remove any
deprecated symbol early.

| Symbol | Deprecated since | Replacement | Removal target | Tracking |
| --- | --- | --- | --- | --- |
| `Contracts\Agent` | v0.23.0 | `Laravel\Ai\Contracts\Agent` (type-hinted throughout) | **v1.0** | [#547](https://github.com/builtbyberry/laravel-swarm/issues/547), owner Daniel Berry |

`Contracts\Agent` is an empty alias (`interface Agent extends Laravel\Ai\Contracts\Agent {}`)
kept only so classes written against it since v0.5.0 keep working; Swarm type-hints
the vendor contract at every public entry point and runner gate, so new code should
type-hint `Laravel\Ai\Contracts\Agent` directly. Its removal is a breaking change
reserved for v1.0 and belongs in the v1.0 upgrade notes.

The removal follow-up is tracked at GitHub [#547](https://github.com/builtbyberry/laravel-swarm/issues/547),
owned by Daniel Berry, targeting v1.0. A store-owned v1.0 release does not yet exist;
its authorization is a separate release-planning decision, so the store-side link is
recorded as a release note rather than created here (this component does not create a
competing release identity).
