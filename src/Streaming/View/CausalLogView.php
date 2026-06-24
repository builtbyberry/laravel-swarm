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
 * (clean vs. everything). The same log yields every view, deterministically and
 * idempotently.
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
     * @var array<string, list<array{type: CausalVoidEdgeType, reason: string}>>
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

        $suppressed = $this->suppressedEventIds();

        $folded = [];

        foreach ($ordered as $event) {
            $id = $this->eventId($event);
            $void = $id !== null ? ($this->voidsByTarget[$id] ?? null) : null;

            if ($void === null) {
                $folded[] = $event;

                continue;
            }

            if ($supersession === ViewSupersession::Clean) {
                // Suppressed by its own void-edge; subtree members are handled
                // by the $suppressed membership check below.
                continue;
            }

            $last = $void[count($void) - 1];
            $folded[] = new VoidedEvent($event, $last['type'], $last['reason']);
        }

        if ($supersession === ViewSupersession::Clean && $suppressed !== []) {
            $folded = array_values(array_filter(
                $folded,
                function (SwarmStreamEvent|VoidedEvent $row) use ($suppressed): bool {
                    $event = $row instanceof VoidedEvent ? $row->event : $row;

                    return ! isset($suppressed[$this->eventId($event) ?? '']);
                },
            ));
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
     * The full set of event ids suppressed in the clean view: every void-edge
     * target, plus — for an `abandons` edge — every event belonging to the
     * abandoned node's subtree (resolved through the parent map). Degrades to the
     * single target when no `node_id` structure is present.
     *
     * @return array<string, true>
     */
    private function suppressedEventIds(): array
    {
        $suppressed = [];
        $abandonedNodes = [];

        foreach ($this->voidsByTarget as $targetId => $edges) {
            $suppressed[$targetId] = true;

            $last = $edges[count($edges) - 1];

            if ($last['type'] === CausalVoidEdgeType::Abandons) {
                $node = $this->nodeOfEvent($targetId);

                if ($node !== null) {
                    $abandonedNodes[$node] = true;
                }
            }
        }

        if ($abandonedNodes === []) {
            return $suppressed;
        }

        $subtree = $this->subtreeNodeIds(array_keys($abandonedNodes));

        foreach ($this->events as $event) {
            $node = $this->nodeIdOf($event);

            if ($node !== null && isset($subtree[$node])) {
                $id = $this->eventId($event);

                if ($id !== null) {
                    $suppressed[$id] = true;
                }
            }
        }

        return $suppressed;
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
        foreach ($this->events as $event) {
            if ($this->eventId($event) === $eventId) {
                return $this->nodeIdOf($event);
            }
        }

        return null;
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
