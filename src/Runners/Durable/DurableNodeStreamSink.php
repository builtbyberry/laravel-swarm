<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\CausalLogStore;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseCausalLogStore;
use BuiltByBerry\LaravelSwarm\Runners\DurableRunRecorder;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmReasoningDelta;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmStreamEvent;
use BuiltByBerry\LaravelSwarm\Streaming\Events\SwarmTextDelta;
use Closure;

/**
 * One durable node attempt's sink into the causal log.
 *
 * Every event is stamped with the attempt's node id and epoch. Without batching
 * each event is one INSERT, written as it is emitted. With batching
 * (`swarm.durable.stream_batching`) the sink holds text and reasoning deltas
 * and writes them as one multi-row INSERT:
 *
 * - the attempt's first event, and every event that is not a delta, is written
 *   at once, together with any deltas held before it, so order is unchanged;
 * - held deltas are written once `$maxEvents` are held, before a delta whose
 *   encoded payload would take the held total past `$maxBytes`, and when a
 *   delta arrives while the oldest held delta is at least `$maxAgeMs` old (the
 *   arriving delta is written with them). A single row larger than `$maxBytes`
 *   is written on its own;
 * - {@see flush()} writes whatever is held. The advancer calls it when the
 *   attempt's stream returns, before the step is checkpointed and sealed
 *   ({@see DurableRunRecorder}).
 *
 * The age limit is checked only when an event arrives — there is no timer — so
 * while a provider is silent, held deltas wait for its next event or for the
 * flush. If the attempt throws, held deltas are dropped, exactly as a crashed
 * process drops them; what was already written stays retractable on resume.
 *
 * The buffer lives on this instance, one per attempt, and the destination table
 * is resolved once at construction. Nothing is shared between attempts or runs.
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
        private bool $batching = false,
        private int $maxEvents = 1,
        private int $maxBytes = PHP_INT_MAX,
        private int $maxAgeMs = 0,
        private string $table = '',
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000;
    }

    public function __invoke(SwarmStreamEvent $event): void
    {
        $event->withNodeId($this->nodeId)->withAttemptEpoch($this->epoch);

        if (! $this->batching || ! $this->causalLog instanceof DatabaseCausalLogStore) {
            $this->causalLog->record($this->runId, $event, $this->ttlSeconds);

            return;
        }

        $row = $this->causalLog->causalRow($this->runId, $event, $this->ttlSeconds);

        if (! $this->emitted || ! self::isDelta($event)) {
            // The first event shows a reader the node has started; a structural
            // event (tool call, node close, …) is what a reader waits on.
            $this->emitted = true;
            $this->write([...$this->takeHeld(), $row]);

            return;
        }

        $bytes = strlen(is_string($row['payload'] ?? null) ? $row['payload'] : '');
        $now = ($this->clock)();

        if ($this->held !== [] && $this->heldBytes + $bytes > $this->maxBytes) {
            $this->flush();
        }

        if ($bytes > $this->maxBytes) {
            $this->write([$row]);

            return;
        }

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
        /** @var DatabaseCausalLogStore $log */
        $log = $this->causalLog;
        $log->recordRows($this->table, $rows);
    }

    private static function isDelta(SwarmStreamEvent $event): bool
    {
        return $event instanceof SwarmTextDelta || $event instanceof SwarmReasoningDelta;
    }
}
