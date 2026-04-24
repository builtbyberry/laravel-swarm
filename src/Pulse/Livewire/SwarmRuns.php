<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Livewire;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class SwarmRuns extends Card
{
    public function render(): Renderable
    {
        [$runs, $time, $runAt] = $this->remember(fn () => $this->resolveRuns());

        return View::make('swarm::pulse.livewire.runs', [
            'runs' => $runs,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * @return Collection<int, mixed>
     */
    protected function resolveRuns(): Collection
    {
        $rows = $this->aggregateTypes([
            'swarm_run_total',
            'swarm_run_failed',
            'swarm_topology_sequential',
            'swarm_topology_parallel',
            'swarm_topology_hierarchical',
            'swarm_run_duration_total_ms',
            'swarm_run_duration_samples',
        ], 'sum', 'swarm_run_total', limit: 100)->all();

        $counts = [];

        foreach ($rows as $row) {
            if (! is_object($row) || ! is_string($row->key ?? null)) {
                continue;
            }

            $durationTotalMs = (int) ($row->swarm_run_duration_total_ms ?? 0);
            $durationSamples = (int) ($row->swarm_run_duration_samples ?? 0);
            $totalRuns = (int) ($row->swarm_run_total ?? 0);
            $failures = (int) ($row->swarm_run_failed ?? 0);
            $topologyMix = array_filter([
                (object) ['topology' => 'sequential', 'count' => (int) ($row->swarm_topology_sequential ?? 0)],
                (object) ['topology' => 'parallel', 'count' => (int) ($row->swarm_topology_parallel ?? 0)],
                (object) ['topology' => 'hierarchical', 'count' => (int) ($row->swarm_topology_hierarchical ?? 0)],
            ], fn (object $topology): bool => $topology->count > 0);

            usort($topologyMix, fn (object $left, object $right): int => $right->count <=> $left->count);

            $counts[] = (object) [
                'swarmClass' => $row->key,
                'totalRuns' => $totalRuns,
                'failures' => $failures,
                'failureRate' => $totalRuns === 0 ? 0.0 : round(($failures / $totalRuns) * 100, 1),
                'averageRunDurationMs' => $durationSamples === 0 ? 0 : (int) round($durationTotalMs / $durationSamples),
                'topologyMix' => collect($topologyMix),
            ];
        }

        usort($counts, fn (object $left, object $right): int => $right->totalRuns <=> $left->totalRuns);

        return collect($counts);
    }
}
