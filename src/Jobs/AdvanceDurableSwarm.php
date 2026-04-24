<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdvanceDurableSwarm implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $runId,
        public int $stepIndex,
    ) {}

    public function handle(DurableSwarmManager $manager): void
    {
        $manager->advance($this->runId, $this->stepIndex);
    }

    public function displayName(): string
    {
        return $this->runId.':'.$this->stepIndex;
    }
}
