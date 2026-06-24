<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming\View;

use BuiltByBerry\LaravelSwarm\Streaming\Events\CausalVoidEdgeType;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * A voided event surfaced in the {@see ViewSupersession::Everything} read (#283).
 *
 * In the clean view a voided event is suppressed; in the everything view it is
 * still emitted, but wrapped so the consumer can see *that* it was voided and
 * *why*. The wrapper carries the underlying event untouched alongside the type
 * and reason taken from the void-edge that voided it. Where several void-edges
 * target one event, the wrapper reflects the last (most recent) one in causal
 * order, matching how the clean view would have resolved its fate.
 */
final class VoidedEvent
{
    public function __construct(
        public readonly SwarmStreamEvent $event,
        public readonly CausalVoidEdgeType $voidType,
        public readonly string $reason,
    ) {}
}
