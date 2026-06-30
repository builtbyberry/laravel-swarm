<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Thrown when a void-edge targets an `event_uuid` with no row in the run's log (#282).
 *
 * Void-edging a target that does not exist cannot fold to a consistent state, so
 * it fails loud rather than appending a dangling edge. This is the design spine:
 * every read folds the true state or fails loud — no silent corruption.
 */
class UnknownCausalTargetException extends SwarmException {}
