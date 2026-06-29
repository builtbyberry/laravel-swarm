# Streaming Substrate: Author Guide

This guide is for the **workflow author** — the engineer who composes swarms and
wants to stream a dynamic (coordinator-generated) workflow, keep a long run's
context bounded, and read the run's structure back from the log. For the
operator side — tiering, the compaction worker, recovery, and quarantine — see
the [Streaming Substrate Operator Runbook](operator-runbook-streaming-substrate.md).

Everything here builds on [`stream()`](streaming.md). Read that first if you have
not — this guide covers what v0.15.0 adds on top of it.

> **One sentence.** v0.15.0 promotes the streamed event log into an
> **append-only causal log**: nothing is ever mutated or deleted in place, every
> course-correction is a typed *void-edge*, and every shaping a reader wants is a
> read-time **fold** over that one immutable log.

## What Changed for Authors

- **You can stream a `Hierarchical` swarm.** The coordinator runs synchronously
  (it returns structured output, which providers do not stream); its workers
  stream normally.
- **A run's structure is on the log.** Three structural events
  (`swarm_node_opened` / `swarm_node_children_decided` / `swarm_node_closed`)
  bracket each node, and every event carries a `node_id`. You can reconstruct the
  shape of a run from the log alone.
- **You read the log through a view, not by hand.** `CausalLogView` folds the log
  along an *order* axis and a *supersession* axis — the same log gives you a clean
  presentation view or a full forensic view without ever changing the store.
- **You can bound your own context.** A `rollup` plan node digests a generation
  so downstream agents read one summary instead of the raw transcript — and makes
  that window reclaimable to cold storage mid-run.
- **You declare context-growth intent.** A `#[ContextGrowthPolicy]` says how the
  framework should react when a run's hot working set grows past the operator's
  budget.

---

## Streaming a Dynamic (Hierarchical) Swarm

`stream()` now accepts `Topology::Hierarchical` alongside `Sequential` and
`StaticHierarchical`. Nothing about how you *write* a hierarchical swarm changes —
you stream it exactly as you would call `prompt()`:

```php
use App\Ai\Swarms\SupportTriageSwarm;

return SupportTriageSwarm::make()->stream([
    'ticket' => $ticket->body,
]);
```

The one thing to understand is the **coordinator boundary**. Because
`laravel/ai` does not stream a `HasStructuredOutput` agent, the coordinator step
runs synchronously via `prompt()`. It still participates in memory, guardrails,
and the causal log — it just does not emit token deltas. What you see on the
stream for the coordinator is purely structural:

| Event | Detail |
| --- | --- |
| `swarm_node_opened` | role `coordinator`, id `__coordinator__`, at step index 0 |
| `swarm_step_start` / `swarm_step_end` | step index 0 |
| `swarm_node_children_decided` | the plan's `start_at` node as the sole child |
| `swarm_node_closed` | the coordinator node closes |

Worker nodes then stream as normal from step index **1**, with `__coordinator__`
as their initial parent node.

**Budget accounting:** `#[MaxAgentSteps(N)]` counts the coordinator as step 0, so
at most `N - 1` worker executions run. The `__` prefix is reserved for framework
node ids — the planner rejects a plan node id that starts with `__`.

**Parallel branches:** control concurrent vs. sequential parallel-branch
streaming for a hierarchical swarm with the
`swarm.hierarchical.stream_parallel_branches` config key (default `concurrent`)
or `#[StreamParallelBranches]` on the swarm — independent of the
static-hierarchical key of the same name.

---

## Structure as Payload: Reading a Run's Shape

Every streamed event carries a nullable `node_id` tagging the run-structure node
it belongs to (null = a top-level event with no enclosing node). Three structural
events bracket each node:

- `swarm_node_opened` — self-identifying (`node_id == id`), carries
  `parent_node_id` (null at the root) and a `role`. Always recorded **before**
  any event tagged with that node id, so a reader always opens a node before it
  sees content belonging to it.
- `swarm_node_children_decided` — lists `child_node_ids` **in chosen order**
  (the declared sibling / presentation order).
- `swarm_node_closed` — the terminal bracket, carrying the node's `result`.

