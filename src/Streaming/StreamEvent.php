<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Streaming;

use Laravel\Ai\Streaming\Events\StreamEvent as LaravelAiStreamEvent;

/**
 * Swarm-owned base class for streaming events.
 *
 * This class extends `Laravel\Ai\Streaming\Events\StreamEvent` so the existing
 * invocation-id tracking, `type()` method, and `toArray()` contract continue
 * to work unchanged. It exists to give every `SwarmStream*` event a swarm-owned
 * ancestor, so consumers may type-hint against this seam instead of the vendor
 * class. The inheritance keeps full runtime compatibility with the vendor
 * streaming machinery while shifting the public type boundary into Swarm's
 * namespace.
 */
abstract class StreamEvent extends LaravelAiStreamEvent {}
