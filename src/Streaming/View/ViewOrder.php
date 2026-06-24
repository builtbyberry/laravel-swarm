<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\View;

/**
 * The order axis of a causal-log read (#283).
 *
 * The log is stored in causal order (DB-id append order). A read may keep that
 * order or re-shape sibling nodes into the order their parent declared. Both are
 * pure folds over the same log — neither rewrites it.
 *
 * - Causal — events exactly as appended (arrival order).
 * - Presentation — sibling `swarm_node_opened`/`swarm_node_closed` events are
 *   reordered to match the `child_node_ids` a parent declared, independent of the
 *   order they happened to arrive in. Nodes whose parent never declared a child
 *   list keep their causal position (a stable sort).
 */
enum ViewOrder
{
    case Causal;
    case Presentation;
}
