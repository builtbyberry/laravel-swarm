<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeChildrenDecided;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeClosed;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmNodeOpened;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Swarms\StreamingStaticHierarchicalDeciderSwarm;
use Illuminate\Support\Facades\Artisan;

/**
 * The structural stream-event grammar (#284): a decider streams its
 * deliberation under its node id and terminates that deliberation in a
 * children-decided event, all in causal order on the append-only log, all
 * readable back through the store. This is the producer side of the wire
 * contract #283's read-policy fold layer consumes.
 */
beforeEach(function () {
    config()->set('swarm.persistence.driver', 'database');
    config()->set('database.default', 'testing');
    config()->set('swarm.streaming.replay.enabled', true);
    Artisan::call('migrate:fresh', ['--database' => 'testing']);
});

test('a streamed decider emits node.opened before its deliberation and terminates in children-decided', function () {
    $stream = StreamingStaticHierarchicalDeciderSwarm::make()->stream('kick off');

    $live = iterator_to_array($stream);

    // Locate the decider node id from the opened event it streamed. The decider
    // is the coordinator-role root node; node.opened is self-identifying.
    $opened = collect($live)
        ->whereInstanceOf(SwarmNodeOpened::class)
        ->firstWhere('role', 'coordinator');

    expect($opened)->not->toBeNull();
    $deciderNodeId = $opened->nodeId;
    expect($deciderNodeId)->toBe($opened->id); // self-identifying convention.
    expect($opened->parentNodeId)->toBeNull(); // root node has no parent.

    // The decider's deliberation (its text deltas) is tagged with its node id.
    $deciderDeltas = collect($live)
        ->whereInstanceOf(SwarmTextDelta::class)
        ->filter(fn (SwarmTextDelta $e): bool => $e->nodeId === $deciderNodeId);
    expect($deciderDeltas)->not->toBeEmpty();

    // The decider terminates its deliberation in a children-decided event whose
    // node_id is the decider's, declaring its chosen child in order.
    $decided = collect($live)
        ->whereInstanceOf(SwarmNodeChildrenDecided::class)
        ->firstWhere('nodeId', $deciderNodeId);
    expect($decided)->not->toBeNull();
    expect($decided->childNodeIds)->toBe(['child_node']);

    // Causal completeness over the LIVE order: opened precedes every event
    // tagged with the decider's id, and children-decided follows the deltas.
    $order = [];
    foreach ($live as $i => $event) {
        if ($event instanceof SwarmNodeOpened && $event->nodeId === $deciderNodeId) {
            $order['opened'] = $i;
        }
        if ($event instanceof SwarmTextDelta && $event->nodeId === $deciderNodeId && ! isset($order['firstDelta'])) {
            $order['firstDelta'] = $i;
        }
        if ($event instanceof SwarmNodeChildrenDecided && $event->nodeId === $deciderNodeId) {
            $order['decided'] = $i;
        }
        if ($event instanceof SwarmNodeClosed && $event->nodeId === $deciderNodeId) {
            $order['closed'] = $i;
        }
    }

    expect($order['opened'])->toBeLessThan($order['firstDelta']);
    expect($order['firstDelta'])->toBeLessThan($order['decided']);
    expect($order['decided'])->toBeLessThan($order['closed']);
});

test('the decider grammar reads back through the causal log store in causal order', function () {
    $stream = StreamingStaticHierarchicalDeciderSwarm::make()->stream('kick off');

    // Drain the stream so every event is persisted to the (replay-enabled) store.
    iterator_to_array($stream);

    // Read the persisted log back through the store — this is the realistic
    // fold path #283 consumes: rehydrated SwarmStreamEvents in DB-id order.
    /** @var array<int, SwarmStreamEvent> $stored */
    $stored = iterator_to_array(app(StreamEventStore::class)->events($stream->runId));
    expect($stored)->not->toBeEmpty();

    $types = array_map(fn (SwarmStreamEvent $e): string => $e->type(), $stored);

    // The structural events survived the persist/rehydrate round trip.
    expect($types)->toContain('swarm_node_opened');
    expect($types)->toContain('swarm_node_children_decided');
    expect($types)->toContain('swarm_node_closed');

    // Recover the decider node id from the persisted, rehydrated opened event.
    $opened = collect($stored)
        ->whereInstanceOf(SwarmNodeOpened::class)
        ->firstWhere('role', 'coordinator');
    expect($opened)->not->toBeNull();
    $deciderNodeId = $opened->nodeId;

    // node.opened precedes every persisted event carrying the decider's node id
    // (causal completeness), and children-decided precedes node.closed.
    $openedIndex = null;
    $firstTaggedIndex = null;
    $decidedIndex = null;
    $closedIndex = null;

    foreach ($stored as $i => $event) {
        if ($event instanceof SwarmNodeOpened && $event->nodeId === $deciderNodeId) {
            $openedIndex = $i;
        }
        if ($event->nodeId === $deciderNodeId && ! $event instanceof SwarmNodeOpened && $firstTaggedIndex === null) {
            $firstTaggedIndex = $i;
        }
        if ($event instanceof SwarmNodeChildrenDecided && $event->nodeId === $deciderNodeId) {
            $decidedIndex = $i;
        }
        if ($event instanceof SwarmNodeClosed && $event->nodeId === $deciderNodeId) {
            $closedIndex = $i;
        }
    }

    expect($openedIndex)->toBeInt();
    expect($firstTaggedIndex)->toBeInt();
    expect($decidedIndex)->toBeInt();
    expect($closedIndex)->toBeInt();

    // Opened is the FIRST event carrying the decider's id — nothing tagged with
    // that node precedes its open.
    expect($openedIndex)->toBeLessThan($firstTaggedIndex);
    expect($decidedIndex)->toBeLessThan($closedIndex);

    // The persisted children-decided declares the chosen child in order.
    $decided = $stored[$decidedIndex];
    expect($decided)->toBeInstanceOf(SwarmNodeChildrenDecided::class);
    expect($decided->childNodeIds)->toBe(['child_node']);
});
