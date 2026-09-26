# Native saved-result continuation prototype

This is executable design evidence for a missing Laravel AI contract. It is not
Laravel Swarm runtime support, an installed dependency change, an upstream pull
request, or a released Laravel AI capability.

The fixture pins two source identities:

- released Laravel AI v1.0.0:
  `101c7ea33cd8569d82570f753fbf38e48b7d3d95`;
- the candidate base, Laravel AI `1.x`:
  `a117adfe4e07696b7ffdf76c3c0b2effc0f0139f`.

The exact candidate patch, Composer lock, and their SHA-256 digests live in the
[upstream fixture manifest](../tests/Fixtures/Upstream/LaravelAiSavedResultContinuation/manifest.json).
The reproduction script clones the official repository, checks out the pinned
commit, verifies and applies the patch, installs the committed lock, and runs
the upstream lint, type, focused regression, and full test suites. All provider
traffic is controlled by HTTP fixtures. It never contacts a paid provider.

## Smallest public contract demonstrated

The candidate extends the existing `prompt()` and `stream()` input union with a
readonly `Continuation` value containing conversation ID, interrupted turn ID,
and revision. It adds `ContinuesConversations` as an optional capability of a
`ConversationStore`. Stores that do not implement the capability keep ordinary
prompts and fail an explicit continuation before provider I/O.

The normal flow stays on Laravel AI's existing public verbs:

```php
$response = $agent
    ->continue($continuation->conversationId, $participant)
    ->prompt($continuation, provider: 'anthropic');
```

The first-party database-store candidate implements claims, reconciliation,
completion receipts, and compare-and-swap revisions through that capability.
It reuses the existing assistant message, its `steps`, `status`, and `meta`
fields, so the prototype requires no schema migration. This is deliberately an
optional adapter rather than a second agent runner or a Swarm-owned copy of
provider logic.

Consumer code uses the public `Continuation` and store contract. The candidate
database implementation necessarily reads Laravel AI's own stored replay blocks
to reconstruct provider input; those schema details are implementation evidence,
not a new consumer API.

## Fresh-process proof

The executable N1 case creates a real native approval pause and persists an
approved tool result. A separate PHP process begins the next provider request;
after the parent observes both the durable result and the provider-entry barrier,
it sends that process `SIGKILL`. A third process receives only persisted identity,
reconciles the abandoned owner, and resumes the interrupted assistant turn.

The assertions prove:

- the approved tool effect occurs exactly once;
- the recovery performs one fresh provider call and no tool rerun;
- no second user message is stored;
- completion is folded into the original assistant turn;
- the persisted native completion receipt can be read without rerunning the
  agent or its application callback; and
- the recovery process has a different process ID from the killed process.

SQLite is sufficient to prove process death and state reconstruction in this
case. It does not establish production row-lock behavior. The candidate also
uses two fresh PHP processes to prove that only one competing claimant acquires
the conditional turn claim, but a production integration still needs its
database-specific concurrency lane.

## Contract matrix

