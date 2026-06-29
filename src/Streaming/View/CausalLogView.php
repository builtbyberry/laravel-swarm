<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\View;

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * A read-time policy/fold layer over the append-only causal log (#283).
 *
 * The log is the truth; this view never writes to it. Every shaping a consumer
 * asks for is a pure fold of the same stored events along two independent axes —
 * {@see ViewOrder} (causal vs. presentation order) and {@see ViewSupersession}
 * (clean vs. everything). The fold is a deterministic function of the log: the
 * same log always folds to the same view.
 *
 * The fold reads *structure* from each event's `toArray()` payload by string key
 * (`node_id`, `parent_node_id`, `child_node_ids`, the void-edge fields) rather
 * than from typed properties, so it is decoupled from the node-event classes that
 * carry that structure. Where a payload omits `node_id` — every #282-era event —
 * the view degrades gracefully: presentation order collapses to causal order and
 * an `abandons` edge suppresses only its single target.
 */
final class CausalLogView
{
    /**
     * The materialized hot window, in causal (DB-id append) order.
     *
     * @var list<SwarmStreamEvent>
     */
    private array $events;

    /**
     * Void-edges grouped by the `event_uuid` they target, each list in causal
     * order. A target may collect more than one edge (apply last-wins).
     *
     * @var array<string, list<array{type: CausalVoidEdgeType, reason: string, digest_node_id: string|null}>>
     */
    private array $voidsByTarget = [];

    /**
     * Node parent map: `node_id` => `parent_node_id` (from `swarm_node_opened`).
     *
     * @var array<string, ?string>
     */
    private array $parentOf = [];

    /**
     * Declared sibling order: parent `node_id` => ordered child `node_id`s
     * (from `swarm_node_children_decided`). Absence means "no declared order".
     *
     * @var array<string, list<string>>
     */
    private array $declaredChildren = [];

    /**
     * The `node_id` each event belongs to, keyed by the event's own id, built
     * once in {@see index()}. A null value means the event carries no node;
     * an absent key means no event with that id is in the window (dangling). This
     * is what lets {@see nodeOfEvent()} resolve an abandon target's node without
     * re-scanning the log per edge.
     *
     * @var array<string, ?string>
     */
    private array $nodeIdByEventId = [];

    /**
     * @param  iterable<SwarmStreamEvent>  $events  Typically `StreamEventStore::events($runId)`.
     */
    public function __construct(iterable $events)
    {
        $this->events = $this->materialize($events);

        $this->index();
    }

    /**
     * Fold the run's persisted log into a view.
     */
    public static function forRun(StreamEventStore $store, string $runId): self
    {
        return new self($store->events($runId));
    }

    /**
     * Serialize the accumulated fold state for compactor resume (#287).
     *
     * Produces a plain array suitable for `json_encode()` and cold-storage
     * sealing. The compactor stores the sealed snapshot alongside the raw event
     * rows so a future re-read can reconstruct the view's index without
     * replaying the full log from scratch.
     *
     * @return array{
     *     events: list<array<string, mixed>>,
     *     voids_by_target: array<string, list<array{type: string, reason: string, digest_node_id: string|null}>>,
     *     parent_of: array<string, string|null>,
     *     declared_children: array<string, list<string>>,
     *     node_id_by_event_id: array<string, string|null>,
     * }
     */
    public function snapshot(): array
    {
        $voidsByTarget = [];

        foreach ($this->voidsByTarget as $target => $edges) {
            $voidsByTarget[$target] = array_map(
                fn (array $edge): array => [
                    'type' => $edge['type']->value,
                    'reason' => $edge['reason'],
                    'digest_node_id' => $edge['digest_node_id'],
                ],
                $edges,
            );
        }

        return [
            'events' => array_map(fn (SwarmStreamEvent $e): array => $e->toArray(), $this->events),
            'voids_by_target' => $voidsByTarget,
            'parent_of' => $this->parentOf,
            'declared_children' => $this->declaredChildren,
            'node_id_by_event_id' => $this->nodeIdByEventId,
        ];
    }

    /**
     * Fold the log into an ordered list of events under the two view axes.
     *
     * Under {@see ViewSupersession::Clean} voided events are absent; under
     * {@see ViewSupersession::Everything} they are present as {@see VoidedEvent}
     * wrappers carrying their void type and reason. Order follows the
     * {@see ViewOrder} axis.
     *
     * @return list<SwarmStreamEvent|VoidedEvent>
     */
    public function fold(
        ViewOrder $order = ViewOrder::Causal,
        ViewSupersession $supersession = ViewSupersession::Clean,
    ): array {
        $ordered = $order === ViewOrder::Presentation
            ? $this->presentationOrdered()
            : $this->events;

        $annotations = $this->voidAnnotations();

        $folded = [];

        foreach ($ordered as $event) {
            $id = $this->eventId($event);
            $annotation = $id !== null ? ($annotations[$id] ?? null) : null;

            if ($annotation === null) {
                $folded[] = $event;

                continue;
            }

            if ($supersession === ViewSupersession::Clean) {
                // Voided (directly, or as a member of an abandoned subtree) — hidden.
                continue;
            }

            $folded[] = new VoidedEvent($event, $annotation['type'], $annotation['reason'], $annotation['digest_node_id']);
        }

        return $folded;
    }

    /**
     * Pass 1 — index void-edges, the node parent map, and declared child order.
     */
    private function index(): void
    {
        foreach ($this->events as $event) {
            $payload = $event->toArray();
            $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;

            $id = is_string($payload['id'] ?? null) ? $payload['id'] : null;

            if ($id !== null) {
                $this->nodeIdByEventId[$id] = is_string($payload['node_id'] ?? null) ? $payload['node_id'] : null;
            }

            match ($type) {
                'swarm_causal_void_edge' => $this->indexVoidEdge($payload),
                'swarm_node_opened' => $this->indexNodeOpened($payload),
                'swarm_node_children_decided' => $this->indexChildrenDecided($payload),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function indexVoidEdge(array $payload): void
    {
        $target = $payload['target_event_id'] ?? null;
        $voidType = CausalVoidEdgeType::tryFrom(is_string($payload['void_type'] ?? null) ? $payload['void_type'] : '');

        if (! is_string($target) || $voidType === null) {
            return;
        }

        $this->voidsByTarget[$target][] = [
            'type' => $voidType,
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
            'digest_node_id' => is_string($payload['digest_node_id'] ?? null) ? $payload['digest_node_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function indexNodeOpened(array $payload): void
    {
        $nodeId = $payload['node_id'] ?? null;

        if (! is_string($nodeId)) {
            return;
        }

        $parent = $payload['parent_node_id'] ?? null;
        $this->parentOf[$nodeId] = is_string($parent) ? $parent : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function indexChildrenDecided(array $payload): void
    {
        $parent = $payload['node_id'] ?? null;
        $children = $payload['child_node_ids'] ?? null;

        if (! is_string($parent) || ! is_array($children)) {
            return;
        }

        $this->declaredChildren[$parent] = array_values(array_filter($children, 'is_string'));
    }

    /**
     * Every event id a void-edge annotates, mapped to the type and reason it was
     * voided under. A direct target takes its last (most recent) edge in causal
     * order; an `abandons` edge additionally annotates every event in the
     * abandoned node's subtree with that same type and reason, so the whole branch
     * reads as abandoned — both in the clean view (all hidden) and the everything
     * view (all surfaced as {@see VoidedEvent}). A direct annotation wins over an
     * inherited subtree one. Degrades to the single target when no `node_id`
     * structure is present.
     *
     * @return array<string, array{type: CausalVoidEdgeType, reason: string, digest_node_id: string|null}>
     */
    private function voidAnnotations(): array
    {
        $annotations = [];

        // node_id => reason it was directly abandoned under.
        $abandoned = [];

        // node_id => ['reason' => …, 'digest_node_id' => …] for rolled-up nodes.
        $rolledUp = [];

        foreach ($this->voidsByTarget as $targetId => $edges) {
            $last = $edges[count($edges) - 1];
            $annotations[$targetId] = [
                'type' => $last['type'],
                'reason' => $last['reason'],
                'digest_node_id' => $last['digest_node_id'],
            ];

            if ($last['type'] === CausalVoidEdgeType::Abandons) {
                $node = $this->nodeOfEvent($targetId);

                if ($node !== null) {
                    $abandoned[$node] = $last['reason'];
                }
            }

            if ($last['type'] === CausalVoidEdgeType::RolledUp) {
                $node = $this->nodeOfEvent($targetId);

                if ($node !== null) {
                    $rolledUp[$node] = ['reason' => $last['reason'], 'digest_node_id' => $last['digest_node_id']];
                }
            }
        }

        // A rolled-up node's OWN events are suppressed by node_id membership —
        // never its causal descendants (#289 R6). The sequential walk records
        // each node as the parent of its `next`, so a subtree expansion (as
        // Abandons does) would erase the entire forward chain including the
        // rollup and everything after it. Membership suppresses exactly the
        // digested nodes and leaves the chain intact.
        if ($rolledUp !== []) {
            foreach ($this->events as $event) {
                $node = $this->nodeIdOf($event);

                if ($node === null || ! isset($rolledUp[$node])) {
                    continue;
                }

                $id = $this->eventId($event);

                // A direct edge on this specific event (e.g. its own supersede)
                // is more specific than the rollup membership, so it wins.
                if ($id === null || isset($annotations[$id])) {
                    continue;
                }

                $annotations[$id] = [
                    'type' => CausalVoidEdgeType::RolledUp,
                    'reason' => $rolledUp[$node]['reason'],
                    'digest_node_id' => $rolledUp[$node]['digest_node_id'],
                ];
            }
        }

        if ($abandoned === []) {
            return $annotations;
        }

        $subtree = $this->subtreeNodeIds(array_keys($abandoned));

        foreach ($this->events as $event) {
            $node = $this->nodeIdOf($event);

            if ($node === null || ! isset($subtree[$node])) {
                continue;
            }

            $id = $this->eventId($event);

            // A direct edge on this event (e.g. its own supersede) is more
            // specific than the inherited abandonment, so it is left untouched.
            if ($id === null || isset($annotations[$id])) {
                continue;
            }

            $annotations[$id] = [
                'type' => CausalVoidEdgeType::Abandons,
                'reason' => $this->abandonReasonFor($node, $abandoned),
                'digest_node_id' => null,
            ];
        }

        return $annotations;
    }

    /**
     * The reason a node inherits from the nearest abandoned ancestor (or itself),
     * climbing the parent map. Returns '' if no abandoned ancestor is found — a
     * defensive fallback the subtree membership check makes unreachable.
     *
     * @param  array<string, string>  $abandoned  node_id => reason
     */
    private function abandonReasonFor(string $node, array $abandoned): string
    {
        $cursor = $node;
        $guard = 0;

        while ($cursor !== null && $guard++ <= count($this->parentOf)) {
            if (isset($abandoned[$cursor])) {
                return $abandoned[$cursor];
            }

            $cursor = $this->parentOf[$cursor] ?? null;
        }

        return '';
    }

    /**
     * Expand a set of node ids to themselves plus every descendant node, via the
     * parent map. Void-edges only ever point backward, so the parent graph is
     * acyclic; a visited guard keeps this total even on malformed input.
     *
     * @param  list<string>  $roots
     * @return array<string, true>
     */
    private function subtreeNodeIds(array $roots): array
    {
        $subtree = [];

        foreach ($roots as $root) {
            $subtree[$root] = true;
        }

        // Repeatedly fold children of already-included nodes into the set until
        // it stops growing; the visited guard bounds this to one pass per node.
        do {
            $grew = false;

            foreach ($this->parentOf as $node => $parent) {
                if ($parent !== null && isset($subtree[$parent]) && ! isset($subtree[$node])) {
                    $subtree[$node] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        return $subtree;
    }

    /**
     * Reorder sibling events into declared (presentation) order with a stable
     * sort: events are keyed to their owning node, sibling nodes are ranked by
     * the parent's `child_node_ids`, and any event whose node has no declared
     * rank keeps its causal position. Events with no `node_id` (every #282-era
     * event) all share the synthetic root and so never move.
     *
     * @return list<SwarmStreamEvent>
     */
    private function presentationOrdered(): array
    {
        if ($this->declaredChildren === []) {
            return $this->events;
        }

        // node_id => declared rank of the sibling it falls under (itself or its
        // nearest ancestor named in a parent's child list). Unranked nodes — and
        // events with no node_id — are absent and keep their causal slot.
        $rankOf = $this->declaredRankByNode();

        // Walk the log once, collecting the causal slots that ranked events
        // occupy. Those slots are redistributed by declared rank (stable on the
        // causal index within a rank); every other event stays put.
        $slots = [];
        $ranked = [];

        foreach ($this->events as $causalIndex => $event) {
            $node = $this->nodeIdOf($event);
            $rank = $node !== null ? ($rankOf[$node] ?? null) : null;

            if ($rank === null) {
                continue;
            }

            $slots[] = $causalIndex;
            $ranked[] = ['event' => $event, 'rank' => $rank, 'causal' => $causalIndex];
        }

        if ($ranked === []) {
            return $this->events;
        }

        usort($ranked, fn (array $a, array $b): int => $a['rank'] <=> $b['rank'] ?: $a['causal'] <=> $b['causal']);

        $ordered = $this->events;

        foreach ($slots as $position => $slot) {
            $ordered[$slot] = $ranked[$position]['event'];
        }

        return array_values($ordered);
    }

    /**
     * Map every node to the declared presentation rank of the sibling subtree it
     * belongs to: a node named directly in some parent's `child_node_ids` takes
     * that index; a deeper descendant inherits the rank of its nearest declared
     * ancestor (so a child's whole subtree moves with it). Nodes under no
     * declaration are absent and keep causal order.
     *
     * @return array<string, int>
     */
    private function declaredRankByNode(): array
    {
        $siblingRank = [];

        foreach ($this->declaredChildren as $children) {
            foreach ($children as $index => $child) {
                // First declaration wins; void-edges only point backward so a
                // node is never re-parented into a different sibling group.
                $siblingRank[$child] ??= $index;
            }
        }

        $rankOf = [];

        foreach (array_keys($this->parentOf) as $node) {
            $cursor = $node;
            $guard = 0;

            // Climb to the nearest ancestor (or self) carrying a declared rank.
            while ($cursor !== null && ! isset($siblingRank[$cursor]) && $guard++ < count($this->parentOf)) {
                $cursor = $this->parentOf[$cursor] ?? null;
            }

            if ($cursor !== null && isset($siblingRank[$cursor])) {
                $rankOf[$node] = $siblingRank[$cursor];
            }
        }

        return $rankOf;
    }

    /**
     * The node a void-edge's target event belongs to, or null when the target is
     * dangling (absent from the window) or carries no `node_id`.
     */
    private function nodeOfEvent(string $eventId): ?string
    {
        return $this->nodeIdByEventId[$eventId] ?? null;
    }

    /**
     * @param  iterable<SwarmStreamEvent>  $events
     * @return list<SwarmStreamEvent>
     */
    private function materialize(iterable $events): array
    {
        return is_array($events) ? array_values($events) : iterator_to_array($events, false);
    }

    private function eventId(SwarmStreamEvent $event): ?string
    {
        $id = $event->toArray()['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function nodeIdOf(SwarmStreamEvent $event): ?string
    {
        $nodeId = $event->toArray()['node_id'] ?? null;

        return is_string($nodeId) ? $nodeId : null;
    }
}
