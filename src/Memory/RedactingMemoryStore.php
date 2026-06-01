<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Memory;

use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRedacted;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWriteSkipped;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

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
 * On a `Full` decision the inner driver dispatches {@see MemoryWritten} as
 * usual and the decorator adds nothing — so the default no-op policy's event
 * stream is byte-identical to pre-v0.10. On a `Redact` decision the inner
 * driver still dispatches {@see MemoryWritten} (with the redacted byte size)
 * and the decorator additionally dispatches {@see MemoryRedacted} as the
 * explicit signal that the policy redacted the value. A {@see CaptureDecision::Skip}
 * decision drops the entry entirely — the inner driver is never called, so no
 * row is written and no {@see MemoryWritten} fires; the decorator dispatches
 * {@see MemoryWriteSkipped} so the dropped write is still observable.
 *
 * @internal
 */
final class RedactingMemoryStore implements MemoryStore
{
    public function __construct(
        protected MemoryStore $inner,
        protected MemoryCapturePolicy $policy,
        protected Dispatcher $events,
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
            CaptureDecision::Redact => $this->putRedacted($entry),
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
     * Persist a structurally-redacted copy of the entry, then signal the
     * redaction. The inner driver dispatches {@see MemoryWritten} (with the
     * redacted byte size); this method adds {@see MemoryRedacted} so listeners
     * can observe that the value was redacted by policy. Redaction covers the
     * entry value only — metadata is persisted unchanged.
     */
    protected function putRedacted(MemoryEntry $entry): MemoryEntry
    {
        $persisted = $this->inner->put($entry->withValue($this->redact($entry->value)));

        $this->events->dispatch(new MemoryRedacted(
            scope: $entry->scope,
            scopeId: $entry->scopeId,
            key: $entry->key,
        ));

        return $persisted;
    }

    /**
     * Structurally redact a value: every scalar (and null) becomes the
     * redaction sentinel, while array structure and keys are preserved so the
     * entry remains shaped like the original and stays addressable. Keys are
     * never redacted — only values — so do not place sensitive data in keys.
     */
    protected function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redact($item), $value);
        }

        return SwarmCapture::REDACTED;
    }

    /**
     * Honour a {@see CaptureDecision::Skip} decision: persist nothing and write
     * no row, so the inner driver dispatches no {@see MemoryWritten}. Dispatch
     * {@see MemoryWriteSkipped} instead so the dropped write stays observable.
     * Skip suppresses this write only — any pre-existing entry at the address is
     * left untouched. The entry is returned with prospective timestamps to
     * satisfy the {@see MemoryStore::put()} contract, but it was never written.
     */
    protected function skip(MemoryEntry $entry): MemoryEntry
    {
        $this->events->dispatch(new MemoryWriteSkipped(
            scope: $entry->scope,
            scopeId: $entry->scopeId,
            key: $entry->key,
        ));

        $now = CarbonImmutable::now('UTC');

        return $entry->withTimestamps($entry->createdAt ?? $now, $now);
    }
}
