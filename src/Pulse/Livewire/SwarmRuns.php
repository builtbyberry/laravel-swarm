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
     * @return Collection<int, object{
     *     swarmClass: string,
     *     totalRuns: int,
     *     failures: int,
     *     failureRate: float,
     *     averageRunDurationMs: int,
     *     topologyMix: Collection<int, object{topology: string, count: int}>
     * }>
     */
    protected function resolveRuns(): Collection
    {
        $counts = $this->aggregateTypes([
            'swarm_run_total',
            'swarm_run_failed',
            'swarm_topology_sequential',
            'swarm_topology_parallel',
            'swarm_topology_hierarchical',
            'swarm_run_duration_total_ms',
            'swarm_run_duration_samples',
        ], 'sum', 'swarm_run_total', limit: 100)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->map(function (object $row): object {
                $durationTotalMs = (int) ($row->swarm_run_duration_total_ms ?? 0);
                $durationSamples = (int) ($row->swarm_run_duration_samples ?? 0);

                return (object) [
                    'swarmClass' => $row->key,
                    'totalRuns' => (int) ($row->swarm_run_total ?? 0),
                    'failures' => (int) ($row->swarm_run_failed ?? 0),
                    'averageRunDurationMs' => $durationSamples === 0 ? 0 : (int) round($durationTotalMs / $durationSamples),
                    'topologyMix' => collect([
                        (object) ['topology' => 'sequential', 'count' => (int) ($row->swarm_topology_sequential ?? 0)],
                        (object) ['topology' => 'parallel', 'count' => (int) ($row->swarm_topology_parallel ?? 0)],
                        (object) ['topology' => 'hierarchical', 'count' => (int) ($row->swarm_topology_hierarchical ?? 0)],
                    ])->filter(fn (object $topology): bool => $topology->count > 0)->values(),
                ];
            });

        return $counts
            ->map(function (object $swarm): object {
                return (object) [
                    'swarmClass' => $swarm->swarmClass,
                    'totalRuns' => $swarm->totalRuns,
                    'failures' => $swarm->failures,
                    'failureRate' => $swarm->totalRuns === 0 ? 0.0 : round(($swarm->failures / $swarm->totalRuns) * 100, 1),
                    'averageRunDurationMs' => $swarm->averageRunDurationMs,
                    'topologyMix' => $swarm->topologyMix->sortByDesc('count')->values(),
                ];
            })
            ->sortByDesc('totalRuns')
            ->values();
    }
}
