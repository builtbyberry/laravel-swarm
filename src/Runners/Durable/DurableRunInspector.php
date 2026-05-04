<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Runners\Durable;

use BuiltByBerry\LaravelSwarm\Contracts\DurableRunStore;
use BuiltByBerry\LaravelSwarm\Events\SwarmProgressRecorded;
use BuiltByBerry\LaravelSwarm\Exceptions\SwarmException;
use BuiltByBerry\LaravelSwarm\Persistence\DatabaseRunHistoryStore;
use BuiltByBerry\LaravelSwarm\Responses\DurableRunDetail;
use Illuminate\Contracts\Events\Dispatcher;

class DurableRunInspector
{
    public function __construct(
        protected DurableRunStore $durableRuns,
        protected DatabaseRunHistoryStore $historyStore,
        protected Dispatcher $events,
        protected DurablePayloadCapture $payloads,
        protected DurableRunContext $runs,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array
    {
        return $this->durableRuns->find($runId);
    }

    public function inspect(string $runId): DurableRunDetail
    {
        $run = $this->durableRuns->find($runId);

        if ($run === null) {
            throw new SwarmException("Durable run [{$runId}] was not found.");
        }

        return new DurableRunDetail(
            runId: $runId,
            run: $run,
            history: $this->historyStore->find($runId),
            labels: $this->durableRuns->labels($runId),
            details: $this->durableRuns->details($runId),
            waits: $this->durableRuns->waits($runId),
            signals: $this->durableRuns->signals($runId),
            progress: $this->durableRuns->progress($runId),
            children: $this->durableRuns->childRuns($runId),
            branches: $this->durableRuns->branchesFor($runId),
        );
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     * @return array<int, DurableRunDetail>
     */
    public function inspectByLabels(array $labels, int $limit = 50): array
    {
        return array_map(
            fn (string $runId): DurableRunDetail => $this->inspect($runId),
            $this->durableRuns->runIdsForLabels($labels, $limit),
        );
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $labels
     */
    public function updateLabels(string $runId, array $labels): void
    {
        $this->runs->requireRun($runId);
        $this->durableRuns->updateLabels($runId, $labels);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function updateDetails(string $runId, array $details): void
    {
        $this->runs->requireRun($runId);
        $this->durableRuns->updateDetails($runId, $this->payloads->payload($details));
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function recordProgress(string $runId, ?string $branchId = null, array $progress = []): void
    {
        $this->runs->requireRun($runId);
        $progress = $this->payloads->payload($progress);
        $this->durableRuns->recordProgress($runId, $branchId, $progress);

        $this->events->dispatch(new SwarmProgressRecorded(
            runId: $runId,
            branchId: $branchId,
            progress: $progress,
        ));
    }
}