| Boundary | Executable disposition |
| --- | --- |
| Provider, model, agent and account | Persisted agent class, provider name/class, model, participant and revision must match before provider I/O. An on-demand `Ai::build()` provider receives a deterministic configuration-derived account identity, so changed inline credentials fail closed. A named configured provider is identified only by its stable name; credential or account rotation behind that name is not detected. |
| Tools and options | The recovered generation reconstructs the currently deployed agent's declared tools and generation options. Continuation rejects runtime tool, message-history, or attachment replacement. Forced tool choice is not renewed after the approval step, and the remaining maximum-step budget is retained. Equivalent reconstruction is proved only while the agent source and configuration are unchanged. |
| Middleware and structured output | The currently deployed step middleware runs for the recovered generation, and the final response and durable receipt retain the structured schema result. The candidate does not fingerprint middleware or schema declarations across deployments. |
| Provider replay | Anthropic signatures, Gemini thought signatures, and OpenAI encrypted reasoning/function blocks retain ordered replay. OpenAI deliberately sends full replay after process loss and omits stale `previous_response_id`. |
| Streaming | A completed continuation records a receipt and replays original event IDs. A partially consumed stream and provider failure remain interrupted. Application callback failure after native completion does not erase the receipt. |
| Events, usage and folding | Only fresh-generation events are emitted on recovery; persisted prior usage is folded once into the original assistant turn, with one user message. |
| Failover | Continuation does not cross provider failover because the saved replay blocks are provider-specific. A mismatch fails before I/O rather than treating a new provider request as the same turn. |
| Ownership and stale state | Wrong participant, changed provider/model/account/agent, stale revision, deleted turn, missing replay, later conversation history, active owner, and incompatible legacy state fail closed before I/O. |
| Approval validation | Partial, repeated, or already-resolved decisions remain rejected. A newly requested approval returns the turn to `awaiting` rather than manufacturing completion. |
| Trait-independent recording | Recorder selection accepts the public `RemembersConversations` contract or Laravel AI's conversation middleware. A custom continuation store and contract-only agent persist approved results without the framework trait. |
| Atomicity and uncertain effects | Result-persistence failure cannot make the approved tool executable again through replayed decisions. Assistant completion and its receipt commit together. Later or unknown effects park the turn for caller-owned reconciliation. |

The candidate intentionally does not promise cross-provider failover. Full
provider replay is what preserves same-turn semantics after process loss, and
those opaque blocks are provider-specific. Failing closed is more accurate than
silently converting recovery into a different provider request.

Reconciliation is also intentionally caller-owned. The public store method
requires the previous owner and evidence, but the fixture cannot prove an
application's external effect reconciliation, authorization policy, audit
retention, or fencing system. Those remain production-bridge responsibilities.

## Deployment and configuration boundary

This candidate proves a persisted continuation only when the agent source and
its named provider configuration remain unchanged between interruption and
recovery. It does not fingerprint declared tools, middleware, generation
options, structured-output schemas, or the credentials/account behind a named
configured provider. A deployment or configuration rotation can therefore
change those inputs without tripping the persisted identity checks.

The affected mode is any native continuation that spans such a source deploy,
provider-account rotation, or configuration change. Before one of those
changes, operators must drain, cancel, or explicitly reconcile active native
continuations. Versioned agent identities and new provider names are viable
alternatives when old and new configurations must coexist. P6 owns an explicit
fingerprint/version contract for this boundary, targeted before P6 may be
claimed or implemented; this proof does not silently supply one. Any published
or equivalent upstream implementation must revalidate this boundary against
its actual identity contract.

## Reproduce the evidence

From the Laravel Swarm repository root:

```bash
bash tests/Fixtures/Upstream/LaravelAiSavedResultContinuation/run.sh
```

The runner executes upstream formatting and type gates, the continuation and
adjacent regression cases, then the full upstream suite. To prove a material
guard is not passing vacuously, disable the exact continuation-ownership check:

```bash
P5B_MUTATION=disable-ownership-check \
  bash tests/Fixtures/Upstream/LaravelAiSavedResultContinuation/run.sh
```

The mutation command succeeds only when the targeted ownership test fails. A
green mutation job therefore means the test detected the injected defect, not
that the defective source passed.

## Decision boundary for Laravel Swarm

This prototype changes no Swarm runtime, configuration, migration, persistence,
retention, pruning, recovery command, or public API. It introduces no production
operational requirement by itself and is revert-safe.

The proof removes the design-unknown blocker: a bounded public contract can
support saved-result same-turn recovery without re-executing the approved tool.
It does not remove the source-availability blocker. P6 must remain unclaimed
until this exact candidate has passed independent review and the maintainer has
explicitly authorized an upstream, fork, patch, or equivalent released-source
strategy. If upstream publishes an equivalent contract, the published source
must be revalidated rather than assumed equivalent to this patch.
