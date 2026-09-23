# Laravel AI 0.11.2 preservation evidence

For native provider activity, capture, storage, replay and attempt semantics, see
[provider-tool events](provider-tool-events.md).

C4 executes the retained-workflow portion of approved Audit 225 v8 (Decision 2357)
after C2 PR #498 and C3 PR #499. The baseline is v0.25.0
`be7df78e8fde12362cfff9007cfe723d572a5e4f`; C4 starts at
`81ea641263e38a877388e792ff7c6866ca6bad5e` on `release/v0.26.0`.
This report records package integration evidence. The HTTP tests execute the
official native invocation code against controlled transport responses; they do
not claim a live provider service was exercised.

For the complete A/R/D disposition index, exact deletion manifest and later C5
landing evidence, see [C6 release evidence](ai-0112-release-evidence.md). The C4
results below remain historical C4 measurements.

## Executed lanes

Local results below are from the C4 candidate. **T** is
`composer test` (Feature, Unit, Installer); **P** is
`composer test:process-concurrency:ci` (real child processes, no skips);
**D** is `composer test:process-concurrency:real-db` in both MySQL 8 and
Postgres 16 PR jobs. SQLite tests are database-backed but do not prove
`FOR UPDATE SKIP LOCKED`. **C** is `composer test:compliance`.
Required lint, analysis and CI coverage retain their existing configuration.

Local PHP 8.5.8, Laravel 13.30.1 (`718d17db56861e0a49f644217c8853dab1bff8ce`),
official laravel/ai 0.11.2 (`ee2c5162838d440c4e2e629ea93c8c87e838eaed`):

| Command | Result |
|---|---|
| `composer test` | 2,202 tests / 10,366 assertions passed |
| `composer test:process-concurrency:ci` | 97 tests / 122 assertions passed; no skips |
| `composer test:compliance` | 51 tests / 171 assertions passed |
| `composer lint` | Passed |
| `composer analyse` | Passed |
| `vendor/bin/pest tests/Feature/Adoption` | 31 tests / 250 assertions passed (included in T) |

