<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Jobs\Concerns\ConfiguresDurableAdvanceJob;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AdvanceDurableSwarm implements ShouldQueue
{
    use ConfiguresDurableAdvanceJob;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
