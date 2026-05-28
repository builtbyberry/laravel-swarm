<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Recorders;

use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryRead;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemorySnapshotted;
use BuiltByBerry\LaravelSwarm\Events\Memory\MemoryWritten;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Laravel\Pulse\Pulse;

/**
 * Pulse recorder for swarm memory growth + snapshot size metrics.
 *
 * Tracks four signals operators tune retention and capture policy against:
 *
 * - **Entries written per scope** (`swarm_memory_entries`)
 * - **Approximate JSON byte size of writes per scope** (`swarm_memory_bytes_total`,
 *   `swarm_memory_bytes_samples`)
 * - **Approximate JSON byte size + entry count of persisted snapshots**
 *   (`swarm_memory_snapshot_bytes`, `swarm_memory_snapshot_entries`,
 *   `swarm_memory_snapshot_count`)
 * - **Recall hit/miss totals** (`swarm_memory_read_total`,
 *   `swarm_memory_read_hits`)
 *
 * Sampling is config-driven via `swarm.pulse.memory.sample_rate` (0.0–1.0).
 * The default is 1.0 (record every event); high-volume deployments should
 * dial down to keep Pulse ingest costs predictable. The sample rate applies
 * uniformly to writes, reads, and snapshots so the resulting averages remain
 * statistically meaningful — they are the average of a uniform random sample.
 *
 * Registered by class string in `config/pulse.php` via `swarm:install:pulse`
 * — part of the public package surface per `docs/pulse.md`.
 */
class SwarmMemoryMetrics
{
    /**
     * @var list<class-string>
     */
    public array $listen = [
        MemoryWritten::class,
        MemoryRead::class,
        MemorySnapshotted::class,
    ];

    public function __construct(
        protected Pulse $pulse,
        protected ConfigRepository $config,
    ) {}

    public function record(MemoryWritten|MemoryRead|MemorySnapshotted $event): void
    {
        if (! $this->shouldSample()) {
            return;
        }

        $timestamp = CarbonImmutable::now()->getTimestamp();

        $this->pulse->lazy(function () use ($event, $timestamp): void {
            match (true) {
                $event instanceof MemoryWritten => $this->recordWrite($event, $timestamp),
                $event instanceof MemoryRead => $this->recordRead($event, $timestamp),
                $event instanceof MemorySnapshotted => $this->recordSnapshot($event, $timestamp),
            };
        });
    }

    protected function recordWrite(MemoryWritten $event, int $timestamp): void
    {
        $scope = $event->scope->value;

        $this->pulse->record(
            type: 'swarm_memory_entries',
            key: $scope,
            value: 1,
            timestamp: $timestamp,
        )->sum();

        if ($event->bytes !== null) {
            $this->pulse->record(
                type: 'swarm_memory_bytes_total',
                key: $scope,
                value: $event->bytes,
                timestamp: $timestamp,
            )->sum();

            $this->pulse->record(
                type: 'swarm_memory_bytes_samples',
                key: $scope,
                value: 1,
                timestamp: $timestamp,
            )->sum();
        }
    }

    protected function recordRead(MemoryRead $event, int $timestamp): void
    {
        $scope = $event->scope->value;

        $this->pulse->record(
            type: 'swarm_memory_read_total',
            key: $scope,
            value: 1,
            timestamp: $timestamp,
        )->sum();

        if ($event->hit) {
            $this->pulse->record(
                type: 'swarm_memory_read_hits',
                key: $scope,
                value: 1,
                timestamp: $timestamp,
            )->sum();
        }
    }

    protected function recordSnapshot(MemorySnapshotted $event, int $timestamp): void
    {
        // Snapshots are not scope-keyed — they are per-run, per-step. Aggregate
        // them under a single virtual key so the card can read totals without
        // exploding into one row per run id. Operators who need per-run
        // detail go to the snapshots table directly via swarm:inspect.
        $key = 'snapshots';

        $this->pulse->record(
            type: 'swarm_memory_snapshot_count',
            key: $key,
            value: 1,
            timestamp: $timestamp,
        )->sum();

        if ($event->bytes !== null) {
            $this->pulse->record(
                type: 'swarm_memory_snapshot_bytes',
                key: $key,
                value: $event->bytes,
                timestamp: $timestamp,
            )->sum();
        }

        if ($event->entryCount !== null) {
            $this->pulse->record(
                type: 'swarm_memory_snapshot_entries',
                key: $key,
                value: $event->entryCount,
                timestamp: $timestamp,
            )->sum();
        }
    }

    /**
     * Cheap, dependency-free Bernoulli sampler — `mt_rand()` is fine here
     * because the gate is statistical, not cryptographic, and the alternative
     * (random_int) costs an extra syscall per memory event.
     */
    protected function shouldSample(): bool
    {
        $rate = (float) $this->config->get('swarm.pulse.memory.sample_rate', 1.0);

        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }
}
