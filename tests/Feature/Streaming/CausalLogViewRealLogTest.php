<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingStaticHierarchicalDeciderSwarm;
use Illuminate\Support\Facades\Artisan;

/**
 * The cross-component contract lock (post-merge follow-up to #283/#284).
 *
 * #283's read-policy fold and #284's structural grammar were built on separate
 * branches against a *pinned* wire contract, so until both merged nothing folded
 * a REAL emitted log — the fold was only ever exercised against #283's own
 * synthetic payloads (see the F1 finding). These tests close that gap: a real
 * streamed static-hierarchical decider run is persisted, then read back through
 * {@see CausalLogView}. A key rename on either side of the contract — #284
 * emitting `parent`/`children` instead of `parent_node_id`/`child_node_ids`, or
 * #283 reading the wrong key — would surface here as a structural assertion
 * failure rather than a silent degrade to causal-order/no-suppression.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('swarm.streaming.replay.enabled', true);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

/**
 * Stream the decider swarm to completion and return its run id, so every event
 * is persisted to the replay-enabled causal log the fold then reads.
 */
function streamDeciderRun(): string
{
    $stream = StreamingStaticHierarchicalDeciderSwarm::make()->stream('kick off');
    iterator_to_array($stream);

    return $stream->runId;
}

test('the fold sees #284 structure on a real persisted log — the wire contract holds end to end', function () {
    $store = app(StreamEventStore::class);
    $folded = CausalLogView::forRun($store, streamDeciderRun())
        ->fold(ViewOrder::Causal, ViewSupersession::Everything);

    $types = array_map(fn (SwarmStreamEvent $e): string => $e->type(), $folded);

    // The three structural events survived emit → persist → rehydrate → fold.
    expect($types)->toContain('swarm_node_opened')
        ->and($types)->toContain('swarm_node_children_decided')
        ->and($types)->toContain('swarm_node_closed');

    // The fold read #284's parent_node_id key: the child node names the decider
    // as its parent. If either side renamed the key, this is null and fails.
    $opened = collect($folded)->whereInstanceOf(SwarmNodeOpened::class)->keyBy(fn (SwarmNodeOpened $e) => $e->nodeId);
    expect($opened)->toHaveKeys(['decider_node', 'child_node']);
    expect($opened['decider_node']->parentNodeId)->toBeNull()
        ->and($opened['child_node']->parentNodeId)->toBe('decider_node');
});

test('every emitted node.opened is self-identifying and every tagged event resolves to an opened node (F7)', function () {
    // F7 — gate-revised: the `node_id == id` invariant is locked here, at the
    // emission boundary where #285's fan-out could break it, rather than by
    // coercing serialization (which would fight the uniform node_id round-trip).
    $stored = iterator_to_array(app(StreamEventStore::class)->events(streamDeciderRun()));

    $openedNodeIds = [];

    foreach ($stored as $event) {
        if ($event instanceof SwarmNodeOpened) {
            // The node a node.opened opens is itself: node_id == id.
            expect($event->nodeId)->toBe($event->toArray()['id']);
            $openedNodeIds[$event->nodeId] = true;
        }
    }

    expect($openedNodeIds)->not->toBeEmpty();

    // Causal completeness, the consumer's view of it: every substantive event
    // that carries a node tag belongs to a node that was actually opened — a
    // forgetful emitter (no withNodeId) would leave an orphan tag and fail here.
    foreach ($stored as $event) {
        if ($event instanceof SwarmNodeOpened) {
            continue;
        }

        if (is_string($event->nodeId)) {
            expect($openedNodeIds)->toHaveKey(
                $event->nodeId,
                "Event [{$event->type()}] is tagged with unopened node [{$event->nodeId}].",
            );
        }
    }
});

test('clean vs everything fold honors a void-edge appended against a real node (F1)', function () {
    $runId = streamDeciderRun();

    /** @var CausalLogStore $store */
    $store = app(CausalLogStore::class);

    // Abandon the child node by targeting its (self-identifying) node.opened.
    $store->appendVoidEdge($runId, CausalVoidEdgeType::Abandons, 'child_node', 'operator cancelled the subtask');

    $view = CausalLogView::forRun($store, $runId);

    $cleanIds = collect($view->fold(ViewOrder::Causal, ViewSupersession::Clean))
        ->map(fn (SwarmStreamEvent $e): ?string => $e->nodeId)
        ->all();

    // The decider survives; nothing tagged with the abandoned child remains.
    expect($cleanIds)->toContain('decider_node')
        ->and($cleanIds)->not->toContain('child_node');

    // The everything view keeps the abandoned subtree, each member surfaced as a
    // VoidedEvent under the abandon reason (the F3 behavior, on a real log).
    $abandoned = collect($view->fold(ViewOrder::Causal, ViewSupersession::Everything))
        ->filter(fn ($row): bool => $row instanceof VoidedEvent);

    expect($abandoned)->not->toBeEmpty()
        ->and($abandoned->every(fn (VoidedEvent $v): bool => $v->voidType === CausalVoidEdgeType::Abandons))->toBeTrue()
        ->and($abandoned->every(fn (VoidedEvent $v): bool => $v->reason === 'operator cancelled the subtask'))->toBeTrue()
        ->and($abandoned->every(fn (VoidedEvent $v): bool => $v->event->nodeId === 'child_node'))->toBeTrue();
});