You rarely consume these by hand. The point of the grammar is that a *reader* —
the `CausalLogView` below, or your own dashboard — can rebuild the run tree from
the log alone. See [Lifecycle Events § Structural Stream Events](events.md#structural-stream-events-v0150)
for the exact payload of each.

---

## Folding the Log with `CausalLogView`

The log is the single source of truth and is never rewritten. To present it, you
**fold** it. `CausalLogView` is a pure, read-only, deterministic layer: the same
log always yields the same view, and a fold never writes to the store.

```php
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;

$view = CausalLogView::forRun(app(StreamEventStore::class), $runId);

// The default fold: causal order, void-edges honored.
$events = $view->fold();

// A forensic fold: keep events in stored order, surface everything that was voided.
$audit = $view->fold(ViewOrder::Causal, ViewSupersession::Everything);
```

You can also construct a view from any `iterable<SwarmStreamEvent>` — e.g. an
in-memory replay — not just a store-backed run.

A fold has two independent axes:

### Order axis — `ViewOrder`

| Case | Meaning |
| --- | --- |
| `Causal` (default) | Events stay in stored append order. |
| `Presentation` | Sibling node open/close are reordered into the order their parent declared via `child_node_ids` (a stable sort — undeclared nodes keep causal order). |

### Supersession axis — `ViewSupersession`

| Case | Meaning |
| --- | --- |
| `Clean` (default) | Void-edges are honored: a `supersedes`/`replaces` target is suppressed, an `abandons` target *and its node subtree* are suppressed, and a `rolled_up` target's node events are suppressed. |
| `Everything` | Voided events are surfaced as `VoidedEvent` wrappers carrying their `voidType`, `reason`, and (for a rollup) `digestNodeId` — so an audit view never shows a retracted event without its mark. |

The fold reads structure from each event's payload by string key (`node_id`,
`parent_node_id`, `child_node_ids`), so it degrades gracefully on a log with no
node structure: presentation order collapses to causal order, and `abandons`
suppresses only its single target. A pre-v0.15 persisted log folds cleanly.

### Void-edges

A **void-edge** is how the log records a course-correction without deleting
anything. It is itself an event pointing at the `event_uuid` of an earlier event,
with a typed reason:

| Type | When | Fold effect (`Clean`) |
| --- | --- | --- |
| `supersedes` | A semantic revision — the workflow chose a different path. | Suppress the target. |
| `replaces` | A crash-retry of the same logical step. | Suppress the target. |
| `abandons` | Terminal cancellation. | Suppress the target **and its node subtree**. |
| `rolled_up` | A rollup digested this node (below). | Suppress **only** the digested node's own events. |

