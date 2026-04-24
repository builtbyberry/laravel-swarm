<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Pulse\Livewire;

use BuiltByBerry\LaravelSwarm\Pulse\Support\SwarmPulseKey;
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
        $counts = $this->aggregate('swarm_run', 'count', 'count', limit: 500)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->map(function (object $row): object {
                $parts = SwarmPulseKey::parseRunStatus($row->key);

                return (object) [
                    'swarmClass' => $parts['swarmClass'],
                    'topology' => $parts['topology'],
                    'status' => $parts['status'],
                    'count' => (int) $row->count,
                ];
            })
            ->groupBy('swarmClass');

        $durations = $this->aggregate('swarm_run_duration', ['avg', 'count'], 'avg', limit: 500)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->map(function (object $row): object {
                $parts = SwarmPulseKey::parseRunDuration($row->key);

                return (object) [
                    'swarmClass' => $parts['swarmClass'],
                    'topology' => $parts['topology'],
                    'avg' => (float) $row->avg,
                    'count' => (int) $row->count,
                ];
            })
            ->groupBy('swarmClass');

        return $counts
            ->map(function (Collection $swarmCounts, string $swarmClass) use ($durations): object {
                $totalRuns = $swarmCounts->sum('count');
                $failures = $swarmCounts->where('status', 'failed')->sum('count');
                $durationRows = $durations->get($swarmClass, collect());
                $durationSamples = (int) $durationRows->sum('count');
                $durationTotal = $durationRows->sum(fn (object $row): float => $row->avg * $row->count);

                return (object) [
                    'swarmClass' => $swarmClass,
                    'totalRuns' => $totalRuns,
                    'failures' => $failures,
                    'failureRate' => $totalRuns === 0 ? 0.0 : round(($failures / $totalRuns) * 100, 1),
                    'averageRunDurationMs' => $durationSamples === 0 ? 0 : (int) round($durationTotal / $durationSamples),
                    'topologyMix' => $swarmCounts
                        ->groupBy('topology')
                        ->map(fn (Collection $topologyCounts, string $topology): object => (object) [
                            'topology' => $topology,
                            'count' => (int) $topologyCounts->sum('count'),
                        ])
                        ->sortByDesc('count')
                        ->values(),
                ];
            })
            ->sortByDesc('totalRuns')
            ->values();
    }
}
