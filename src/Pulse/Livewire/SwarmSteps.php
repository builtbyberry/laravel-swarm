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
class SwarmSteps extends Card
{
    public function render(): Renderable
    {
        [$steps, $time, $runAt] = $this->remember(fn () => $this->resolveSteps());

        return View::make('swarm::pulse.livewire.steps', [
            'steps' => $steps,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * @return Collection<int, object{
     *     swarmClass: string,
     *     topology: string,
     *     agentClass: string,
     *     averageDurationMs: int,
     *     count: int
     * }>
     */
    protected function resolveSteps(): Collection
    {
        return $this->aggregate('swarm_step_duration', ['avg', 'count'], 'avg', limit: 25)
            ->filter(fn (object $row): bool => is_string($row->key ?? null))
            ->map(function (object $row): object {
                $parts = SwarmPulseKey::parseStepDuration($row->key);

                return (object) [
                    'swarmClass' => $parts['swarmClass'],
                    'topology' => $parts['topology'],
                    'agentClass' => $parts['agentClass'],
                    'averageDurationMs' => (int) round((float) $row->avg),
                    'count' => (int) $row->count,
                ];
            })
            ->values();
    }
}
