<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\View\CausalLogView;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewOrder;
use BuiltByBerry\LaravelSwarm\Streaming\View\ViewSupersession;
use BuiltByBerry\LaravelSwarm\Streaming\View\VoidedEvent;
use BuiltByBerry\LaravelSwarm\Tests\Fixtures\Streaming\SyntheticCausalEvent;

/**
 * @param  array<int, SwarmStreamEvent|VoidedEvent>  $folded
 * @return list<string>
 */
function ids(array $folded): array
{
    return array_map(function (SwarmStreamEvent|VoidedEvent $row): string {
        $event = $row instanceof VoidedEvent ? $row->event : $row;

        return (string) ($event->toArray()['id'] ?? '');
    }, $folded);
}

// ---------------------------------------------------------------------------
// Purity / determinism
// ---------------------------------------------------------------------------

test('an empty log folds to an empty view on every axis', function () {
    $view = new CausalLogView([]);

    expect($view->fold(ViewOrder::Causal, ViewSupersession::Clean))->toBe([])
        ->and($view->fold(ViewOrder::Presentation, ViewSupersession::Everything))->toBe([]);
});

test('the causal+everything fold returns events untouched in append order', function () {
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::leaf('b'),
        SyntheticCausalEvent::leaf('c'),
    ];

    $folded = (new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Everything);

    expect(ids($folded))->toBe(['a', 'b', 'c']);
});

test('folding is deterministic and idempotent', function () {
    $events = [
        SyntheticCausalEvent::nodeOpened('o-root', 'root', null),
        SyntheticCausalEvent::nodeOpened('o-1', 'n1', 'root'),
        SyntheticCausalEvent::nodeOpened('o-2', 'n2', 'root'),
        SyntheticCausalEvent::childrenDecided('cd', 'root', ['n2', 'n1']),
        SyntheticCausalEvent::voidEdge('v', 'supersedes', 'o-1', 'revised'),
    ];

    $view = new CausalLogView($events);
    $first = ids($view->fold(ViewOrder::Presentation, ViewSupersession::Clean));
    $second = ids($view->fold(ViewOrder::Presentation, ViewSupersession::Clean));

    expect($first)->toBe($second);
});

// ---------------------------------------------------------------------------
// ORDER axis — presentation order
// ---------------------------------------------------------------------------

test('presentation order reorders sibling open/close by declared structure regardless of arrival', function () {
    // Siblings arrive n1 then n2, but the parent declared n2 before n1.
    $events = [
        SyntheticCausalEvent::nodeOpened('o-root', 'root', null),
        SyntheticCausalEvent::childrenDecided('cd', 'root', ['n2', 'n1']),
        SyntheticCausalEvent::nodeOpened('o-1', 'n1', 'root'),
        SyntheticCausalEvent::nodeClosed('c-1', 'n1'),
        SyntheticCausalEvent::nodeOpened('o-2', 'n2', 'root'),
        SyntheticCausalEvent::nodeClosed('c-2', 'n2'),
    ];

    $folded = (new CausalLogView($events))->fold(ViewOrder::Presentation, ViewSupersession::Everything);

    // n2's open/close come before n1's; the root + decision keep causal position.
    expect(ids($folded))->toBe(['o-root', 'cd', 'o-2', 'c-2', 'o-1', 'c-1']);
});

test('nodes without a declared child list keep causal order (stable sort)', function () {
    $events = [
        SyntheticCausalEvent::nodeOpened('o-root', 'root', null),
        SyntheticCausalEvent::nodeOpened('o-x', 'nx', 'root'),
        SyntheticCausalEvent::nodeOpened('o-y', 'ny', 'root'),
        // No children-decided event: nothing is ranked, so order is unchanged.
    ];

    $folded = (new CausalLogView($events))->fold(ViewOrder::Presentation, ViewSupersession::Everything);

    expect(ids($folded))->toBe(['o-root', 'o-x', 'o-y']);
});

test('presentation order degrades to causal order when no structure is present', function () {
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::leaf('b'),
    ];

    $causal = ids((new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Everything));
    $presentation = ids((new CausalLogView($events))->fold(ViewOrder::Presentation, ViewSupersession::Everything));

    expect($presentation)->toBe($causal);
});

// ---------------------------------------------------------------------------
// SUPERSESSION axis — clean vs everything
// ---------------------------------------------------------------------------

test('clean view suppresses a superseded target; everything view exposes it with its reason', function () {
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::leaf('b'),
        SyntheticCausalEvent::voidEdge('v', 'supersedes', 'b', 'coordinator re-routed'),
    ];

    $view = new CausalLogView($events);

    expect(ids($view->fold(ViewOrder::Causal, ViewSupersession::Clean)))->toBe(['a', 'v']);

    $everything = $view->fold(ViewOrder::Causal, ViewSupersession::Everything);
    expect(ids($everything))->toBe(['a', 'b', 'v']);

    $voided = collect($everything)->first(fn ($row) => $row instanceof VoidedEvent);
    expect($voided->voidType)->toBe(CausalVoidEdgeType::Supersedes)
        ->and($voided->reason)->toBe('coordinator re-routed');
});

