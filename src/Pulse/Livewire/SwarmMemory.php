<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Livewire;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Pulse\Recorders\SwarmMemoryMetrics;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse card surfacing memory growth + snapshot size metrics recorded by
 * {@see SwarmMemoryMetrics}.
 *
 * Three blocks render side by side:
 *
 * - **Per-scope writes** — entry-count, total bytes, average bytes per write,
 *   and recall hit rate, grouped by {@see MemoryScope}.
 * - **Snapshot footprint** — average bytes per persisted snapshot row and the
 *   average entry count, plus the total snapshot count for the period.
 * - **Hint band** — operator guidance pointing at the tuning knobs that
 *   actually move these numbers (`swarm:memory:purge`, capture allowlists,
 *   propagation policy).
 *
 * @internal
 */
#[Lazy]
class SwarmMemory extends Card
{
    public function render(): Renderable
    {
        [$snapshot, $time, $runAt] = $this->remember(fn (): array => $this->resolveSnapshot());

        return View::make('swarm::pulse.livewire.memory', [
            'snapshot' => $snapshot,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * @return array{scopes: Collection<int, mixed>, snapshots: object}
     */
    protected function resolveSnapshot(): array
    {
        $rows = $this->aggregateTypes([
            'swarm_memory_entries',
            'swarm_memory_bytes_total',
            'swarm_memory_bytes_samples',
            'swarm_memory_read_total',
            'swarm_memory_read_hits',
        ], 'sum', 'swarm_memory_entries', limit: 16)->all();

        $scopes = [];

        foreach ($rows as $row) {
            if (! is_object($row) || ! is_string($row->key ?? null)) {
                continue;
            }

            $bytesTotal = (int) ($row->swarm_memory_bytes_total ?? 0);
            $bytesSamples = (int) ($row->swarm_memory_bytes_samples ?? 0);
            $readTotal = (int) ($row->swarm_memory_read_total ?? 0);
            $readHits = (int) ($row->swarm_memory_read_hits ?? 0);

            $scopes[] = (object) [
                'scope' => $row->key,
                'scopeLabel' => $this->scopeLabel($row->key),
                'entries' => (int) ($row->swarm_memory_entries ?? 0),
                'totalBytes' => $bytesTotal,
                'averageBytes' => $bytesSamples === 0 ? 0 : (int) round($bytesTotal / $bytesSamples),
                'readTotal' => $readTotal,
                'hitRate' => $readTotal === 0 ? null : round(($readHits / $readTotal) * 100, 1),
            ];
        }

        $snapshotRowsRaw = $this->aggregateTypes([
            'swarm_memory_snapshot_count',
            'swarm_memory_snapshot_bytes',
            'swarm_memory_snapshot_entries',
        ], 'sum', 'swarm_memory_snapshot_count', limit: 1)->all();

        $snapshotRow = null;
        foreach ($snapshotRowsRaw as $candidate) {
            if (is_object($candidate) && is_string($candidate->key ?? null)) {
                $snapshotRow = $candidate;
                break;
            }
        }

        $snapshotCount = $snapshotRow !== null ? (int) ($snapshotRow->swarm_memory_snapshot_count ?? 0) : 0;
        $snapshotBytes = $snapshotRow !== null ? (int) ($snapshotRow->swarm_memory_snapshot_bytes ?? 0) : 0;
        $snapshotEntries = $snapshotRow !== null ? (int) ($snapshotRow->swarm_memory_snapshot_entries ?? 0) : 0;

        $snapshots = (object) [
            'count' => $snapshotCount,
            'averageBytes' => $snapshotCount === 0 ? 0 : (int) round($snapshotBytes / $snapshotCount),
            'averageEntries' => $snapshotCount === 0 ? 0 : (int) round($snapshotEntries / $snapshotCount),
        ];

        return [
            'scopes' => collect($scopes),
            'snapshots' => $snapshots,
        ];
    }

    /**
     * Look up the human label for a known {@see MemoryScope} value. Unknown
     * values pass through untouched so a future scope addition still renders
     * predictably even before the card knows about it.
     */
    protected function scopeLabel(string $scope): string
    {
        $known = MemoryScope::tryFrom($scope);

        return $known === null ? $scope : ucfirst($known->value);
    }
}
