# Citation evidence

Laravel AI citation sources are available on completed `prompt()` / `run()` responses, completed streamed responses, and individual steps:

```php
$response = ResearchSwarm::make()->prompt('Summarize the evidence');

foreach ($response->citations as $citation) {
    // Provider-supplied source for an invocation contributing the final output.
    echo $citation->title.' '.$citation->url;
}

foreach ($response->steps as $step) {
    // Sources from this particular agent execution, including earlier work.
    $evidence = $step->citationEvidence;
}
```

`citationEvidence` is an immutable `CitationEvidence` containing `items`, `status`, and `reasons`. The readonly `citations` array is derived from its items. Existing constructor calls continue to work; append the optional `citationEvidence:` argument to supply evidence in a response or fake fixture.

A native source is evidence supplied by a provider, not verification of a claim. Providers can supply search or fetch results without an inline association. Swarm preserves those sources and does not invent a passage association. It does not fetch or validate source URLs.

## Which sources belong to the final output?

- Sequential execution uses the final agent's own citations. Earlier sources remain on earlier steps; rewriting does not automatically transfer them.
- Parallel execution includes the successful branches whose outputs are concatenated, in declaration order. Each source retains its original step identity.
- Generated and static hierarchy select the node named by the finish node's `output_from`. A literal finish output has available, empty evidence. Repeated loop executions remain separate steps; the selected node's latest completed occurrence supplies its evidence.
- Child and parent runs retain separate provenance. A child's sources are not implicitly attributed to parent text.

A URL citation retains URL, title, and optional native start/end ranges. `range_domain` is `original_agent_output`: offsets are never shifted to imply they index combined, rewritten, or payload-truncated output. Native range units remain provider-owned. Swarm ownership consists of run ID, step index, agent class, and node ID when applicable. Native invocation, message, event ID, and timestamp are retained when supplied; absent identifiers remain null.

## Availability and capture

| Status | Meaning |
| --- | --- |
| `available` | The provider supplied the retained evidence; an empty list means it supplied no citations. |
| `partial` | Some evidence is retained or known incomplete, with fixed reason codes. |
| `redacted` | Output capture withheld the sources. No source count or payload is exposed. |
| `omitted` | Output capture selected Skip; the `citations` wire field is absent. |
| `unavailable` | Stored evidence could not be decoded or decrypted. |
| `unknown` | Legacy data or an implementation did not provide citation evidence. |

Wire arrays use `citations`, `citation_status`, and `citation_reasons`. Never interpret `unknown`, withheld, or unavailable as evidence that there were no sources. Mixed contributing branch states produce `partial`, retaining only permitted items and safe reason codes such as `contributing_unknown`; withheld source counts are not exposed.

Citations follow the existing **output** capture decision, including run-scoped `CapturePolicy` decisions. There is no separate privacy toggle. Raw live response objects retain their native sources, like their raw output. History, replay, and broadcast carry captured evidence. A resumed checkpoint can only return the evidence it retained: changing capture to Full does not reconstruct previously withheld sources or trigger a new provider call to recover them.

`swarm.citations.max_count` defaults to 256 and `swarm.citations.max_bytes` to 262144 per invocation. Limits retain whole source records and report `partial` with reason `limit`; they never silently shorten a URL or title. Limits apply during extraction and stream accumulation and when encoding stored evidence. Existing run/step/event retention limits still apply. Unknown native citation types produce `partial` with `unsupported_type`.

## Streams, replay, and broadcast

`swarm_citation` events preserve native event identity and carry captured evidence. `swarm_step_end` and `swarm_stream_end` include the completed evidence envelopes, including steps that used `prompt()` inside a streamed workflow. The completed stream response includes all executed steps. Terminal native metadata is reconciled one occurrence at a time against source fields; an empty terminal collection never erases sources already observed in citation events. Identical URLs in separate invocations are not deduplicated.

The existing lazy iteration, replay, `each()` / `then()`, and broadcast helpers apply. Persisted replay does not call providers. Durable causal logs retain their existing void and seal semantics: an abandoned attempt's events remain available to raw audit, while the causal view excludes the invalidated attempt. A partial stream is not a successful completed response.

## Persistence and rollout

Run the v0.26.2 migration before using new workers. It adds nullable `citation_evidence` LONGTEXT columns (portable Laravel `longText`, allowing encryption overhead and multiple contributing invocations) to configured history, history-step, durable-branch, durable-node-output, and stream-step-checkpoint tables. There is no backfill: old null evidence reads `unknown`. Drain and restart workers at the version boundary. Built-in stores check required citation columns before invoking an agent. `swarm:health` checks history and stream checkpoint citation columns; add `--durable` to check branch and node storage. An entirely absent optional checkpoint table retains the existing no-resume behavior; an existing table missing the new column requires migration.

Database evidence is capture-filtered and sealed with the existing `swarm.persistence.encrypt_at_rest` setting. Citation fields in database stream/causal-log JSON are separately sealed; this is **not** a claim that all legacy event fields are encrypted. Hot and cold readers open those fields; cold graduation copies sealed raw event data and compaction uses the existing sealed snapshots. Display reads return `unavailable` on a wrong key without exposing ciphertext. Cache storage retains captured structured evidence under the cache's existing protection model.

For `APP_KEY` changes, follow the [rotation inventory and verification procedure](app-key-rotation.md#citation-and-replay-inventory). Re-encrypt the direct citation columns and the nested envelopes in legacy inline steps and hot/cold event JSON; rotating only whole columns misses those nested values.

Branch output and evidence commit in the same lease-guarded update. Hierarchical checkpoints commit output, evidence, and cursor together. Completion selects current-result evidence before terminal node cleanup, or the explicitly selected committed node; final output and evidence commit together in history. Failed or stale branch attempts cannot supply successful final evidence. Existing parent-row retention, cascade, and `swarm:prune` cover the added columns. Existing history/replay JSON inspection exposes the evidence states; no new daemon or operational command is needed.

Cold event and snapshot rows can outlive history and hot replay: they have no automatic `swarm:prune` expiry. Apply an explicit cold archive retention/deletion policy, including retained citation evidence. See the [streaming retention horizon](operator-runbook-streaming-substrate.md#4-the-retention-horizon).

Custom stores keep their existing base interfaces. Optional capabilities provide parity:

- `StoresDurableCitationEvidence`: commit branch/node evidence atomically with output, and retrieve selected node evidence.
- `CitationAwareStreamStepCheckpointStore`: retain captured evidence alongside operational output and usage.
- `RecordsCitationSteps`: receive the run context when recording steps for run-scoped capture.
- `ChecksCitationStorage`: fail preflight when required storage is unavailable.

Stores without a citation capability preserve execution; evidence they cannot retain is `unknown` after recovery. If subclassing a built-in history store, customize both record entry points or its shared `persistStep()` method.

Rolling back package code is not evidence-loss-safe: older writers/readers can omit the new data or skip citation events. Preserve the database and replay data, keep added columns during a code rollback, and assess evidence loss before restarting older workers. Dropping the migration deletes citation evidence. See [upgrading](../UPGRADING.md).

Provider-tool progress events remain separate work for v0.26.3. Native approval continuation remains unsupported and fail-closed.

A byte budget smaller than the fixed partial-status envelope still retains that envelope so truncation is observable; no source payload is retained. Capture redaction retains structural event occurrences and native event identity, while withholding URLs, titles, ranges, and source-item counts.
