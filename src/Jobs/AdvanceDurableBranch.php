<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\ConfiguresDurableAdvanceJob;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\EmitsSwarmJobTelemetry;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AdvanceDurableBranch implements ShouldQueue
{
    use ConfiguresDurableAdvanceJob;
    use EmitsSwarmJobTelemetry;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
        public string $branchId,
        ?int $enqueuedAtMs = null,
    ) {
        $this->enqueuedAtMs = $enqueuedAtMs ?? self::telemetryEpochMilliseconds();
    }

    public function handle(DurableSwarmManager $manager): void
    {
        $this->withSwarmJobTelemetry(function () use ($manager): void {
            $manager->advanceBranch($this->runId, $this->branchId);
        });
    }

    public function displayName(): string
    {
        return $this->runId.':branch:'.$this->branchId;
    }

    protected function telemetryRunId(): ?string
    {
        return $this->runId;
    }

    protected function telemetrySwarmClass(): ?string
    {
        $row = Container::getInstance()->make(DurableRunStore::class)->find($this->runId);

        return is_array($row) ? ($row['swarm_class'] ?? null) : null;
    }
}
