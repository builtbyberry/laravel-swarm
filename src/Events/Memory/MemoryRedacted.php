<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Events\Memory;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;

/**
 * Fired when the {@see MemoryCapturePolicy} redacts a memory entry at write.
 *
 * Dispatched by {@see RedactingMemoryStore} when a policy returns
 * {@see CaptureDecision::Redact}, immediately
 * after the (already-redacted) entry is persisted. The companion
 * {@see MemoryWritten} event still fires from the underlying store with the
 * redacted byte size; this event is the explicit signal that the value was
 * redacted by policy, so audit listeners can answer "prove redaction happened"
 * without inspecting the persisted value.
 *
 * Carries only the entry address (`scope`, `scopeId`, `key`) — never the value,
 * redacted or otherwise — to preserve the capture policy's no-payload invariant.
 * A {@see CaptureDecision::Full} write fires no
 * such event, so the default no-op policy's event stream is unchanged.
 */
final readonly class MemoryRedacted
{
    public function __construct(
        public MemoryScope $scope,
        public string $scopeId,
        public string $key,
    ) {}
}
