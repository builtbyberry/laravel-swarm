<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;

/**
 * Fired when the {@see MemoryCapturePolicy} drops a memory write entirely.
 *
 * Dispatched by {@see RedactingMemoryStore} when a policy returns
 * {@see CaptureDecision::Skip}. Because a
 * skipped write never reaches the underlying store, no {@see MemoryWritten}
 * event fires for it — this event is the only signal that a write was
 * intentionally dropped, so audit listeners can record the decision rather
 * than inferring it from the absence of a write.
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
