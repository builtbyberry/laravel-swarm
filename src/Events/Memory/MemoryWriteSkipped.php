<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Fired when the {@see MemoryCapturePolicy} drops a memory write entirely.
 *
 * Dispatched at the write boundary when a policy returns
 * {@see CaptureDecision::Skip}. Because a skipped write is neither persisted
 * nor buffered, no {@see MemoryWritten} event fires for it. This event is the
 * positive signal that a write was intentionally dropped, so audit listeners
 * do not need to infer the decision from the absence of a write.
 *
 * Carries only the entry address (`scope`, `scopeId`, `key`) — never the value
 * that was dropped — preserving the capture policy's no-payload invariant. Skip
 * leaves any pre-existing entry at the address untouched; it suppresses this
 * write, it does not delete prior state.
 */
final class MemoryWriteSkipped
{
    public function __construct(
        public readonly MemoryScope $scope,
        public readonly string $scopeId,
        public readonly string $key,
    ) {}
}
