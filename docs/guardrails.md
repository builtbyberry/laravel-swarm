# Guardrails

Laravel Swarm runs **guardrails** at three deterministic phases:

1. **Input** — after payload limits / queue-safe validation and **before** `RunHistoryStore::start()` and `SwarmStarted`. Validated **synchronously** for every execution mode: `prompt()`, `queue()`, `stream()`, `broadcastOnQueue()`, and `dispatchDurable()` all throw eagerly if input validation fails.
2. **Step** — immediately **before** each step completion is recorded (`SwarmStepRecorder::completed()`), as soon as agent output is available.
3. **Output** — after the orchestration produces a final string output and **before** history completion and `SwarmCompleted`.

Guardrails are **not** HTTP middleware, routing hooks, or topology mutations. They express policy blocks only by throwing `GuardrailViolation` (use `GuardrailViolation::block()` for clarity).

## Contracts

- `SwarmInputGuardrail::validate(RunContext $context)`
- `SwarmStepGuardrail::validate(GuardrailStepContext $step)`
- `SwarmOutputGuardrail::validate(RunContext $context, string $output)`

Optional swarm hook: implement `DefinesGuardrails` and return class names (container-resolved) or instances from `guardrails()`.

## Configuration

Published `config/swarm.php` includes:

- `guardrails.input`, `guardrails.step`, `guardrails.output` — arrays of guardrail class strings (resolved via the container) or pass instances from `DefinesGuardrails::guardrails()`.
- `guardrails.child_inheritance`
  - `own_and_global` (default) — merge global config entries, then the swarm's `guardrails()`.
  - `own_global_and_parent` — also merge parent swarm `guardrails()` when `metadata.parent_run_id` resolves via persisted history (`swarm_class` on the parent row). When the parent swarm class no longer exists or cannot be resolved from the container, a warning is logged and parent guardrails are not applied. Monitor for these log entries in long-running deployments when swarm classes are renamed or removed.
- `guardrails.parallel_failure_policy` (**sync `ParallelRunner` only**; durable queued parallel branches keep existing behavior):
  - `existing` — validate each parallel branch immediately before that branch's step row is written.
  - `batch_validate_before_record` — validate every parallel output before **any** branch step completion row is written.

Do not confuse `guardrails.parallel_failure_policy` with durable branch join semantics (`durable_parallel_failure_policy` metadata / `DurableParallelFailurePolicy`).

## Failure semantics

On `GuardrailViolation`, Swarm follows the same terminal failure path as other exceptions: persisted failure state, `SwarmFailed`, audit hooks, and telemetry `exception_class` including `GuardrailViolation`. For input guardrail blocks on dispatch paths (`queue`, `broadcastOnQueue`, `dispatchDurable`), the failure is recorded as a preflight failure row — history is written before the exception propagates, exactly as it is for `prompt()` and `stream()`.

The exception exposes a stable **`policyCode`** field for the string policy identifier. Use `$e->policyCode` in application code — **not** `$e->code`. The property is named `policyCode` deliberately: PHP's `Exception::$code` is an inherited integer property, and declaring a `readonly string $code` on a subclass causes a fatal type conflict. `policyCode` is unambiguous and carries no inherited-property baggage.

Persisted context may include `guardrail_code` / `guardrail_scope` / `guardrail_metadata` from `safeContextMetadata()`.

Treat `reason` and guardrail `metadata` as operator-facing: avoid raw prompts or secrets.

## Queued execution — input guardrails run twice

For `queue()` and `broadcastOnQueue()`, input guardrails run at **two points**:

1. **Dispatch time** (synchronous, on the caller's process) — fail-fast before the job is placed on the queue. A preflight failure row is written and `SwarmFailed` is dispatched immediately.
2. **Execution time** (on the queue worker, inside `runWithExecutionMode`) — authoritative check. Validates current conditions when the job fires, regardless of how the job was constructed.

Both checks serve different purposes. Removing the dispatch-time check would prevent synchronous caller feedback. Removing the execution-time check would leave a gap for jobs replayed, retried, or constructed without going through `queue()`. Design guardrails as **pure, stateless policy checks** — throwing `GuardrailViolation` or returning nothing. A guardrail that has side effects (incrementing a rate-limit counter, logging an audit event) will run those effects twice for queued swarms.

Durable runs validate input once, at `dispatchDurable()` call time, before any database rows are written.

## Laravel AI middleware

Use Laravel AI's agent middleware for **single-agent** concerns. Swarm guardrails apply to orchestrated runs across modes (`prompt`, `queue`, `stream`, `dispatchDurable`) at the boundaries above.

## Queue lease schema errors

When the configured history table is missing `execution_token` and `leased_until`, database-backed queued execution raises `MissingQueueLeaseSchemaException` (a configuration error). This is separate from `LostSwarmLeaseException`, which can represent runtime lease loss on some paths.
