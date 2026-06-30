<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Exceptions;

/**
 * Thrown when a void-edge targets an event that has already been sealed (#282).
 *
 * Supersession is bounded to the unsealed window: once the #287 compactor seals
 * an event (`sealed_at` set), its history is no longer retractable. Failing loud
 * here — rather than silently appending a void-edge against sealed history — is
 * the append-only invariant the substrate is built on.
 */
class SealedCausalWindowException extends SwarmException {}
