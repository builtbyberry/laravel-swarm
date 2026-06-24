<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\View;

/**
 * The supersession axis of a causal-log read (#283).
 *
 * Void-edges record, in causal order, that a later event voids an earlier one.
 * A read either honors those edges (hiding what was voided) or exposes the voided
 * events with the reason and type they were voided under. Both are pure folds —
 * the log keeps every event regardless.
 *
 * - Clean — voided events are suppressed. A `supersedes`/`replaces` edge hides its
 *   target; an `abandons` edge hides its target *and* the node subtree rooted at
 *   it (resolved through the parent map). Abandonment is node-granular: an
 *   `abandons` edge against *any* event carrying a node id retracts that whole
 *   node and its descendants, not just the single targeted event. The
 *   course-corrected view a consumer renders by default.
 * - Everything — nothing is suppressed. Voided events are surfaced as
 *   {@see VoidedEvent} wrappers that carry the void type and reason — including
 *   each member of an abandoned subtree, wrapped under the abandoning edge's
 *   reason — for an audit/debug view of the full history including retracted work.
 */
enum ViewSupersession
{
    case Clean;
    case Everything;
}