test('a replaces edge suppresses its target in the clean view', function () {
    $events = [
        SyntheticCausalEvent::leaf('first-attempt'),
        SyntheticCausalEvent::leaf('retry'),
        SyntheticCausalEvent::voidEdge('v', 'replaces', 'first-attempt', 'crash retry'),
    ];

    $clean = ids((new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Clean));

    expect($clean)->toBe(['retry', 'v']);
});

test('abandons suppresses its target AND its subtree in the clean view', function () {
    // root -> n1 -> n1a ; n1 also has a sibling n2 that must survive.
    $events = [
        SyntheticCausalEvent::nodeOpened('o-root', 'root', null),
        SyntheticCausalEvent::nodeOpened('o-1', 'n1', 'root'),
        SyntheticCausalEvent::leaf('leaf-1', 'n1'),
        SyntheticCausalEvent::nodeOpened('o-1a', 'n1a', 'n1'),
        SyntheticCausalEvent::leaf('leaf-1a', 'n1a'),
        SyntheticCausalEvent::nodeOpened('o-2', 'n2', 'root'),
        SyntheticCausalEvent::leaf('leaf-2', 'n2'),
        SyntheticCausalEvent::voidEdge('v', 'abandons', 'o-1', 'operator cancelled the branch'),
    ];

    $clean = ids((new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Clean));

    // Everything under n1 (incl. descendant n1a) is gone; root, n2 survive.
    expect($clean)->toBe(['o-root', 'o-2', 'leaf-2', 'v']);
});

test('everything view keeps abandoned subtree events and wraps only the direct target', function () {
    $events = [
        SyntheticCausalEvent::nodeOpened('o-1', 'n1', 'root'),
        SyntheticCausalEvent::leaf('leaf-1', 'n1'),
        SyntheticCausalEvent::voidEdge('v', 'abandons', 'o-1', 'cancelled'),
    ];

    $everything = (new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Everything);

    expect(ids($everything))->toBe(['o-1', 'leaf-1', 'v']);

    $wrapped = collect($everything)->filter(fn ($row) => $row instanceof VoidedEvent)->values();
    expect($wrapped)->toHaveCount(1)
        ->and($wrapped->first()->event->toArray()['id'])->toBe('o-1')
        ->and($wrapped->first()->voidType)->toBe(CausalVoidEdgeType::Abandons);
});

// ---------------------------------------------------------------------------
// Edge cases that must not crash
// ---------------------------------------------------------------------------

test('a dangling void-edge whose target is absent is a no-op in clean and shown in everything', function () {
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::voidEdge('v', 'supersedes', 'ghost-target', 'points at nothing'),
    ];

    $view = new CausalLogView($events);

    expect(ids($view->fold(ViewOrder::Causal, ViewSupersession::Clean)))->toBe(['a', 'v'])
        ->and(ids($view->fold(ViewOrder::Causal, ViewSupersession::Everything)))->toBe(['a', 'v']);
});

test('multiple void-edges on one target apply in causal order (last wins for the wrapper)', function () {
    $events = [
        SyntheticCausalEvent::leaf('b'),
        SyntheticCausalEvent::voidEdge('v1', 'supersedes', 'b', 'first'),
        SyntheticCausalEvent::voidEdge('v2', 'replaces', 'b', 'second'),
    ];

    $view = new CausalLogView($events);

    // Clean: target gone regardless of how many edges.
    expect(ids($view->fold(ViewOrder::Causal, ViewSupersession::Clean)))->toBe(['v1', 'v2']);

    $voided = collect($view->fold(ViewOrder::Causal, ViewSupersession::Everything))
        ->first(fn ($row) => $row instanceof VoidedEvent);

    expect($voided->voidType)->toBe(CausalVoidEdgeType::Replaces)
        ->and($voided->reason)->toBe('second');
});

test('abandons with no node_id structure suppresses only the single target', function () {
    // #282-degraded: the target carries no node_id, so no subtree is known.
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::leaf('b'),
        SyntheticCausalEvent::leaf('c'),
        SyntheticCausalEvent::voidEdge('v', 'abandons', 'b', 'cancelled'),
    ];

    $clean = ids((new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Clean));

    expect($clean)->toBe(['a', 'c', 'v']);
});

test('a void-edge with an unrecognized void_type is ignored', function () {
    $events = [
        SyntheticCausalEvent::leaf('a'),
        SyntheticCausalEvent::voidEdge('v', 'not-a-real-type', 'a', 'garbage'),
    ];

    $clean = ids((new CausalLogView($events))->fold(ViewOrder::Causal, ViewSupersession::Clean));

    expect($clean)->toBe(['a', 'v']);
});

test('the two axes compose: presentation order with a clean supersession', function () {
    $events = [
        SyntheticCausalEvent::nodeOpened('o-root', 'root', null),
        SyntheticCausalEvent::childrenDecided('cd', 'root', ['n2', 'n1']),
        SyntheticCausalEvent::nodeOpened('o-1', 'n1', 'root'),
        SyntheticCausalEvent::nodeOpened('o-2', 'n2', 'root'),
        SyntheticCausalEvent::voidEdge('v', 'supersedes', 'o-1', 'dropped n1'),
    ];

    $folded = ids((new CausalLogView($events))->fold(ViewOrder::Presentation, ViewSupersession::Clean));

    // n1 suppressed; n2 ranked ahead of where n1 would have been.
    expect($folded)->toBe(['o-root', 'cd', 'o-2', 'v']);
});
