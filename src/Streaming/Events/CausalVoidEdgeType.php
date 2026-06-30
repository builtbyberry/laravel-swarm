<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\Events;

/**
 * The kind of causal void-edge appended to the log (#282).
 *
 * A void-edge never deletes the event it points at; it records, in causal order,
 * that a later event voids an earlier one. The fold layer (#283) interprets these
 * at read time. The three cases are deliberately distinct so a reader can tell a
 * deliberate plan revision from a mechanical crash-retry from an abandonment:
 *
 * - Supersedes — a semantic revision: the workflow chose a different path, so the
 *   earlier event no longer reflects intent (e.g. a coordinator re-routes).
 * - Replaces — a crash-retry of the same logical step: same intent, fresh attempt
 *   after a failed/abandoned execution.
 * - Abandons — terminal cancellation of an event (and, by fold convention, its
 *   subtree); no replacement follows.
 * - RolledUp — a rollup node digested this event's node into a downstream summary
 *   (#289). Unlike Abandons it suppresses ONLY the digested node's own events (by
 *   node_id), never its causal descendants — the forward chain keeps running — and
 *   it carries a digest pointer to the node that reads in its place.
 * - NodeReexecuted — a durable node crashed mid-execution and re-ran on resume
 *   (#298). Suppresses the prior attempt's events for that node by (node_id,
 *   attempt_epoch) membership — NOT by node_id alone, because the fresh attempt
 *   reuses the same node_id; only the retracted epoch's events are hidden, the
 *   re-executed epoch's events stay. Distinct from Replaces (which targets a
 *   single event) because a streamed node emits many events per attempt.
 */
enum CausalVoidEdgeType: string
{
    case Supersedes = 'supersedes';
    case Replaces = 'replaces';
    case Abandons = 'abandons';
    case RolledUp = 'rolled_up';
    case NodeReexecuted = 'node_reexecuted';
}
