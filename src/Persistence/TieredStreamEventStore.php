<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Persistence;

use BuiltByBerry\LaravelSwarm\Contracts\ColdArchiveDriver;
use BuiltByBerry\LaravelSwarm\Contracts\StreamEventStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmCausalSealBarrier;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;

/**
 * Transparent hot/cold tiered decorator over a StreamEventStore (#286).
 *
 * `record()` delegates to the hot store. `forget()` propagates to both hot
 * and cold so deletion is complete across tiers. The compactor (#287) is the
 * only path that writes to cold. `events()` stitches cold and hot
 * together at the base pointer: cold owns [0, base), hot owns [base, ∞).
 *
 * Stateless singleton-safe: no mutable per-run instance fields. Every call reads
 * fresh from the underlying stores.
 *
 * @internal
 */
class TieredStreamEventStore implements StreamEventStore
{
    public function __construct(
        public readonly DatabaseStreamEventStore $hot,
        protected ColdArchiveDriver $cold,
    ) {}

    public function record(string $runId, SwarmStreamEvent $event, int $ttlSeconds): void
    {
        $this->hot->record($runId, $event, $ttlSeconds);
    }

    public function forget(string $runId): void
    {
        $this->hot->forget($runId);
        $this->cold->forget($runId);
    }

    public function assertReady(): void
    {
        $this->hot->assertReady();
        $this->cold->assertReady();
    }

    public function events(string $runId): iterable
    {
        // F2: read base pointer ONCE so the entire seam resolution uses a
        // consistent snapshot boundary — no torn read across a concurrent swap.
        $base = $this->cold->basePointer($runId);

        if ($base === 0) {
            // No cold data yet — hot is the complete event set.
            yield from $this->hot->events($runId);

            return;
        }

        // F1 half-open seam: cold owns id < base, hot owns id >= base.
        // Together they cover the full set with no gap and no duplicate.
        // Explicit foreach avoids PHP yield-from key passthrough: both sub-generators
        // yield integer keys 0,1,… — yield-from passes those through and a second
        // generator overwrites the first in iterator_to_array($gen, preserve_keys=true).
        foreach ($this->cold->readEvents($runId, $base) as $event) {
            yield $event;
        }
        foreach ($this->hot->eventsFrom($runId, $base) as $event) {
            if (! ($event instanceof SwarmCausalSealBarrier)) {
                yield $event;
            }
        }
    }
}
