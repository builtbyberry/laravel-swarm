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
        ], 'count', 'swarm_run_total', limit: 100)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->map(function (object $row): object {
                return (object) [
                    'swarmClass' => $row->key,
                    'totalRuns' => (int) ($row->swarm_run_total ?? 0),
                    'failures' => (int) ($row->swarm_run_failed ?? 0),
                    'topologyMix' => collect([
                        (object) ['topology' => 'sequential', 'count' => (int) ($row->swarm_topology_sequential ?? 0)],
                        (object) ['topology' => 'parallel', 'count' => (int) ($row->swarm_topology_parallel ?? 0)],
                        (object) ['topology' => 'hierarchical', 'count' => (int) ($row->swarm_topology_hierarchical ?? 0)],
                    ])->filter(fn (object $topology): bool => $topology->count > 0)->values(),
                ];
            });

        $durations = $this->aggregate('swarm_run_duration_total', ['avg', 'count'], 'count', limit: 100)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->keyBy('key');

        return $counts
            ->map(function (object $swarm) use ($durations): object {
                $duration = $durations->get($swarm->swarmClass);
                $durationSamples = (int) ($duration->count ?? 0);
                $averageRunDurationMs = $duration === null ? 0 : (int) round((float) $duration->avg);

                return (object) [
                    'swarmClass' => $swarm->swarmClass,
                    'totalRuns' => $swarm->totalRuns,
                    'failures' => $swarm->failures,
                    'failureRate' => $swarm->totalRuns === 0 ? 0.0 : round(($swarm->failures / $swarm->totalRuns) * 100, 1),
                    'averageRunDurationMs' => $durationSamples === 0 ? 0 : $averageRunDurationMs,
                    'topologyMix' => $swarm->topologyMix->sortByDesc('count')->values(),
                ];
            })
            ->sortByDesc('totalRuns')
            ->values();
    }
}
