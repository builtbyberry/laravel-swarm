<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Jobs;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\ConfiguresDurableAdvanceJob;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\EmitsSwarmJobTelemetry;
use BuiltByBerry\LaravelSwarm\Jobs\Concerns\FailsUnsupportedNativeOutcome;
use BuiltByBerry\LaravelSwarm\Runners\DurableSwarmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @internal
 */
class AdvanceDurableSwarm implements ShouldQueue
{
    use ConfiguresDurableAdvanceJob;
    use EmitsSwarmJobTelemetry;
    use FailsUnsupportedNativeOutcome;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The outbox entry this job was pushed for directly on commit, deleted once
     * the job acquires its lease. Null for relay and recovery pushes, which own
     * no entry. A declared (not promoted) property so a payload queued without
     * it restores to null: SerializesModels restores declared properties only
     * and never runs the constructor.
     */
    public ?int $outboxEntryId = null;

    public function __construct(
        public string $runId,
        public int $stepIndex,
        ?int $enqueuedAtMs = null,
        ?int $outboxEntryId = null,
    ) {
        $this->enqueuedAtMs = $enqueuedAtMs ?? self::telemetryEpochMilliseconds();
        $this->outboxEntryId = $outboxEntryId;
        $this->applyDurableAdvanceJobTimeout();
    }

    public function handle(DurableSwarmManager $manager): void
    {
        $this->withoutNativeOutcomeRetry(fn () => $this->withSwarmJobTelemetry(function () use ($manager): void {
            $manager->advance($this->runId, $this->stepIndex, $this->outboxEntryId);
        }));
    }

    public function displayName(): string
    {
        return $this->runId.':'.$this->stepIndex;
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