The initial candidate `0afce8607d94bf06b25d432531ffbc26055560d2` passed the
[PHP/dependency matrix](https://github.com/builtbyberry/laravel-swarm/actions/runs/35479695550):
all four PHP 8.4/8.5 lowest/current lanes executed 2,202 tests / 10,366 assertions,
91.2% coverage, and 97 process tests / 122 assertions. Lowest resolved Laravel
13.16.0; current resolved 13.32.0; all used official Laravel AI 0.11.2.
[Real-database CI](https://github.com/builtbyberry/laravel-swarm/actions/runs/35479695547)
executed 10 tests / 162 assertions on each of MySQL 8 and Postgres 16. Both stable
lanes also executed 51 compliance tests / 171 assertions. All nine PR checks passed.

These are separate from the local runs; no local MySQL/Postgres execution is
claimed. The corrections below affect report wording only. The final head must
also pass the same required checks; [PR #500's check record](https://github.com/builtbyberry/laravel-swarm/pull/500/checks)
is the landing evidence for D and CI coverage.

Review C4-F1: D-only concurrency anchors are attributed to D, not the P lane that
excludes them. Compliance labels appear only where linked tests belong to that
group. All A/R-row lane labels were checked against their linked tests; the
cross-cutting process/database responsibilities have their own R-row anchors.

## Selected native integrations

The new [native workflow tests](../tests/Feature/Adoption/NativeWorkflowPreservationTest.php)
use Laravel HTTP fakes without replacing native Promptable. Native tool calls,
usage and downstream requests are counted. Existing SwarmFake assertions describe
dispatch intent; real queue/durable tests execute Swarm jobs separately.

| Row | Executable discriminator | Lane |
|---|---|---|
| A01 | New native prompt/stream output and usage assertions; [vendor compatibility](../tests/Feature/VendorAgentCompatibilityTest.php) and [front door](../tests/Feature/SwarmAgentFrontDoorTest.php) preserve concrete agents/public types. | T |
| A02 | New wire assertions for model, max tokens, temperature, top-p and provider metadata; [C3 native HTTP](../tests/Feature/Streaming/NativeHttpParityTest.php) counts pre-output failover versus post-output failure, usage and max-step effects. [Bounds tests](../tests/Feature/Adoption/PreservationBoundsTest.php) separately count workflow retry. | T |
| A03 | New middleware outer/inner ordering, revised HTTP/native event prompt, lazy stream, exception-before-HTTP and input/step/final guardrail failures. [C2 HTTP](../tests/Feature/NativeOutcomeHttpTest.php) preserves rejection after native middleware. | T |
| A04 | New ToolSearch wire type/deferred schema, argument/effect/result pairing and zero-HTTP unsupported/duplicate/store=false rejection. [C3 native HTTP](../tests/Feature/Streaming/NativeHttpParityTest.php) covers AgentTool nesting, validation text, failures and budgets. Memory/tool policies remain under R20. | T |
| A05 | New outgoing JSON schema and real native JSON route: valid plan reaches worker; malformed/shape/unknown/cycle inputs do not invoke a worker. [Structured stream guards](../tests/Feature/Streaming/StructuredOutputStreamGuardTest.php) remain. | T |
| A06 | [SwarmFake](../tests/Unit/SwarmFakeTest.php) covers inert intent/proxies; [queued coordination](../tests/Feature/QueuedHierarchicalParallelCoordinationTest.php) and [C2 job boundaries](../tests/Feature/NativeOutcomeBoundaryTest.php) execute real jobs. | T |
| A07 | [Native result parity](../tests/Feature/Streaming/NativeResultParityTest.php), [native HTTP](../tests/Feature/Streaming/NativeHttpParityTest.php), [historical reader](../tests/Unit/Streaming/NativeResultReaderTest.php) and telemetry/causal lanes retain C3 identities, correction flags, capture and bounded interleaving. | T |
| A08 | New native conversation opt-in proves exact role order, separate conversation IDs, no auto-persistence without opt-in and plaintext native rows under Swarm capture-off/sealing. [Swarm propagation](../tests/Feature/Memory/Propagation/ConversationTraitPropagationTest.php) separately proves policy exclusions. No messages-owning traits are composed. | T, C |

## Retained Swarm responsibilities

Every R row is represented below. Linked suites contain the stated assertions;
the lane results above establish execution. Broad suite success does not extend
the supported mode or privacy boundary.

| Row | Assertions retained | Test anchors | Lane |
|---|---|---|---|
| R01 — public front doors, context and responses | Public verbs and aliases, declared dispatch requirements, input/context/response shapes. | [InlineSwarmBuildersTest](../tests/Feature/InlineSwarmBuildersTest.php); [SwarmAgentFrontDoorTest](../tests/Feature/SwarmAgentFrontDoorTest.php); [SequentialSwarmTest](../tests/Feature/SequentialSwarmTest.php) | T |
| R02 — sequential multi-agent flow | Ordered steps, output-to-input, artifacts, usage, deadlines and failure stop. | [SequentialSwarmTest](../tests/Feature/SequentialSwarmTest.php); [StreamingSwarmTest](../tests/Feature/StreamingSwarmTest.php) | T |
| R03 — top-level parallel workflow | Original branch input, safe resolution, stable join order and real process execution. | [ParallelSwarmTest](../tests/Feature/ParallelSwarmTest.php); [RealProcessConcurrencyTest](../tests/ProcessConcurrency/RealProcessConcurrencyTest.php) | T, P |
| R04 — generated hierarchy and validated route plan | Coordinator route parsing, worker allow-list, DAG grammar and step limits. | [HierarchicalSwarmTest](../tests/Feature/HierarchicalSwarmTest.php); [HierarchicalStreamRunnerTest](../tests/Feature/HierarchicalStreamRunnerTest.php); [HierarchicalRoutePlanTest](../tests/Unit/HierarchicalRoutePlanTest.php); [HierarchicalRoutePlannerLoopTest](../tests/Unit/HierarchicalRoutePlannerLoopTest.php) | T |
| R05 — static hierarchy, bounded loops and rollups | Static plans, bounded nested loops, parallel-in-loop and rollup execution. | [StaticHierarchicalSwarmTest](../tests/Feature/StaticHierarchicalSwarmTest.php); [DurableStaticHierarchicalNestedLoopTest](../tests/Feature/DurableStaticHierarchicalNestedLoopTest.php); [DurableStaticHierarchicalParallelInLoopTest](../tests/Feature/DurableStaticHierarchicalParallelInLoopTest.php); [RollupNodeTest](../tests/Feature/Streaming/RollupNodeTest.php) | T |
| R06 — queued workflow and multi-worker hierarchical joins | InvokeSwarm/BroadcastSwarm and real multi_worker branch/resume jobs; completed join and failure policy. | [QueuedSwarmTest](../tests/Feature/QueuedSwarmTest.php); [QueuedHierarchicalParallelCoordinationTest](../tests/Feature/QueuedHierarchicalParallelCoordinationTest.php); [QueuedSwarmJobPayloadTest](../tests/Unit/QueuedSwarmJobPayloadTest.php) | T |
| R07 — dispatch validation, budgets and timeouts | Queueability, active context and structured-stream rejection; ordinary versus resume-job settings. | [DispatchValidatorTest](../tests/Unit/Runners/DispatchValidatorTest.php); [QueuedSwarmJobConfigTest](../tests/Unit/QueuedSwarmJobConfigTest.php); [DurableAdvanceJobConfigTest](../tests/Unit/DurableAdvanceJobConfigTest.php) | T |
| R08 — durable workflow cursor and checkpoint transaction | Durable cursor checkpoint, duplicate-job suppression and crash-after-commit recovery. | [DurableSwarmTest](../tests/Feature/DurableSwarmTest.php); [DatabaseDurableRunStoreTest](../tests/Unit/Persistence/DatabaseDurableRunStoreTest.php); [DurableOutboxTest](../tests/Feature/DurableOutboxTest.php) | T |
| R09 — retries, backoff and recovery policy | Scheduled backoff, branch retry and recovery; native completion can precede a repeated tool effect. | [DurableSwarmTest](../tests/Feature/DurableSwarmTest.php); [SwarmRecoverCommandTest](../tests/Feature/SwarmRecoverCommandTest.php); [DurableRetryLoggingTest](../tests/Feature/DurableRetryLoggingTest.php); [PreservationBoundsTest](../tests/Feature/Adoption/PreservationBoundsTest.php) | T |
| R10 — leases/fencing, branches and durable outbox | Lease/fence isolation, concurrent branch sinks and transactional outbox dispatch. | [DurableOutboxTest](../tests/Feature/DurableOutboxTest.php); [DurableRunStateConcurrencyTest](../tests/ProcessConcurrency/DurableRunStateConcurrencyTest.php); [DurableBranchSinkConcurrencyTest](../tests/ProcessConcurrency/DurableBranchSinkConcurrencyTest.php) | T, D |
| R11 — named waits, signals and timeouts | Wait/release/timeout and run-key signal idempotency; insertion-before-release failure remains. | [DurableSwarmTest](../tests/Feature/DurableSwarmTest.php); [DurableApprovalWorkflowExampleTest](../tests/Feature/Examples/DurableApprovalWorkflowExampleTest.php); [PreservationBoundsTest](../tests/Feature/Adoption/PreservationBoundsTest.php) | T |
| R12 — authenticated durable webhook ingress | Signed/token/callback authentication, none-mode restriction, hash/idempotency conflicts and FKs. | [DurableSwarmTest](../tests/Feature/DurableSwarmTest.php); [RunIdForeignKeysTest](../tests/Feature/RunIdForeignKeysTest.php) | T |
| R13 — child swarms and parent reconciliation | Child lineage, wait/join, cancellation and claim exclusion; captured recovery and claimed-intent limits. | [DurableSwarmTest](../tests/Feature/DurableSwarmTest.php); [DurableChildDispatchConcurrencyTest](../tests/ProcessConcurrency/DurableChildDispatchConcurrencyTest.php); [PreservationBoundsTest](../tests/Feature/Adoption/PreservationBoundsTest.php) | T, D |
| R14 — operator controls and display read seams | Immediate versus scheduled controls, labels/details/progress; display degradation versus strict recovery. | [SwarmOperatorContractTest](../tests/Feature/SwarmOperatorContractTest.php); [DisplayReadSeamsTest](../tests/Feature/Persistence/DisplayReadSeamsTest.php); [SwarmOperatorControlConcurrencyTest](../tests/ProcessConcurrency/SwarmOperatorControlConcurrencyTest.php) | T, D |
| R15 — typed streaming, lazy response, broadcasts and replay | Typed lazy streams, broadcast jobs, callbacks, partial/abandoned streams and replay failure policy. | [StreamingSwarmTest](../tests/Feature/StreamingSwarmTest.php); [BroadcastSwarmTest](../tests/Feature/BroadcastSwarmTest.php); [StreamEventBreadcrumbTest](../tests/Feature/StreamEventBreadcrumbTest.php); [ZdrReasoningStreamTest](../tests/Feature/ZdrReasoningStreamTest.php) | T |
| R16 — causal log, node grammar, attempt void/seal and views | Node grammar, attempt epochs, void/seal fences and causal versus presentation views. | [StructuralStreamEventGrammarTest](../tests/Feature/StructuralStreamEventGrammarTest.php); [CausalLogViewFoldTest](../tests/Feature/Streaming/CausalLogViewFoldTest.php); [CausalLogSealFenceConcurrencyTest](../tests/ProcessConcurrency/CausalLogSealFenceConcurrencyTest.php); [DurableBranchVoidSealConcurrencyTest](../tests/ProcessConcurrency/DurableBranchVoidSealConcurrencyTest.php) | T, D |
| R17 — compaction, hot/cold retention and context growth | Hot/cold seam, compaction CAS/lease, quarantine and context-growth limits. | [SubstrateCorrectnessSuiteTest](../tests/Feature/Compaction/SubstrateCorrectnessSuiteTest.php); [BackgroundCompactorTest](../tests/Feature/Compaction/BackgroundCompactorTest.php); [TieredStreamEventStoreTest](../tests/Feature/Persistence/TieredStreamEventStoreTest.php); [ContextGrowthPolicyTest](../tests/Feature/Streaming/ContextGrowthPolicyTest.php) | T |
| R18 — scoped memory, propagation and write capture | Scope IDs, propagation exclusions, conversation binding and Full/Redact/Skip writes. | [RestrictivePolicyAcrossRunnersTest](../tests/Feature/Memory/Propagation/RestrictivePolicyAcrossRunnersTest.php); [ConversationScopeDurableTest](../tests/Feature/Memory/ScopeIsolation/ConversationScopeDurableTest.php); [MemoryCapturePolicyTest](../tests/Feature/Memory/MemoryCapturePolicyTest.php); [RunContextMemoryBackingTest](../tests/Feature/Memory/RunContextMemoryBackingTest.php) | T, C |
| R19 — snapshots, FrozenView and completed stream checkpoints | Frozen reads/tool records, non-final checkpoint output/usage reuse and best-effort failure reruns. | [StreamingCrashReplayTest](../tests/Feature/StreamingCrashReplayTest.php); [StaticHierarchicalStreamCrashReplayTest](../tests/Feature/StaticHierarchicalStreamCrashReplayTest.php); [ReplayDeterminismTest](../tests/Feature/Memory/ReplayDeterminismTest.php) | T |
| R20 — Swarm memory tools and filesystem opt-in wrapper | Scoped memory tools, reserved keys, outside-run use, disk opt-in and path confinement. | [HasSwarmMemoryToolsTest](../tests/Feature/Tools/HasSwarmMemoryToolsTest.php); [MemoryToolsTopologyMatrixTest](../tests/Feature/Tools/MemoryToolsTopologyMatrixTest.php); [FilesystemToolsTest](../tests/Feature/Tools/FilesystemToolsTest.php) | T |
| R21 — workflow guardrails and payload limits | Input before invocation, step before recording, output before completion; child/parallel policies. | [GuardrailsSwarmTest](../tests/Feature/GuardrailsSwarmTest.php); [GuardrailsParallelSyncTest](../tests/Feature/GuardrailsParallelSyncTest.php); [GuardrailsDurableInputTest](../tests/Feature/GuardrailsDurableInputTest.php); [SwarmGuardrailRunnerTest](../tests/Unit/Runners/SwarmGuardrailRunnerTest.php); [NativeWorkflowPreservationTest](../tests/Feature/Adoption/NativeWorkflowPreservationTest.php) | T |
| R22 — lifecycle events, telemetry, duration and job failures | Lifecycle/telemetry meaning, correlation, durations, metadata filtering and duplicate suppression. | [TelemetryCorrelationTest](../tests/Feature/TelemetryCorrelationTest.php); [TelemetryStreamBoundaryTest](../tests/Feature/TelemetryStreamBoundaryTest.php); [SwarmEventDurationsTest](../tests/Feature/SwarmEventDurationsTest.php); [SwarmTelemetryDispatcherTest](../tests/Unit/SwarmTelemetryDispatcherTest.php) | T |
| R23 — audit actors, capture, signing, chains and outbox | Audit chain/signing/provenance, halt policy, outbox dispatch and concurrency. | [AuditChainEndToEndTest](../tests/Feature/AuditChainEndToEndTest.php); [AuditHaltTest](../tests/Feature/AuditHaltTest.php); [AuditOutboxIntegrationTest](../tests/Feature/AuditOutboxIntegrationTest.php); [AuditOutboxConcurrencyTest](../tests/ProcessConcurrency/AuditOutboxConcurrencyTest.php) | T, D |
| R24 — persistence privacy and operational/evidence distinction | Capture omission/defaults, designated column sealing, legacy/plaintext and strict/display decryption. | [CaptureSkipOmissionTest](../tests/Feature/Audit/CaptureSkipOmissionTest.php); [SwarmPersistenceCipherTest](../tests/Unit/Persistence/SwarmPersistenceCipherTest.php); [DisplayReadSeamsTest](../tests/Feature/Persistence/DisplayReadSeamsTest.php); [PreservationBoundsTest](../tests/Feature/Adoption/PreservationBoundsTest.php) | T |
| R25 — run context/artifacts/history and custom-store seams | Cache/database contracts, query/count/history behavior and encrypted-context matching; concrete durable bound. | [DatabasePersistenceTest](../tests/Feature/DatabasePersistenceTest.php); [SwarmHistoryTest](../tests/Feature/SwarmHistoryTest.php); [SwarmHistoryLeanCountTest](../tests/Feature/SwarmHistoryLeanCountTest.php); [PreservationBoundsTest](../tests/Feature/Adoption/PreservationBoundsTest.php) | T |
| R26 — retention, foreign keys and maintenance safety | Active-run retention protection, pruning, FK cascade/set-null and archive separation. | [DatabasePersistenceTest](../tests/Feature/DatabasePersistenceTest.php); [RunIdForeignKeysTest](../tests/Feature/RunIdForeignKeysTest.php); [AuditOutboxRetentionTest](../tests/Feature/AuditOutboxRetentionTest.php); [MemoryRunIdCascadeTest](../tests/Feature/Memory/MemoryRunIdCascadeTest.php); [SubstrateCorrectnessSuiteTest](../tests/Feature/Compaction/SubstrateCorrectnessSuiteTest.php) | T |
| R27 — operational CLI, installers and generated code | Operator/install CLI behavior, exit codes and command overlap in real processes. | [SwarmOperatorContractTest](../tests/Feature/SwarmOperatorContractTest.php); [CommandOverlapTest](../tests/Feature/CommandOverlapTest.php); [CommandOverlapConcurrencyTest](../tests/ProcessConcurrency/CommandOverlapConcurrencyTest.php); [InstallCommandTest](../tests/Installer/InstallCommandTest.php) | T, P |
| R28 — configuration, attributes, extension registration and companions | Config defaults, installer/config extensions and reference resolution; companion smoke belongs to C5. | [SwarmConfigTest](../tests/Unit/Config/SwarmConfigTest.php); [InstallDurableCommandTest](../tests/Installer/InstallDurableCommandTest.php); [InstallAuditCommandTest](../tests/Installer/InstallAuditCommandTest.php); [DocumentationReferenceTest](../tests/Unit/DocumentationReferenceTest.php) | T |
| R29 — workflow fakes, assertions and compatibility helpers | Intent-only dispatch/stream fakes, fluent proxy/destruction and audit/test-helper assertions. | [SwarmFakeTest](../tests/Unit/SwarmFakeTest.php); [InteractsWithSwarmEventsTest](../tests/Feature/Testing/InteractsWithSwarmEventsTest.php); [SwarmFakeAuditInterceptsTest](../tests/Unit/Testing/SwarmFakeAuditInterceptsTest.php) | T |
| R30 — per-run context isolation and cleanup | Run/conversation isolation, Octane reset and bounded generator/Fiber interleaving from C3. | [StreamingCrashReplayTest](../tests/Feature/StreamingCrashReplayTest.php); [OctaneRunContextResetTest](../tests/Feature/OctaneRunContextResetTest.php); [ConcurrentRunIsolationTest](../tests/Feature/Memory/ScopeIsolation/ConcurrentRunIsolationTest.php); [NativeResultParityTest](../tests/Feature/Streaming/NativeResultParityTest.php) | T |

## Supported combinations and operational limits

- `prompt()` / `run()` and declared-class `queue()` / `dispatchDurable()` retain sequential, parallel, generated hierarchical and static hierarchical paths. Inline builders remain in-process. Generated hierarchical queue defaults to one job; opt-in `multi_worker` has real branch/join tests. Static hierarchy does not acquire that multi-worker promise.
- Live `stream()` / broadcast helpers support sequential, generated hierarchical and static hierarchical topology. The generated coordinator uses prompt; top-level parallel live streaming remains rejected. Durable per-node streaming has its own node/branch causal log and does not imply one ordered live parallel stream.
- Queued/durable execution requires `swarm.capture.active_context=true`. C4 does not provide an all-capture-off durable path. Signal payload capture affects operational metadata; capture off redacts values. Captured signal JSON is not one of the designated sealed columns, even when context input sealing is enabled. The new raw-row tests pin both cases.
- Signal insertion happens before the wait-acceptance transaction. A crash there leaves a recorded signal; replaying its run-scoped idempotency key is a duplicate and does not release the wait. Keys bind run plus key, not name/payload. The new crash test proves this boundary. It is not a native approval continuation or a stronger atomic receipt.
- Child first dispatch may use live input; recovery depends on persisted captured input. A child intent claimed before creation can remain stranded: age alone does not release its dispatch marker. The new recovery test preserves that limitation; child recovery work remains separate.
- FrozenView stabilizes selected memory reads and tool records. Completed non-final streaming checkpoints can skip work, but writes are best-effort; missing/unreadable checkpoints and unfinished/final steps permit re-execution. It does not deduplicate arbitrary effects.
- Cancellation/deadlines are cooperative and do not hard-cancel provider calls. Durable fences protect state writes, not external effects. The new native retry test observes native completion and one effect before an uncommitted Swarm step, then two total effects/four HTTP requests after retry. This characterizes an existing risk; it does not make effect replay safe or exactly once. C2 native approval rejection remains nonretryable.
- Context/artifact/history contracts remain supported, but durable dispatch requires the concrete database stores (compatible subclasses can qualify); a contract-only context wrapper is rejected. Compaction also remains database-coupled. Native conversation storage has its own privacy/retention controls: Swarm capture and encryption do not seal those rows. Distinct conversation-ID isolation is not per-user authorization. Raw exception logging can remain visible under the selected log policy.
- C3 correction flags remain readable from historical rows with safe defaults. Old readers lose the denied/failed correction even though JSON parses. Keep a compatible reader or return to reviewed design before downgrade; worker drain alone is insufficient. No broader ambient Fiber/Octane isolation is claimed beyond executed caller-owned mappings/reset tests.

## Negative controls and delivery boundary

28 restored negative controls: 27 focused test failures and one runtime failure
(recursive exhaustion after acyclicity validation was removed). No mutation remains
in production or vendor source. The restored C4 lane passes 31 tests / 250 assertions.
Review C4-F2: test exceptions count as detected mutations; they are not mislabeled
as assertion mismatches.

| Control | Protected source | Focused test filter | Outcome |
|---|---|---|---|
| max-tokens-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `successful usage` | Test failure |
| temperature-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `successful usage` | Test failure |
| top-p-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `successful usage` | Test failure |
| provider-options-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `successful usage` | Test failure |
| structured-schema-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `structured JSON` | Test failure |
| tool-result-pair-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/BuildsTextRequests.php` | `paired tool` | Test failure |
| discovery-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/MapsTools.php` | `paired tool` | Test failure |
| deferred-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/MapsTools.php` | `paired tool` | Test failure |
| stateless-restriction | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/MapsTools.php` | `discovery restrictions` | Test failure |
| unsupported-discovery | `vendor/laravel/ai/src/Gateway/TextGenerationLoop.php` | `discovery restrictions` | Test failure |
| duplicate-discovery | `vendor/laravel/ai/src/Gateway/TextGenerationLoop.php` | `discovery restrictions` | Test failure |
| middleware-order | `vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php` | `successful usage` | Test failure |
| middleware-bypass | `vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php` | `failure ordering` | Test failure |
| guardrail-input | `src/Runners/SwarmGuardrailRunner.php` | `failure ordering` | Test failure |
| guardrail-step | `src/Runners/SwarmGuardrailRunner.php` | `failure ordering` | Test failure |
| guardrail-output | `src/Runners/SwarmGuardrailRunner.php` | `failure ordering` | Test failure |
| conversation-opt-in | `vendor/laravel/ai/src/Middleware/RememberConversation.php` | `conversation opt in` | Test failure |
| conversation-role-wire | `vendor/laravel/ai/src/Gateway/OpenAi/Concerns/MapsMessages.php` | `conversation opt in` | Test failure |
| structured-input-wire | `tests/Feature/Adoption/Fixtures/NativeWire.php` | `structured JSON` | Test failure |
| concrete-store-bound | `src/Runners/DispatchValidator.php` | `concrete durable` | Test failure |
| signal-duplicate-bound | `src/Runners/Durable/DurableSignalHandler.php` | `record before release` | Test failure |
| signal-capture | `src/Runners/Durable/DurablePayloadCapture.php` | `signal plaintext` | Test failure |
| signal-wire | `src/Persistence/DatabaseDurableRunStore.php` | `signal plaintext` | Test failure |
| child-claim-bound | `src/Persistence/DatabaseDurableRunStore.php` | `age reclaim` | Test failure |
| workflow-retry | `src/Runners/Durable/DurableRetryHandler.php` | `repeat a native tool` | Test failure |
| route-worker-allowlist | `src/Routing/HierarchicalRoutePlanner.php` | `structured JSON` | Test failure |
| route-cycle | `src/Routing/HierarchicalRoutePlanner.php` | `structured JSON` | Runtime recursion failure |
| signal-record-loss | `src/Runners/Durable/DurableSignalHandler.php` | `record before release` | Test failure |


C4 changes tests and this preservation evidence only. It introduces no production
behavior, migration, configuration knob, job format, operational command or
persistent state. The chosen approach keeps native ownership and characterizes
known limits; new Swarm discovery/conversation resolvers and opportunistic recovery
fixes were rejected. Upgrade fixtures, old jobs/readers, moving-dev CI and downstream
smoke remain C5; full public-doc reconciliation remains C6. This is not release
readiness, a main merge, tagging or shipment approval.
