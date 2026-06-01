<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Carbon\CarbonImmutable;

/**
 * {@see MemoryStore} decorator that applies the bound {@see MemoryCapturePolicy}
 * to every write before delegating to the underlying driver.
 *
 * This is the single write-time chokepoint for memory redaction. Because every
 * memory write — whether through the {@see SwarmMemory}
 * facade, {@see RunContext}, or a direct
 * store resolution — flows through the bound `MemoryStore`, wrapping it here
 * guarantees no write can bypass the policy. Reads (`get`/`all`) return the
 * already-redacted persisted values, so the propagation view and the frozen
 * {@see MemorySnapshot} inherit redaction structurally — no separate
 * pre-snapshot pass is needed.
 *
 * The decorator never dispatches lifecycle events itself: it hands the
 * (possibly redacted) entry to the inner driver, which dispatches
 * {@see MemoryWritten} as usual, so the
 * event's `bytes` reflect the persisted (redacted) size. A {@see CaptureDecision::Skip}
 * decision drops the entry entirely — the inner driver is never called, so no
 * row is written and no event is dispatched.
 *
 * @internal
 */
final class RedactingMemoryStore implements MemoryStore
{
    public function __construct(
        protected MemoryStore $inner,
        protected MemoryCapturePolicy $policy,
    ) {}

    /**
     * The wrapped persistence driver.
     *
     * Exposed for introspection — e.g. confirming which concrete driver the
     * container selected for the active persistence mode. Not part of the
     * {@see MemoryStore} contract.
     */
    public function inner(): MemoryStore
    {
        return $this->inner;
    }

    public function put(MemoryEntry $entry): MemoryEntry
    {
        return match ($this->policy->memory($entry->scope, $entry->key)) {
            CaptureDecision::Full => $this->inner->put($entry),
            CaptureDecision::Redact => $this->inner->put($entry->withValue($this->redact($entry->value))),
            CaptureDecision::Skip => $this->skip($entry),
        };
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        return $this->inner->get($scope, $scopeId, $key);
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        return $this->inner->forget($scope, $scopeId, $key);
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return $this->inner->all($scope, $scopeId);
    }

    /**
     * Structurally redact a value: every scalar (and null) becomes the
     * redaction sentinel, while array structure and keys are preserved so the
     * entry remains shaped like the original and stays addressable.
     */
    protected function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redact($item), $value);
        }

        return SwarmCapture::REDACTED;
    }

    /**
     * Honour a {@see CaptureDecision::Skip} decision: persist nothing and
     * dispatch no event. The entry is returned with prospective timestamps to
     * satisfy the {@see MemoryStore::put()} contract, but it was never written —
     * a subsequent `get()` for the same address returns null.
     */
    protected function skip(MemoryEntry $entry): MemoryEntry
    {
        $now = CarbonImmutable::now('UTC');

        return $entry->withTimestamps($entry->createdAt ?? $now, $now);
    }
}