See [Lifecycle Events § Causal void-edges](events.md#causal-void-edges-282-289)
for the `CausalVoidEdgeType` enum values.

---

## Rollup Nodes

A long generation — a wide parallel fan-out, a loop that runs many iterations —
produces a lot of context. A **rollup node** lets you, the author, digest that
generation into one summary and bound what flows downstream. It is the
context-bounding idiom that pairs with the growth policy below.

A rollup is a plan node with `'type' => 'rollup'`. It is shaped exactly like a
worker — `agent`, `prompt`, `with_outputs`, `next` — and you can place it in
**both** static-hierarchical and hierarchical (coordinator-generated) plans.

```php
public function plan(): array
{
    return [
        'start_at' => 'gather',
        'nodes' => [
            'gather' => [
                'type' => 'parallel',
                'branches' => ['source_a', 'source_b', 'source_c'],
                'next' => 'digest',
            ],
            'source_a' => ['type' => 'worker', 'agent' => SourceAgent::class, 'prompt' => 'Summarize source A.'],
            'source_b' => ['type' => 'worker', 'agent' => SourceAgent::class, 'prompt' => 'Summarize source B.'],
            'source_c' => ['type' => 'worker', 'agent' => SourceAgent::class, 'prompt' => 'Summarize source C.'],

            // The rollup digests the three sources into one brief.
            'digest' => [
                'type' => 'rollup',
                'agent' => DigesterAgent::class,
                'prompt' => 'Digest these source summaries into a single brief.',
                'with_outputs' => [
                    'a' => 'source_a',
                    'b' => 'source_b',
                    'c' => 'source_c',
                ],
                'next' => 'write',
            ],

            // Downstream reads the digest, never the raw sources.
            'write' => [
                'type' => 'worker',
                'agent' => WriterAgent::class,
                'prompt' => 'Write the report from the brief.',
                'with_outputs' => ['brief' => 'digest'],
                'next' => 'finish',
            ],
            'finish' => ['type' => 'finish', 'output_from' => 'write'],
        ],
    ];
}
```

`with_outputs` does double duty: it is the digester's prompt context **and** it
names the generation being digested. After the digest output is recorded, the
walk runs three rollup-only effects:

1. **Operational bounding.** The digested nodes are pruned from the in-process
   node-output map (and from the persisted hierarchical boundary that survives
   resume), so a downstream node can only read the digest, never the raw
   generation.
2. **Display fold.** A `rolled_up` void-edge is appended against each digested
   node's current step-end, so `CausalLogView` suppresses exactly those nodes'
   events. In the `Everything` view they surface as `VoidedEvent`s carrying a
   `digestNodeId` pointer to the summary.
3. **Sealability.** A mid-run seal barrier is appended so the
   [compaction worker](operator-runbook-streaming-substrate.md) can graduate the
   digested window to cold storage **mid-run** rather than only at completion.

### Unreferenceability is enforced, not assumed

Because a rollup's purpose is that downstream reads the digest, the framework
**rejects at plan materialization** any node ordered after a rollup that
references a digested node — via a later `with_outputs` or a finish node's
`output_from`. It fails loud before the walk runs, naming the rollup to reference
instead, so you never get a silent empty read on resume. This holds for both
`fromStaticPlan` and the coordinator-driven `fromCoordinatorOutput`.

### Rollups and loops

A rollup composes with [bounded loops](static-hierarchical-topology.md#bounded-loops):
place it on a loop body's forward path and it digests each iteration's fresh
outputs (it targets the live, unsealed step-end, never the once-only node-open
event a prior iteration may already have sealed). A rollup carries no loop of its
own — the planner rejects a `loop` on a rollup node.

### Scope and degradation

- **Hierarchical topology only** — the static-plan and coordinator-driven walk.
  The pure `Sequential` runner has no plan nodes and is unaffected.
- **The display fold + seal require the database causal log.** Off the database
  driver only the operational prune applies, and the display fold degrades to
  showing the raw nodes.
- The void-edges and barrier are written in **one transaction** and the seal is
  **idempotent**, so a re-dispatched or re-executed pass never double-voids.

> **What a rollup does and does not bound.** A rollup bounds the **agent prompt
> context** (downstream reads one digest) and the **hot causal log** (the
> digested window becomes reclaimable to cold). It does **not** lower the
> context-growth budget metric below — that metric counts cumulative emitted
> stream events per segment and is monotonic by design, so you will not see it
> drop after a rollup.

---

## Context-Growth Policy

A streaming run accumulates a hot working set of events. The context-growth
policy is how a run's growth is governed **by declared intent** rather than by
surprise. There are two dials:

- **You** (the author) declare a *policy* — how the framework should react when
  the working set exceeds the budget.
- **The operator** supplies the *budget* number and an optional *hard-cap* veto
  via config. See the
  [operator runbook](operator-runbook-streaming-substrate.md#5-context-growth-budget).

Declare your intent with `#[ContextGrowthPolicy]`:

```php
use BuiltByBerry\LaravelSwarm\Attributes\ContextGrowthPolicy;
use BuiltByBerry\LaravelSwarm\Enums\GrowthPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

#[ContextGrowthPolicy(GrowthPolicy::Backpressure)]
class ResearchSwarm implements Swarm
{
    // ...
}
```

When absent, the framework default comes from `swarm.context_growth.policy`
(itself `degrade_to_cold`).

### The ladder

`GrowthPolicy` is a set of **cumulative severity bands** — a declared rung
includes the behaviour of every lower one:

| Rung | Behaviour |
| --- | --- |
| `Ignore` | Take no action. (The operator hard-cap still applies.) |
| `Warn` | Emit a `context_growth.action` telemetry event + a throttled warning. |
| `DegradeToCold` | Warn, and nudge background compaction to reclaim what it can. **The framework default** — loud and least-destructive. |
| `Backpressure` | Warn, degrade, and insert a bounded delay at the step boundary. |
| `Refuse` | Warn, then abort the run loud with a re-dispatchable `ContextBudgetExceededException`. |

The policy is evaluated at each **step boundary** by the streaming runners,
measuring the run's hot working set as its in-process stream-event count — the
same rows compaction reclaims.

### What you can rely on

- **The hard cap clamps your intent.** A hard-cap breach refuses the run
  regardless of your declared policy (even `Ignore`), mirroring the
  `swarm.limits.*` precedent. The hard cap is best-effort governance, not a
  correctness invariant.
- **Fail-safe.** A throwing, slow, or mis-measuring policy degrades to the
  least-destructive action and the run proceeds. The *only* intentional throw is
  the `Refuse` / hard-cap `ContextBudgetExceededException`, which is never
  swallowed — catch it (or let a durable run re-dispatch it) if you opt into
  `Refuse`.
- **Per segment, idempotent on resume.** The working set is measured per stream
  segment (it resets to zero when a run resumes in a fresh process), so the
  budget and hard-cap are enforced per segment, not across a run's full history.
- **Streaming substrate only.** A non-streaming `prompt()` run does not
  accumulate a hot causal log and is out of scope.

> **`DegradeToCold` needs a seal to act on.** Within a single non-rollup run
> there may be no sealed prefix to reclaim until completion. The nudge becomes
> functional once a seal barrier exists — at run end, or mid-run once a
> [rollup](#rollup-nodes) seals a window. The timely live-growth responses are
> `Backpressure` and `Refuse`.

---

## Related

- [Streaming](streaming.md) — `stream()`, replay, crash-replay durability, capture
- [Streaming Substrate Operator Runbook](operator-runbook-streaming-substrate.md) — tiering, compaction, recovery, quarantine
- [Lifecycle Events](events.md#structural-stream-events-v0150) — structural + causal event payloads
- [Static Hierarchical Topology](static-hierarchical-topology.md) — route plans, parallel groups, bounded loops
- [Public Surface](public-surface.md#streaming-substrate-v0150) — contracts, classes, and the `CausalLogView` API
