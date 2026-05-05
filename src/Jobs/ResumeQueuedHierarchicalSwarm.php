<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\EmitsSwarmJobTelemetry;
use BuiltByBerry\LaravelSwarm\Runners\QueuedHierarchicalCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumeQueuedHierarchicalSwarm implements ShouldQueue
{
    use EmitsSwarmJobTelemetry;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
        ?int $enqueuedAtMs = null,
    ) {
        $this->enqueuedAtMs = $enqueuedAtMs ?? self::telemetryEpochMilliseconds();
    }

    public function handle(QueuedHierarchicalCoordinator $coordinator): void
    {
        $this->withSwarmJobTelemetry(function () use ($coordinator): void {
            $coordinator->resumeAfterParallelJoin($this->runId);
        });
    }

    public function displayName(): string
    {
        return $this->runId.':resume-queued-hierarchical';
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
