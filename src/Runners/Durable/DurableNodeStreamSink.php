<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Closure;

/**
 * One durable node attempt's sink into the causal log.
 *
 * Every event is stamped with the attempt's node id and epoch. Without batching
 * each event is one INSERT, written as it is emitted. With batching the sink
 * holds text and reasoning deltas and writes them as one multi-row INSERT:
 *
 * - the attempt's first event, and every event that is not a delta, is written
 *   at once, together with the deltas held before it, so order is unchanged;
 * - held deltas are written once `$maxEvents` are held, and when a delta
 *   arrives while the oldest held delta is at least `$maxAgeMs` old (the
 *   arriving delta goes with them);
 * - no INSERT carries more than `$maxBytes` of encoded payload: when the next
 *   row would pass it, the held rows are written first, and a single row larger
 *   than `$maxBytes` is written on its own;
 * - {@see flush()} writes whatever is held. Callers flush once the attempt's
 *   step has succeeded and before anything seals or completes it; see
 *   {@see DurableNodeStreamRecorder::sinkFor()}.
 *
 * The age limit is checked only when an event arrives — there is no timer — so
 * while a provider is silent, held deltas wait for its next event or the flush.
 * Nothing is written for held deltas if the attempt throws before the flush.
 *
 * The buffer lives on this instance, one per attempt, and the destination table
 * is resolved once at construction.
 *
 * @internal
 */
final class DurableNodeStreamSink
{
    /** @var list<array<string, mixed>> */
    private array $held = [];

    private int $heldBytes = 0;

    private ?float $oldestHeldAt = null;

    private bool $emitted = false;

    /** The store batched rows go to; null when this sink writes per event. */
    private ?DatabaseCausalLogStore $batchLog;

    private string $table = '';

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  milliseconds on a monotonic clock
     */
    public function __construct(
        private CausalLogStore $causalLog,
        private string $runId,
        private string $nodeId,
        private int $epoch,
        private int $ttlSeconds,
        bool $batching = false,
        private int $maxEvents = 1,
        private int $maxBytes = PHP_INT_MAX,
        private int $maxAgeMs = 0,
        ?Closure $clock = null,
    ) {
        $this->batchLog = $batching && $causalLog instanceof DatabaseCausalLogStore ? $causalLog : null;
        $this->table = $this->batchLog?->tableName() ?? '';
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    public function __invoke(SwarmStreamEvent $event): void
    {
        $event->withNodeId($this->nodeId)->withAttemptEpoch($this->epoch);

        if ($this->batchLog === null) {
            $this->causalLog->record($this->runId, $event, $this->ttlSeconds);

            return;
        }

        $row = $this->batchLog->causalRow($this->runId, $event, $this->ttlSeconds);
        $bytes = strlen(is_string($row['payload'] ?? null) ? $row['payload'] : '');

        // Keep every INSERT within the byte budget: write what is held first if
        // this row would take it past.
        if ($this->held !== [] && $this->heldBytes + $bytes > $this->maxBytes) {
            $this->flush();
        }

        if (! $this->emitted || ! self::isDelta($event) || $bytes > $this->maxBytes) {
            // The first event shows a reader the node has started; a structural
            // event (tool call, node close, …) is what a reader waits on; an
            // oversized row goes alone.
            $this->emitted = true;
            $this->write([...$this->takeHeld(), $row]);

            return;
        }

        $now = ($this->clock)();
        $expired = $this->oldestHeldAt !== null && $now - $this->oldestHeldAt >= $this->maxAgeMs;

        $this->held[] = $row;
        $this->heldBytes += $bytes;
        $this->oldestHeldAt ??= $now;

        if ($expired || count($this->held) >= $this->maxEvents) {
            $this->flush();
        }
    }

    /**
     * Write every held delta as one INSERT. A no-op when nothing is held.
     */
    public function flush(): void
    {
        $rows = $this->takeHeld();

        if ($rows !== []) {
            $this->write($rows);
        }
    }

    /**
     * Empty the buffer before writing, so a failed INSERT is never retried by a
     * later flush.
     *
     * @return list<array<string, mixed>>
     */
    private function takeHeld(): array
    {
        $rows = $this->held;
        $this->held = [];
        $this->heldBytes = 0;
        $this->oldestHeldAt = null;

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(array $rows): void
    {
        $this->batchLog?->recordRows($this->table, $rows);
    }

    private static function isDelta(SwarmStreamEvent $event): bool
    {
        return $event instanceof SwarmTextDelta || $event instanceof SwarmReasoningDelta;
    }
}
